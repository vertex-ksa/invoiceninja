<?php

namespace App\Services\Receivables;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class NativeCashFlowForecast
{
    public function build(User $actor,array $input,bool $lockSource=false): array
    {
        abort_unless(app()->environment(['local','testing']) && config('ninja.cash_flow_forecast_enabled')===true,403);
        $company=$actor->company();
        abort_unless(!$actor->is_deleted && !$company->is_disabled && (string)$actor->companyId()===(string)$company->id
            && ($actor->isAdmin()||$actor->hasPermission('view_reports')),403);
        $invoiceQuery=Invoice::query()->where('company_id',$company->id)->where('is_deleted',false)->whereNull('deleted_at')
            ->whereIn('status_id',[Invoice::STATUS_SENT,Invoice::STATUS_PARTIAL])->where('balance','>',0)
            ->whereHas('client',fn($q)=>$q->where('company_id',$company->id)->where('is_deleted',false)->whereNull('deleted_at'))
            ->with(['client'=>function($query)use($lockSource){if($lockSource){$query->lockForUpdate();}}])
            ->orderBy('id')->limit(1001);
        if($lockSource){$invoiceQuery->lockForUpdate();}
        $invoices=$invoiceQuery->get();
        abort_if($invoices->count()>1000,413);
        $facts=[];
        foreach($invoices as $invoice){
            if(!Gate::forUser($actor)->allows('view',$invoice)){continue;}
            $currency=$invoice->client->currency();
            if(!$currency||(string)$invoice->client->company_id!==(string)$company->id){throw new InvalidArgumentException('Native currency or scope unavailable.');}
            $row=['invoice_id'=>$invoice->hashed_id,'currency'=>$currency->code,'precision'=>$currency->precision,
                'balance_minor'=>(new NativeBalance())->minor($invoice->getRawOriginal('balance'),$currency->precision),
                'due_date'=>(new NativeInvoiceDate())->calendar($invoice->getRawOriginal('due_date'))];
            $row['source_token']=hash('sha256',json_encode([$row,$invoice->getRawOriginal('updated_at')],JSON_THROW_ON_ERROR));$facts[]=$row;
        }
        $result=(new CashFlowForecast())->build($facts,$input);
        return [...$result,'company_id'=>(string)$company->id,'native_actor_id'=>$actor->hashed_id,'generated_at'=>now('UTC')->toIso8601String(),
            'source_semantics'=>'current_authorized_native_open_invoice_balances_not_point_in_time_or_historical_cash',
            'scope_complete'=>true,'is_point_in_time_snapshot'=>false];
    }
}
