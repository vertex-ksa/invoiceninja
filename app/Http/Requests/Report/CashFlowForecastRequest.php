<?php
namespace App\Http\Requests\Report;
use App\Http\Requests\Request;
class CashFlowForecastRequest extends Request
{
    public function authorize(): bool {$user=auth()->user();return app()->environment(['local','testing'])&&config('ninja.cash_flow_forecast_enabled')===true&&$user!==null&&!$user->company()->is_disabled&&($user->isAdmin()||$user->hasPermission('view_reports'));}
    public function rules(): array {return ['start_date'=>['required','date_format:Y-m-d'],'end_date'=>['required','date_format:Y-m-d'],'scenarios'=>['required','array','min:1','max:5'],'company_id'=>['prohibited'],'user_id'=>['prohibited'],'send_email'=>['prohibited']];}
}
