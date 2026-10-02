<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Models\{CompanyLedger,Invoice,Payment,User};
use App\Factory\InvoiceFactory;
use App\Repositories\ActivityRepository;
use App\Services\Receivables\AcceptReceiptAllocation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{Bus,DB,Mail};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\{MockAccountData,TestCase};

/** Run only in the separately reviewed new allocation schema; no current receipt/read fixture. */
final class ReceiptAllocationAcceptanceTest extends TestCase
{
    use MockAccountData,DatabaseTransactions;
    private Invoice $target;
    protected function setUp(): void {
        parent::setUp();Bus::fake()->except([\App\Jobs\Company\CreateCompanyTaskStatuses::class]);Mail::fake();$this->makeTestData();Bus::fake();
        config(['ninja.receipt_allocation_enabled'=>true,'ninja.receipt_allocation_mode'=>'STANDALONE','ninja.db.multi_db_enabled'=>false]);
        request()->headers->set('X-API-TOKEN',$this->token);
        $settings=$this->company->settings;$settings->currency_id='1';$settings->france_reporting_enabled=false;$this->company->settings=$settings;$this->company->quickbooks=null;$this->company->saveQuietly();
        $settings=$this->client->settings;$settings->currency_id='1';$settings->auto_archive_invoice=false;$this->client->settings=$settings;$this->client->country_id=null;$this->client->saveQuietly();
        $owner=User::factory()->create(['account_id'=>$this->account->id]);
        DB::table('company_user')->where('user_id',$this->user->id)->where('company_id',$this->company->id)->update(['is_admin'=>false,'is_owner'=>false,'is_locked'=>false,'permissions'=>'view_reports,view_payment,view_invoice,view_client,edit_payment,edit_invoice,edit_client']);
        DB::table('clients')->where('id',$this->client->id)->update(['user_id'=>$owner->id,'assigned_user_id'=>null,'balance'=>'6.250000','paid_to_date'=>'10.000000','payment_balance'=>'0.500000']);
        DB::table('payments')->where('id',$this->payment->id)->update(['user_id'=>$owner->id,'assigned_user_id'=>null,'amount'=>'10.000000','applied'=>'9.500000','refunded'=>'0.000000','status_id'=>Payment::STATUS_COMPLETED,'currency_id'=>1,'exchange_currency_id'=>null,'exchange_rate'=>'1.000000','number'=>'TM-allocation-receipt','is_deleted'=>false,'deleted_at'=>null]);
        DB::table('invoices')->where('id',$this->invoice->id)->update(['user_id'=>$owner->id,'assigned_user_id'=>null,'amount'=>'11.750000','balance'=>'2.250000','paid_to_date'=>'9.500000','partial'=>'0.000000','status_id'=>Invoice::STATUS_PARTIAL,'is_proforma'=>false,'is_deleted'=>false,'deleted_at'=>null]);
        $this->target=InvoiceFactory::create($this->company->id,$owner->id);$this->target->client_id=$this->client->id;$this->target->amount='4.000000';$this->target->balance='4.000000';$this->target->paid_to_date='0.000000';$this->target->partial='0.000000';$this->target->status_id=Invoice::STATUS_SENT;$this->target->number='TM-allocation-target';$this->target->saveQuietly();
        DB::table('paymentables')->where('payment_id',$this->payment->id)->delete();DB::table('paymentables')->insert(['payment_id'=>$this->payment->id,'paymentable_id'=>$this->invoice->id,'paymentable_type'=>'invoices','amount'=>'9.5000','refunded'=>'0.0000','created_at'=>now(),'updated_at'=>now()]);
        DB::table('company_ledgers')->where('company_id',$this->company->id)->where('client_id',$this->client->id)->delete();
        $opening=new CompanyLedger();$opening->company_id=$this->company->id;$opening->user_id=$owner->id;$opening->client_id=$this->client->id;$opening->adjustment='6.250000';$opening->balance='6.250000';$opening->activity_id=1;$opening->notes='Synthetic opening';$opening->hash='';$this->payment->company_ledger()->save($opening);
        DB::table('webhooks')->where('company_id',$this->company->id)->delete();
        $this->payment=$this->payment->fresh();$this->client=$this->client->fresh();
    }
    private function input(): array {return ['payment_id'=>$this->payment->hashed_id,'invoice_id'=>$this->target->hashed_id,'amount_minor'=>'50','expected_native_actor_id'=>$this->user->hashed_id];}
    private function command(): array {$input=$this->input();$intent=(new AcceptReceiptAllocation())->intent($this->user,$input);return [...$input,'operation_key'=>'synthetic_allocation_0001','expected_financial_beforeimage'=>$intent['expected_financial_beforeimage']];}
    private function rows(): array {$out=[];foreach(['payments','paymentables','invoices','clients','company_ledgers','activities','receipt_allocation_operations'] as $t)$out[$t]=DB::table($t)->orderBy('id')->get()->toJson();return $out;}
    private function denied(callable $action,int $status): void {try{$action();$this->fail('Native command should have been denied');}catch(HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    public function testIntentHasNoNativeEffectsAndReturnsAnHonestFinancialBeforeimage(): void {$before=$this->rows();$r=(new AcceptReceiptAllocation())->intent($this->user,$this->input());$this->assertTrue($r['preview_only']);$this->assertSame('NONE',$r['write_authority']);$this->assertSame('0.5000',$r['proposed']['paymentable_amount']);$this->assertSame($before,$this->rows());Bus::assertNothingDispatched();Mail::assertNothingSent();}
    public function testNativeExactAcceptanceAuditLedgerAndDurableBroadcastIntentCommitTogether(): void {
        $command=$this->command();$beforeActivity=DB::table('activities')->count();$r=(new AcceptReceiptAllocation())->accept($this->user,$command);
        $this->assertSame('COMMITTED',$r['state']);$this->assertSame('PENDING_BROADCAST',$r['callback_state']);$this->assertFalse($r['official_gl_posting']);
        $this->assertSame('10.000000',$this->payment->fresh()->getRawOriginal('amount'));$this->assertSame('10.000000',$this->payment->fresh()->getRawOriginal('applied'));
        $this->assertSame('3.500000',$this->target->fresh()->getRawOriginal('balance'));$this->assertSame('0.500000',$this->target->fresh()->getRawOriginal('paid_to_date'));
        $this->assertSame('5.750000',$this->client->fresh()->getRawOriginal('balance'));$this->assertSame('10.000000',$this->client->fresh()->getRawOriginal('paid_to_date'));
        $last=CompanyLedger::where('client_id',$this->client->id)->orderByDesc('id')->first();$this->assertSame('-0.500000',$last->getRawOriginal('adjustment'));$this->assertSame('5.750000',$last->getRawOriginal('balance'));
        $this->assertSame($beforeActivity+1,DB::table('activities')->count());$this->assertSame($this->user->id,DB::table('activities')->orderByDesc('id')->value('user_id'));
        $this->assertSame(1,DB::table('receipt_allocation_operations')->count());Bus::assertNothingDispatched();Mail::assertNothingSent();Mail::assertNothingQueued();
    }
    public function testAcceptanceReceiptRetainsTheNativeAutoIncrementedPaymentableIdentity(): void {
        $command=$this->command();$service=new AcceptReceiptAllocation();$receipt=$service->accept($this->user,$command);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/',$receipt['allocation_id']);
        $pivot=DB::table('paymentables')->where('id',$receipt['allocation_id'])->first();
        $this->assertNotNull($pivot);$this->assertSame($this->payment->id,(int)$pivot->payment_id);
        $this->assertSame($this->target->id,(int)$pivot->paymentable_id);$this->assertSame('invoices',$pivot->paymentable_type);
        $this->assertSame('0.5000',$pivot->amount);
        $stored=json_decode(DB::table('receipt_allocation_operations')->where('id',$receipt['operation_id'])->value('result_json'),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame($receipt,$stored);$rows=$this->rows();
        $this->assertSame($receipt,$service->reconcile($this->user,$command)['receipt']);
        $this->assertSame($receipt['allocation_id'],$service->accept($this->user,$command)['allocation_id']);
        $this->assertSame($rows,$this->rows());Bus::assertNothingDispatched();Mail::assertNothingSent();
    }
    public function testIdenticalReplayUsesStoredResultAndDifferentPayloadConflicts(): void {$command=$this->command();$service=new AcceptReceiptAllocation();$first=$service->accept($this->user,$command);$rows=$this->rows();$again=$service->accept($this->user,$command);$this->assertTrue($again['replayed']);$this->assertSame($first['allocation_id'],$again['allocation_id']);$this->assertSame($rows,$this->rows());$other=$command;$other['amount_minor']='49';$this->denied(fn()=>$service->accept($this->user,$other),409);$this->assertSame($rows,$this->rows());}
    public function testLockedFinancialBeforeimageRejectsChangedInvoiceWithoutAnyNewEffects(): void {$command=$this->command();DB::table('invoices')->where('id',$this->target->id)->update(['balance'=>'3.990000']);$rows=$this->rows();$this->denied(fn()=>(new AcceptReceiptAllocation())->accept($this->user,$command),409);$this->assertSame($rows,$this->rows());}
    public function testCurrentNativePermissionRevocationAlsoDeniesCommittedReplay(): void {$command=$this->command();$service=new AcceptReceiptAllocation();$service->accept($this->user,$command);DB::table('company_user')->where('company_id',$this->company->id)->where('user_id',$this->user->id)->update(['permissions'=>'view_reports,view_payment,view_invoice,view_client,edit_payment,edit_client']);$rows=$this->rows();$this->denied(fn()=>$service->accept($this->user,$command),403);$this->assertSame($rows,$this->rows());}
    public function testArchivedNativeUserCannotReplayACommittedOperation(): void {$command=$this->command();$service=new AcceptReceiptAllocation();$service->accept($this->user,$command);DB::table('users')->where('id',$this->user->id)->update(['is_deleted'=>true]);$rows=$this->rows();$this->denied(fn()=>$service->accept($this->user,$command),403);$this->assertSame($rows,$this->rows());}
    public function testNullPreviousAuthIsRestoredAndReplayFactsRemainExplicitlyHistorical(): void {$command=$this->command();auth()->logout();$service=new AcceptReceiptAllocation();$first=$service->accept($this->user,$command);$this->assertNull(auth()->user());DB::table('invoices')->where('id',$this->target->id)->update(['balance'=>'3.490000']);$rows=$this->rows();$again=$service->accept($this->user,$command);$this->assertTrue($again['replayed']);$this->assertSame($first['financial_result'],$again['financial_result']);$this->assertSame('historical_committed_operation_facts_not_current_snapshot',$again['result_semantics']);$this->assertSame($rows,$this->rows());$this->assertNull(auth()->user());}
    public function testActorChangeBeforeDispatchDeniesBeforeAnyOperationOrBalanceEffect(): void {$command=$this->command();$command['expected_native_actor_id']='anotherActor';$rows=$this->rows();$this->denied(fn()=>(new AcceptReceiptAllocation())->accept($this->user,$command),403);$this->assertSame($rows,$this->rows());}
    public function testNativeAuditFailureRollsBackPivotBalancesLedgerAndOperationReceipt(): void {$command=$this->command();$rows=$this->rows();$repo=$this->createMock(ActivityRepository::class);$repo->method('save')->willThrowException(new \RuntimeException('synthetic audit failure'));app()->instance(ActivityRepository::class,$repo);try{(new AcceptReceiptAllocation())->accept($this->user,$command);$this->fail('Audit failure was swallowed');}catch(\RuntimeException $e){$this->assertSame('synthetic audit failure',$e->getMessage());}finally{app()->forgetInstance(ActivityRepository::class);}$this->assertSame($rows,$this->rows());}
    public function testMissingReceiptReadCannotApplyMoneyOrAuthorizeRetry(): void {
        $command=$this->command();$rows=$this->rows();$result=(new AcceptReceiptAllocation())->reconcile($this->user,$command);
        $this->assertSame('NOT_FOUND',$result['state']);$this->assertNull($result['receipt']);$this->assertTrue($result['read_only']);
        $this->assertSame('NONE',$result['write_authority']);$this->assertFalse($result['automatic_replay_allowed']);
        $this->assertSame('absence_of_committed_receipt_not_permission_to_retry',$result['not_found_semantics']);
        $this->assertSame($rows,$this->rows());Bus::assertNothingDispatched();Mail::assertNothingSent();Mail::assertNothingQueued();
    }
    public function testCommittedReadbackReturnsHistoricalReceiptWithoutReplayingOrUpdatingRows(): void {
        $command=$this->command();$service=new AcceptReceiptAllocation();$receipt=$service->accept($this->user,$command);$rows=$this->rows();
        foreach([1,2] as $read){$result=$service->reconcile($this->user,$command);$this->assertSame('COMMITTED',$result['state']);$this->assertSame($receipt,$result['receipt']);$this->assertSame($rows,$this->rows());}
        Bus::assertNothingDispatched();Mail::assertNothingSent();Mail::assertNothingQueued();
    }
    public function testReconciliationRechecksCurrentNativePermissionAndConflictingPayload(): void {
        $command=$this->command();$service=new AcceptReceiptAllocation();$service->accept($this->user,$command);
        $changed=$command;$changed['amount_minor']='49';$rows=$this->rows();$this->denied(fn()=>$service->reconcile($this->user,$changed),409);$this->assertSame($rows,$this->rows());
        DB::table('company_user')->where('company_id',$this->company->id)->where('user_id',$this->user->id)->update(['permissions'=>'view_reports,view_payment,view_invoice,view_client,edit_payment,edit_client']);
        $rows=$this->rows();$this->denied(fn()=>$service->reconcile($this->user,$command),403);$this->assertSame($rows,$this->rows());
    }
}
