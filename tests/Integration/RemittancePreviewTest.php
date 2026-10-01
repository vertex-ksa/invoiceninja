<?php

namespace Tests\Integration;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Receivables\NativeReceiptAllocationPreview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MockAccountData;
use Tests\TestCase;

/** Run only in a separately reviewed disposable MySQL test database, never the receivables fixture. */
class RemittancePreviewTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();
        config(['ninja.remittance_preview_enabled' => true]);
        $settings = $this->client->settings;
        $settings->currency_id = 1;
        $this->client->settings = $settings;
        $this->client->save();
        DB::table('payments')->where('id', $this->payment->id)->update([
            'amount' => '10.000000', 'applied' => '9.500000', 'refunded' => '0.000000',
            'currency_id' => 1, 'exchange_currency_id' => null, 'exchange_rate' => '1.000000',
            'status_id' => Payment::STATUS_COMPLETED, 'is_deleted' => false, 'deleted_at' => null,
        ]);
        DB::table('paymentables')->where('payment_id', $this->payment->id)->delete();
        DB::table('invoices')->where('id', $this->invoice->id)->update([
            'status_id' => Invoice::STATUS_PARTIAL, 'balance' => '2.250000',
            'is_deleted' => false, 'deleted_at' => null,
        ]);
        DB::table('paymentables')->insert([
            'payment_id' => $this->payment->id, 'paymentable_id' => $this->invoice->id,
            'paymentable_type' => 'invoices', 'amount' => '9.5000', 'refunded' => '0.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function preview(): array
    {
        return (new NativeReceiptAllocationPreview())->build(auth()->user(), $this->payment->hashed_id);
    }

    private function assertDenied(callable $action, int $expected): void
    {
        try { $action(); $this->fail('A partial or unauthorized native report was returned.'); }
        catch (HttpException $exception) { $this->assertSame($expected, $exception->getStatusCode()); }
    }

    public function testReadReconcilesRawStoredDecimalsAndDoesNotWriteOrDispatch(): void
    {
        $before = [];
        foreach (['payments', 'paymentables', 'invoices', 'clients', 'company_ledgers'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        Bus::fake(); Mail::fake();
        $result = $this->preview();
        $this->assertSame('50', $result['receipt']['unapplied_minor']);
        $this->assertSame('950', $result['allocations'][0]['amount_minor']);
        $this->assertFalse($result['is_point_in_time_snapshot']);
        $this->assertArrayNotHasKey('private_notes', $result['receipt']);
        foreach ($before as $table => $rows) { $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson()); }
        Bus::assertNothingDispatched(); Mail::assertNothingSent(); Mail::assertNothingQueued();
    }

    public function testHiddenAllocatedInvoiceDeniesWholeReceiptInsteadOfLeakingAppliedTotals(): void
    {
        Gate::before(fn ($user, $ability, $arguments) => $ability === 'view' && $arguments[0] instanceof Invoice ? false : null);
        $this->assertDenied(fn () => $this->preview(), 403);
    }

    public function testNativeOwnershipPolicyDeniesAnAllocatedInvoiceOwnedElsewhere(): void
    {
        $actor = auth()->user();
        $otherOwner = User::factory()->create(['account_id' => $this->account->id]);
        DB::table('payments')->where('id', $this->payment->id)->update(['user_id' => $actor->id, 'assigned_user_id' => null]);
        DB::table('clients')->where('id', $this->client->id)->update(['user_id' => $actor->id, 'assigned_user_id' => null]);
        DB::table('invoices')->where('id', $this->invoice->id)->update(['user_id' => $otherOwner->id, 'assigned_user_id' => $otherOwner->id]);
        $membership = $actor->token()->cu;
        $membership->is_admin = false; $membership->is_owner = false; $membership->permissions = 'view_reports'; $membership->save();
        // No deny hook: exercise the installed EntityPolicy ownership and current company checks.
        $this->assertTrue(Gate::forUser($actor)->allows('view', $this->payment->fresh()));
        $this->assertTrue(Gate::forUser($actor)->allows('view', $this->client->fresh()));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $this->invoice->fresh()));
        $this->assertDenied(fn () => $this->preview(), 403);
    }

    public function testArchivedLinkedInvoiceUsesNativeViewPolicyButIsNotAnUnpaidCandidate(): void
    {
        DB::table('invoices')->where('id', $this->invoice->id)->update(['deleted_at' => now(), 'is_deleted' => true]);
        $result = $this->preview();
        $this->assertSame($this->invoice->hashed_id, $result['allocations'][0]['invoice_id']);
        $this->assertNotContains($this->invoice->hashed_id, array_column($result['unpaid_invoices'], 'invoice_id'));
        Gate::before(fn ($user, $ability, $arguments) => $ability === 'view' && $arguments[0] instanceof Invoice ? false : null);
        $this->assertDenied(fn () => $this->preview(), 403);
    }

    public function testCapCountsHiddenCandidatesBeforePermissionFiltering(): void
    {
        $row = $this->invoice->fresh()->getRawOriginal(); unset($row['id']);
        $rows = [];
        for ($i = 0; $i < NativeReceiptAllocationPreview::MAX_CANDIDATES; ++$i) {
            $row['number'] = 'tm-remittance-cap-'.$i; $rows[] = $row;
        }
        foreach (array_chunk($rows, 100) as $chunk) { DB::table('invoices')->insert($chunk); }
        $linkedId = $this->invoice->id;
        Gate::before(fn ($user, $ability, $arguments) => $ability === 'view' && $arguments[0] instanceof Invoice && $arguments[0]->id !== $linkedId ? false : null);
        $this->assertDenied(fn () => $this->preview(), 413);
    }

    public function testScopeInputsAreRejectedAndNoNativeCommandAuthorityIsReturned(): void
    {
        $headers = ['X-API-TOKEN' => $this->token, 'X-Requested-With' => 'XMLHttpRequest'];
        $input = ['payment_id' => $this->payment->hashed_id];
        $this->postJson('/api/v1/reports/remittance_allocation_preview', $input, $headers)
            ->assertOk()->assertJsonPath('write_authority', 'NONE')->assertJsonPath('receipt.unapplied_minor', '50');
        foreach (['company_id' => $this->company->id, 'client_id' => $this->client->hashed_id, 'amount' => '1.00', 'send_email' => true] as $key => $value) {
            $this->postJson('/api/v1/reports/remittance_allocation_preview', $input + [$key => $value], $headers)->assertUnprocessable();
        }
    }

    public function testCurrentRoleRevocationAndDisabledFeatureDenyFreshReads(): void
    {
        $membership = auth()->user()->token()->cu;
        $membership->is_admin = false; $membership->is_owner = false; $membership->permissions = ''; $membership->save();
        $this->assertDenied(fn () => $this->preview(), 403);
        config(['ninja.remittance_preview_enabled' => false]);
        $this->assertDenied(fn () => $this->preview(), 403);
    }
}
