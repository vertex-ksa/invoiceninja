<?php
declare(strict_types=1);
namespace App\Services\Receivables;

use App\Events\Payment\PaymentWasUpdated;
use App\Listeners\Activity\PaymentUpdatedActivity;
use App\Models\{Client,Company,CompanyLedger,CompanyToken,CompanyUser,Invoice,Payment,Paymentable,User,Webhook};
use App\Utils\{Ninja,TruthSource};
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Facades\{DB,Gate};
use InvalidArgumentException;

/** Local standalone only. Financial effects/audit/reconciliation commit together; broadcast stays durable pending. */
final class AcceptReceiptAllocation
{
    use MakesHash;

    public function intent(User $actor, array $input): array
    {
        $input = (new ReceiptAllocationInput())->validate($input, false);
        return $this->transaction($actor, $input, function ($user,$company,$payment,$client,$invoice,$pivots,$ledger) use ($input) {
            [$plan,$hash] = $this->plan($user,$company,$payment,$client,$invoice,$pivots,$ledger,$input);
            return ['preview_only'=>true,'write_authority'=>'NONE','currency'=>'USD','currency_precision'=>2,
                'company_id'=>(string)$company->id,'client_id'=>$client->hashed_id,'native_actor_id'=>$user->hashed_id,
                'payment_id'=>$payment->hashed_id,'invoice_id'=>$invoice->hashed_id,'amount_minor'=>$input['amount_minor'],
                'expected_financial_beforeimage'=>$hash,'beforeimage_semantics'=>'locked_native_financial_fields_sha256_not_version_counter',
                'proposed'=>$plan,'mode'=>'STANDALONE_OPERATIONAL_SUBLEDGER','official_gl_posting'=>false];
        });
    }

    public function accept(User $actor, array $input): array
    {
        $input = (new ReceiptAllocationInput())->validate($input, true);
        return $this->transaction($actor,$input,function ($user,$company,$payment,$client,$invoice,$pivots,$ledger) use ($input) {
            $payloadHash = (new ReceiptAllocationInput())->payloadHash($input);
            // Current native authority was already rechecked under locks, even for a committed replay.
            $existing = DB::table('receipt_allocation_operations')->where('company_id',$company->id)
                ->where('native_user_id',$user->id)->where('operation_key',$input['operation_key'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_sha256,$payloadHash),409,'Allocation operation key conflicts.');
                if ($existing->result_json === null) throw new \RuntimeException('Incomplete native operation receipt.');
                $result = json_decode($existing->result_json,true,512,JSON_THROW_ON_ERROR);
                return [...$result,'replayed'=>true];
            }
            $currentHash=$this->financialBeforeimage($user,$company,$payment,$client,$invoice,$pivots,$ledger,$input);
            abort_unless(hash_equals($currentHash,$input['expected_financial_beforeimage']),409,'Native financial beforeimage changed.');
            [$plan,$hash] = $this->plan($user,$company,$payment,$client,$invoice,$pivots,$ledger,$input);
            $operationId = DB::table('receipt_allocation_operations')->insertGetId([
                'company_id'=>$company->id,'native_user_id'=>$user->id,'payment_id'=>$payment->id,'invoice_id'=>$invoice->id,
                'operation_key'=>$input['operation_key'],'payload_sha256'=>$payloadHash,'beforeimage_sha256'=>$hash,
                'callback_state'=>'PENDING_BROADCAST','callback_json'=>json_encode(['native_event'=>'PaymentWasUpdated',
                    'delivery_scope'=>'broadcast_only','company_id'=>(string)$company->id,'payment_id'=>$payment->hashed_id,
                    'native_actor_id'=>$user->hashed_id,'native_audit'=>'TRANSACTION_CONTAINED','payment_balance'=>'TRANSACTION_CONTAINED'],JSON_THROW_ON_ERROR),
                'created_at'=>now()->timestamp,'updated_at'=>now()->timestamp,
            ]);
            // Paymentable inherits Pivot's non-incrementing default. This insert needs
            // its native auto-incremented identity in the durable operation receipt.
            $pivot = new Paymentable(); $pivot->setIncrementing(true); $pivot->payment_id=$payment->id; $pivot->paymentable_id=$invoice->id;
            $pivot->paymentable_type='invoices'; $pivot->amount=$plan['paymentable_amount']; $pivot->refunded='0.0000';
            $pivot->created_at=now('UTC')->timestamp; $pivot->save();
            $invoice->setRelation('client',$client); $payment->setRelation('client',$client); $payment->setRelation('company',$company);
            $invoice = $invoice->service()->applyPaymentExactPartial($payment,$plan)->save();
            $payment->applied=$plan['payment_applied']; $payment->saveQuietly();
            // The existing queued balance listener catches errors; call its actual native SQL effect directly,
            // with exceptions propagated so the encompassing transaction cannot report partial success.
            $client->service()->updatePaymentBalance();
            $eventVars=Ninja::eventVars($user->id); $eventVars['user_id']=$user->id; // Actual bound actor even in native console tests.
            $event = new PaymentWasUpdated($payment->fresh(),$company,$eventVars);
            app(PaymentUpdatedActivity::class)->handle($event); // Actual native ActivityRepository, Payment has no PDF backup.
            // Do not dispatch ShouldBroadcast/queued callbacks before commit. Their intent remains explicit/durable.
            $result=['operation_id'=>(string)$operationId,'operation_key'=>$input['operation_key'],'replayed'=>false,
                'state'=>'COMMITTED','native_actor_id'=>$user->hashed_id,'company_id'=>(string)$company->id,
                'client_id'=>$client->hashed_id,'payment_id'=>$payment->hashed_id,'invoice_id'=>$invoice->hashed_id,
                'allocation_id'=>(string)$pivot->id,'currency'=>'USD','currency_precision'=>2,'amount_minor'=>$input['amount_minor'],
                'financial_result'=>$plan,'native_audit'=>'COMMITTED','native_reconciliation'=>'COMMITTED',
                'callback_state'=>'PENDING_BROADCAST','mode'=>'STANDALONE_OPERATIONAL_SUBLEDGER','official_gl_posting'=>false,
                'beforeimage_sha256'=>$hash,'beforeimage_semantics'=>'locked_native_financial_fields_sha256_not_version_counter',
                'result_semantics'=>'historical_committed_operation_facts_not_current_snapshot',
                'replay_semantics'=>'stored_result_after_current_native_authorization',
                'committed_at'=>now('UTC')->toIso8601String()];
            DB::table('receipt_allocation_operations')->where('id',$operationId)->update(['result_json'=>json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    /** Authoritative readback only: never invokes accept, creates an operation, or applies money. */
    public function reconcile(User $actor, array $input): array
    {
        $input = (new ReceiptAllocationInput())->validate($input, true);
        return $this->transaction($actor,$input,function ($user,$company,$payment,$client,$invoice,$pivots,$ledger) use ($input) {
            $existing = DB::table('receipt_allocation_operations')->where('company_id',$company->id)
                ->where('native_user_id',$user->id)->where('operation_key',$input['operation_key'])->lockForUpdate()->first();
            $receipt = null;
            if ($existing) {
                abort_unless(hash_equals($existing->payload_sha256,(new ReceiptAllocationInput())->payloadHash($input)),409,'Allocation operation key conflicts.');
                if ($existing->result_json === null) throw new \RuntimeException('Incomplete native operation receipt.');
                $receipt = json_decode($existing->result_json,true,512,JSON_THROW_ON_ERROR);
            }
            return ['read_only'=>true,'write_authority'=>'NONE','state'=>$existing?'COMMITTED':'NOT_FOUND',
                'operation_key'=>$input['operation_key'],'company_id'=>(string)$company->id,'native_actor_id'=>$user->hashed_id,
                'client_id'=>$client->hashed_id,'payment_id'=>$payment->hashed_id,'invoice_id'=>$invoice->hashed_id,
                'amount_minor'=>$input['amount_minor'],'expected_financial_beforeimage'=>$input['expected_financial_beforeimage'],
                'receipt'=>$receipt,'automatic_replay_allowed'=>false,
                'not_found_semantics'=>'absence_of_committed_receipt_not_permission_to_retry'];
        });
    }

    private function transaction(User $actor,array $input,callable $action): array
    {
        abort_unless(PHP_INT_SIZE === 8,503);
        abort_unless(app()->environment(['local','testing']) && config('ninja.receipt_allocation_enabled') === true
            && config('ninja.receipt_allocation_mode') === 'STANDALONE' && !config('ninja.db.multi_db_enabled'),403);
        abort_unless($actor->hashed_id === $input['expected_native_actor_id'],403);
        $old = app(TruthSource::class); $saved=[$old->company,$old->user,$old->company_user,$old->company_token];
        $previousUser=auth()->user();
        try {
            return DB::transaction(function () use ($actor,$input,$action) {
                $company=Company::query()->where('id',$actor->companyId())->lockForUpdate()->first();
                abort_unless($company && !$company->is_disabled && $company->db === config('database.default'),403);
                $user=User::query()->where('id',$actor->id)->where('is_deleted',false)->lockForUpdate()->first();
                $token=CompanyToken::query()->where('id',$actor->token()?->id)->where('company_id',$company->id)
                    ->where('user_id',$actor->id)->where('token',request()->header('X-API-TOKEN'))
                    ->where('is_deleted',false)->lockForUpdate()->first();
                $cu=CompanyUser::query()->where('company_id',$company->id)->where('user_id',$actor->id)->lockForUpdate()->first();
                abort_unless($user && $token && $cu && !$cu->is_locked && (string)$user->account_id === (string)$company->account_id
                    && (string)$token->account_id === (string)$company->account_id && (string)$cu->account_id === (string)$company->account_id,403);
                $token->setRelation('cu',$cu); $user->setCompany($company); auth()->setUser($user);
                app(TruthSource::class)->setCompany($company)->setUser($user)->setCompanyUser($cu)->setCompanyToken($token);
                abort_unless($user->isAdmin() || $user->hasPermission('view_reports'),403);
                $paymentId=$this->decodePrimaryKey($input['payment_id'],true);$invoiceId=$this->decodePrimaryKey($input['invoice_id'],true);
                abort_unless(is_int($paymentId) && $paymentId>0 && is_int($invoiceId) && $invoiceId>0,422);
                // Resolve client, then lock it before any receipt/invoice/ledger writes, including legacy client writers.
                $reference=Payment::withTrashed()->where('company_id',$company->id)->find($paymentId);abort_unless($reference,403);
                $client=Client::withTrashed()->where('company_id',$company->id)->where('id',$reference->client_id)->lockForUpdate()->first();
                $payment=Payment::withTrashed()->where('company_id',$company->id)->where('id',$paymentId)->lockForUpdate()->first();
                $invoice=Invoice::withTrashed()->where('company_id',$company->id)->where('client_id',$client?->id)->where('id',$invoiceId)->lockForUpdate()->first();
                abort_unless($client && $payment && $invoice && (string)$payment->client_id === (string)$client->id,403);
                foreach ([$payment,$client,$invoice] as $entity) {
                    abort_unless(Gate::forUser($user)->allows('view',$entity) && Gate::forUser($user)->allows('edit',$entity),403);
                }
                $pivots=Paymentable::query()->where('payment_id',$payment->id)->orderBy('id')->limit(1001)->lockForUpdate()->get();
                abort_if($pivots->count()>1000,413);
                foreach ($pivots as $pivot) {
                    if ($pivot->paymentable_type !== 'invoices') throw new InvalidArgumentException('Credit allocations are unsupported.');
                    $linked=Invoice::withTrashed()->where('id',$pivot->paymentable_id)->lockForUpdate()->first();
                    abort_unless($linked && (string)$linked->company_id === (string)$company->id
                        && (string)$linked->client_id === (string)$client->id && Gate::forUser($user)->allows('view',$linked),403);
                }
                $ledger=CompanyLedger::query()->where('company_id',$company->id)->where('client_id',$client->id)
                    ->orderBy('id')->limit(1001)->lockForUpdate()->get();
                abort_if($ledger->count()>1000,413);
                return $action($user,$company,$payment,$client,$invoice,$pivots,$ledger);
            },1); // No automatic deadlock retry of a financial command.
        } finally {
            app(TruthSource::class)->setCompany($saved[0])->setUser($saved[1])->setCompanyUser($saved[2])->setCompanyToken($saved[3]);
            if ($previousUser) auth()->setUser($previousUser); else auth()->forgetUser();
        }
    }

    private function plan($user,$company,$payment,$client,$invoice,$pivots,$ledger,array $input): array
    {
        $money=new ExactAllocationAmounts();
        // Reserve one row of the bounded native read scope for this new application/ledger adjustment.
        abort_if($pivots->count()>=1000 || $ledger->count()>=1000,413);
        if ($payment->trashed() || $payment->is_deleted || $client->trashed() || $client->is_deleted || $invoice->trashed() || $invoice->is_deleted
            || (int)$payment->status_id !== Payment::STATUS_COMPLETED || !in_array((int)$invoice->status_id,[Invoice::STATUS_SENT,Invoice::STATUS_PARTIAL],true)
            || $invoice->hasPartial() || $invoice->is_proforma || !is_string($invoice->number) || $invoice->number === ''
            || !is_string($payment->number) || $payment->number === '') throw new InvalidArgumentException('Only ordinary active native partial allocation is supported.');
        $currency=$payment->currency; $clientCurrency=$client->currency();
        if (!$currency || !$clientCurrency || $currency->code !== 'USD' || !in_array($currency->getRawOriginal('precision'),[2,'2'],true)
            || (string)$currency->id !== (string)$clientCurrency->id
            || (string)$company->getSetting('currency_id') !== (string)$currency->id
            || ($payment->exchange_currency_id !== null && (string)$payment->exchange_currency_id !== (string)$currency->id)
            || (new NativeBalance())->minor($payment->getRawOriginal('exchange_rate'),6) !== '1000000') {
            throw new InvalidArgumentException('Only native unit-FX USD precision2 is supported.');
        }
        // Unsupported external/native workflows are rejected, never switched off to permit the command.
        if (Webhook::query()->where('company_id',$company->id)->where('is_deleted',false)->whereNull('deleted_at')->exists()
            || $company->getRawOriginal('quickbooks') !== null || (bool)$company->getSetting('france_reporting_enabled')
            || $client->reportableFrTransaction()) throw new InvalidArgumentException('Native external reporting/integration effects require separate review.');
        $sum=0;
        foreach ($pivots as $p) {
            if ($money->unsigned($p->getRawOriginal('refunded')) !== 0) throw new InvalidArgumentException('Refunded allocations are unsupported.');
            $value=$money->unsigned($p->getRawOriginal('amount'));if($value>99999999999999 || $sum>9999999999999999-$value)throw new InvalidArgumentException('Allocation storage envelope exceeded.');
            $sum+=$value;
        }
        foreach ($ledger as $row) if ($row->getRawOriginal('balance') === null) throw new InvalidArgumentException('Native ledger reconciliation is pending.');
        $ledgerBalance=$ledger->last()?->getRawOriginal('balance') ?? '0.000000';
        if ($money->signed($ledgerBalance) !== $money->unsigned($client->getRawOriginal('balance'))) {
            throw new InvalidArgumentException('Native operational ledger/client balances require reconciliation.');
        }
        $facts=['payment_amount'=>$payment->getRawOriginal('amount'),'payment_applied'=>$payment->getRawOriginal('applied'),
            'payment_refunded'=>$payment->getRawOriginal('refunded'),'invoice_balance'=>$invoice->getRawOriginal('balance'),
            'invoice_paid_to_date'=>$invoice->getRawOriginal('paid_to_date'),'client_balance'=>$client->getRawOriginal('balance'),
            'ledger_balance'=>$ledgerBalance,'allocations_sum_minor'=>(string)$sum];
        $plan=$money->plan($facts,$input['amount_minor']);
        return [$plan,$this->financialBeforeimage($user,$company,$payment,$client,$invoice,$pivots,$ledger,$input)];
    }

    private function financialBeforeimage($user,$company,$payment,$client,$invoice,$pivots,$ledger,array $input): string
    {
        $select=static function($model,array $keys): array {$result=[];foreach($keys as $key)$result[$key]=$model->getRawOriginal($key);return $result;};
        $bound=['company_id'=>$company->id,'native_actor_id'=>$user->id,'payment_id'=>$payment->id,'invoice_id'=>$invoice->id,
            'client_id'=>$client->id,'amount_minor'=>$input['amount_minor'],'company_currency'=>$company->getSetting('currency_id'),
            'payment'=>$select($payment,['amount','applied','refunded','status_id','currency_id','exchange_currency_id','exchange_rate','is_deleted','deleted_at','updated_at']),
            'invoice'=>$select($invoice,['amount','balance','paid_to_date','status_id','partial','is_proforma','next_send_date','is_deleted','deleted_at','updated_at']),
            'client'=>$select($client,['balance','paid_to_date','payment_balance','settings','is_deleted','deleted_at','updated_at']),
            'pivots'=>$pivots->map(fn($r)=>$select($r,['id','paymentable_id','paymentable_type','amount','refunded','updated_at']))->all(),
            'ledger'=>$ledger->map(fn($r)=>$select($r,['id','adjustment','balance','updated_at']))->all()];
        return hash('sha256',json_encode($bound,JSON_THROW_ON_ERROR));
    }
}
