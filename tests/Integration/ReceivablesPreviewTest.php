<?php

namespace Tests\Integration;

use App\Models\Invoice;
use App\Models\Company;
use App\Services\Receivables\NativeCollectionPreview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MockAccountData;
use Tests\TestCase;

/** Must run on the locked native Laravel dependencies and a disposable synthetic database. */
class ReceivablesPreviewTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();
        config(['ninja.receivables_preview_enabled' => true]);
        $this->invoice->status_id = Invoice::STATUS_SENT;
        $this->invoice->balance = '12.000000';
        $this->invoice->due_date = '2026-09-01';
        $this->invoice->is_deleted = false;
        $this->invoice->save();
    }

    private function preview(): array
    {
        return (new NativeCollectionPreview())->build(auth()->user(), '2026-10-01', 1);
    }

    public function testReadDoesNotMutateOrDispatchAndUnknownHoldsStayUnverified(): void
    {
        $before = $this->invoice->fresh()->getRawOriginal();
        Bus::fake();
        Mail::fake();
        $result = $this->preview();
        $rows = array_column($result['rows'], null, 'invoice_id');
        $this->assertSame('hold_context_unverified', $rows[$this->invoice->hashed_id]['state']);
        $this->assertFalse($rows[$this->invoice->hashed_id]['dispatch_allowed']);
        $this->assertFalse($result['is_point_in_time_snapshot']);
        $this->assertSame($before, $this->invoice->fresh()->getRawOriginal());
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function testDisabledCapabilityRejectsBeforeRead(): void
    {
        config(['ninja.receivables_preview_enabled' => false]);
        $this->expectException(HttpException::class);
        $this->preview();
    }

    public function testProductionEnvironmentRejectsEvenWhenOptedIn(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(HttpException::class);
        $this->preview();
    }

    public function testObjectPermissionDenialRemovesRowsAndTotals(): void
    {
        Gate::before(fn ($user, $ability) => $ability === 'view' ? false : null);
        $result = $this->preview();
        $this->assertSame([], $result['rows']);
        $this->assertSame([], $result['open_receivables_minor_by_currency']);
    }

    public function testCanceledPaidDraftAndOtherCompanyCannotEnterReceivables(): void
    {
        foreach ([Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID, Invoice::STATUS_DRAFT] as $status) {
            $this->invoice->status_id = $status;
            $this->invoice->save();
            $ids = array_column($this->preview()['rows'], 'invoice_id');
            $this->assertNotContains($this->invoice->hashed_id, $ids);
        }
        $this->invoice->status_id = Invoice::STATUS_SENT;
        $this->invoice->company_id = Company::factory()->create(['account_id' => $this->account->id])->id;
        $this->invoice->save();
        $this->assertNotContains($this->invoice->hashed_id, array_column($this->preview()['rows'], 'invoice_id'));
    }

    public function testClassificationDateDoesNotReconstructHistoricalBalances(): void
    {
        $adapter = new NativeCollectionPreview();
        $past = $adapter->build(auth()->user(), '2020-01-01', 1);
        $future = $adapter->build(auth()->user(), '2030-01-01', 1);
        $this->assertSame($past['open_receivables_minor_by_currency'], $future['open_receivables_minor_by_currency']);
        $this->assertSame('current_native_balance_at_read_time', $past['balance_basis']);
        $this->assertSame('overdue_classification_date_only_not_historical_receivables', $future['as_of_date_semantics']);
        $this->assertSame('native_updated_at_timestamp_not_monotonic_revision', $future['source_version_semantics']);
    }

    public function testRoleRevocationDeniesAnotherPreview(): void
    {
        $membership = auth()->user()->token()->cu;
        $membership->is_admin = false;
        $membership->is_owner = false;
        $membership->permissions = '';
        $membership->save();
        $this->expectException(HttpException::class);
        $this->preview();
    }

    public function testCapacityRejectsWithoutReturningPartialTotals(): void
    {
        $row = $this->invoice->fresh()->getRawOriginal();
        unset($row['id']);
        $rows = [];
        for ($i = 0; $i < NativeCollectionPreview::MAX_CANDIDATES; ++$i) {
            $row['number'] = 'tm-b-cap-'.$i;
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 100) as $chunk) { Invoice::insert($chunk); }
        try {
            $this->preview();
            $this->fail('Truncated preview unexpectedly returned.');
        } catch (HttpException $exception) {
            $this->assertSame(413, $exception->getStatusCode());
        }
    }

    public function testAuthenticatedApiPreservesNoDispatchAndRejectsCallerScope(): void
    {
        $headers = ['X-API-TOKEN' => $this->token, 'X-Requested-With' => 'XMLHttpRequest'];
        $input = ['as_of_date' => '2026-10-01', 'minimum_overdue_days' => 1];
        $this->postJson('/api/v1/reports/receivables_preview', $input, $headers)
            ->assertOk()->assertJsonPath('preview_only', true)
            ->assertJsonPath('balance_basis', 'current_native_balance_at_read_time');
        $this->postJson('/api/v1/reports/receivables_preview', $input + ['company_id' => $this->company->id], $headers)
            ->assertUnprocessable();
        $this->postJson('/api/v1/reports/receivables_preview', $input + ['send_email' => true], $headers)
            ->assertUnprocessable();
    }

    public function testNativePaymentBalanceChangeIsReadOnNextPreview(): void
    {
        $this->invoice->balance = '0.000000';
        $this->invoice->save();
        $this->assertNotContains($this->invoice->hashed_id, array_column($this->preview()['rows'], 'invoice_id'));
    }
}
