<?php
declare(strict_types=1);
namespace App\Services\Receivables;
use App\Models\{Company,CompanyToken,CompanyUser,Invoice,User};
use App\Utils\TruthSource;
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Facades\{DB,Gate};
use InvalidArgumentException;

/** Immutable private planning records. No financial posting or dispatch authority. */
final class CashFlowScenarioRuns
{
    use MakesHash;
    public function save(User $actor,array $input): array {
        $this->input($input,true);
        return $this->authorised($actor,$input,function($user,$company)use($input){
            abort_unless($user->isAdmin()||$user->hasPermission('edit_invoice'),403);
            $hash=hash('sha256',json_encode($this->canonical([$input['forecast_input'],$input['expected_source_snapshot_sha256']]),JSON_THROW_ON_ERROR));
            $existing=DB::table('cash_flow_scenario_runs')->where('company_id',$company->id)->where('native_user_id',$user->id)->where('operation_key',$input['operation_key'])->lockForUpdate()->first();
            if($existing){abort_unless(hash_equals($existing->input_sha256,$hash),409);return $this->receipt($existing,$user,$company,true);}
            abort_if(DB::table('cash_flow_scenario_runs')->where('company_id',$company->id)->where('native_user_id',$user->id)->count()>=1000,413);
            $report=(new NativeCashFlowForecast())->build($user,$input['forecast_input']);
            abort_unless(hash_equals($input['expected_source_snapshot_sha256'],$report['source_snapshot_sha256']),409);
            $publicInput=$input['forecast_input'];foreach($publicInput['scenarios']as&$scenario){$scenario['opening_minor_by_currency']=(object)$scenario['opening_minor_by_currency'];}unset($scenario);
            // JSON objects preserve empty currency maps at the public boundary.
            $report['currency_precisions']=(object)$report['currency_precisions'];
            foreach($report['scenarios']as&$scenario){$scenario['totals_by_currency']=(object)$scenario['totals_by_currency'];$scenario['assumptions']['opening_minor_by_currency']=(object)$scenario['assumptions']['opening_minor_by_currency'];}unset($scenario);
            $id=DB::table('cash_flow_scenario_runs')->insertGetId(['company_id'=>$company->id,'native_user_id'=>$user->id,'operation_key'=>$input['operation_key'],'input_sha256'=>$hash,'input_json'=>json_encode($publicInput,JSON_THROW_ON_ERROR),'report_json'=>json_encode($report,JSON_THROW_ON_ERROR),'created_at'=>now('UTC')->timestamp]);
            return $this->receipt(DB::table('cash_flow_scenario_runs')->find($id),$user,$company,false);
        });
    }
    public function read(User $actor,array $input): array {
        $this->input($input,false);
        return $this->authorised($actor,$input,function($user,$company)use($input){
            $record=DB::table('cash_flow_scenario_runs')->where('company_id',$company->id)->where('native_user_id',$user->id)->where('operation_key',$input['operation_key'])->first();
            return ['read_only'=>true,'write_authority'=>'NONE','state'=>$record?'RECORDED':'NOT_FOUND','operation_key'=>$input['operation_key'],'company_id'=>(string)$company->id,'native_actor_id'=>$user->hashed_id,'automatic_replay_allowed'=>false,'not_found_semantics'=>'absence_of_record_not_permission_to_retry','receipt'=>$record?$this->receipt($record,$user,$company,false):null];
        });
    }
    private function receipt(object $record,User $user,Company $company,bool $replayed): array {
        $report=json_decode($record->report_json,true,512,JSON_THROW_ON_ERROR);$ids=[];
        foreach($report['scenarios']as$scenario){foreach([...$scenario['included'],...$scenario['excluded']]as$row)$ids[$row['invoice_id']]=true;}
        foreach(array_keys($ids)as$id){$numeric=$this->decodePrimaryKey($id,true);$invoice=Invoice::withTrashed()->where('company_id',$company->id)->find($numeric);abort_unless($invoice&&Gate::forUser($user)->allows('view',$invoice),403);}
        // Decode once more as objects to retain empty map shapes in immutable JSON.
        $stored=json_decode($record->report_json,false,512,JSON_THROW_ON_ERROR);
        return ['state'=>'RECORDED','run_id'=>(string)$record->id,'operation_key'=>$record->operation_key,'company_id'=>(string)$company->id,'native_actor_id'=>$user->hashed_id,'input_sha256'=>$record->input_sha256,'replayed'=>$replayed,'record_semantics'=>'immutable_private_planning_snapshot_not_current_cash_or_approved_policy','write_authority'=>'PLANNING_RECORD_ONLY','report'=>$stored,'forecast_input'=>json_decode($record->input_json,false,512,JSON_THROW_ON_ERROR),'created_at'=>gmdate('Y-m-d\TH:i:s\Z',$record->created_at)];
    }
    private function input(array $input,bool $save): void {$expected=['operation_key','expected_native_actor_id'];if($save){$expected[]='forecast_input';$expected[]='expected_source_snapshot_sha256';}$keys=array_keys($input);sort($keys);sort($expected);if($keys!==$expected||!is_string($input['operation_key'])||!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$input['operation_key'])||!is_string($input['expected_native_actor_id'])||!preg_match('/^[A-Za-z0-9]{1,128}$/D',$input['expected_native_actor_id'])||($save&&(!is_array($input['forecast_input'])||!is_string($input['expected_source_snapshot_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$input['expected_source_snapshot_sha256'])))){throw new InvalidArgumentException('Invalid forecast operation.');}}
    private function canonical(mixed $value): mixed {if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);foreach($value as&$item)$item=$this->canonical($item);return $value;}
    private function authorised(User $actor,array $input,callable $action): array {
        abort_unless(app()->environment(['local','testing'])&&config('ninja.cash_flow_forecast_enabled')===true&&!config('ninja.db.multi_db_enabled'),403);abort_unless($actor->hashed_id===$input['expected_native_actor_id'],403);
        $truth=app(TruthSource::class);$saved=[$truth->company,$truth->user,$truth->company_user,$truth->company_token];$previous=auth()->user();
        try{return DB::transaction(function()use($actor,$action){
            $company=Company::query()->where('id',$actor->companyId())->lockForUpdate()->first();$user=User::query()->where('id',$actor->id)->where('is_deleted',false)->lockForUpdate()->first();
            abort_unless($company&&$user&&!$company->is_disabled&&$company->db===config('database.default'),403);
            $token=CompanyToken::query()->where('id',$actor->token()?->id)->where('company_id',$company->id)->where('user_id',$user->id)->where('is_deleted',false)->where('token',request()->header('X-API-TOKEN'))->lockForUpdate()->first();
            $cu=CompanyUser::query()->where('company_id',$company->id)->where('user_id',$user->id)->lockForUpdate()->first();
            abort_unless($token&&$cu&&!$cu->is_locked&&(string)$user->account_id===(string)$company->account_id&&(string)$token->account_id===(string)$company->account_id&&(string)$cu->account_id===(string)$company->account_id,403);
            $token->setRelation('cu',$cu);$user->setCompany($company);auth()->setUser($user);app(TruthSource::class)->setCompany($company)->setUser($user)->setCompanyUser($cu)->setCompanyToken($token);
            abort_unless($user->isAdmin()||$user->hasPermission('view_reports'),403);return $action($user,$company);
        },1);}finally{app(TruthSource::class)->setCompany($saved[0])->setUser($saved[1])->setCompanyUser($saved[2])->setCompanyToken($saved[3]);if($previous)auth()->setUser($previous);else auth()->forgetUser();}
    }
}
