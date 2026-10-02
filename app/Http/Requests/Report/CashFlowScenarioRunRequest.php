<?php
namespace App\Http\Requests\Report;
use App\Http\Requests\Request;
class CashFlowScenarioRunRequest extends Request {
    public function authorize(): bool {return auth()->user()!==null&&app()->environment(['local','testing'])&&config('ninja.cash_flow_forecast_enabled')===true;}
    public function rules(): array {return ['operation_key'=>['required','string','regex:/^[A-Za-z0-9_-]{16,64}$/D'],'expected_native_actor_id'=>['required','string','regex:/^[A-Za-z0-9]{1,128}$/D'],'forecast_input'=>['sometimes','array'],'expected_source_snapshot_sha256'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D'],'company_id'=>['prohibited'],'user_id'=>['prohibited'],'send_email'=>['prohibited']];}
}
