<?php
namespace App\Http\Controllers\Reports;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Report\CashFlowScenarioRunRequest;
use App\Services\Receivables\CashFlowScenarioRuns;
use InvalidArgumentException;
class CashFlowScenarioRunController extends BaseController {
    public function save(CashFlowScenarioRunRequest $request,CashFlowScenarioRuns $service){return $this->run($request,$service,true);}
    public function read(CashFlowScenarioRunRequest $request,CashFlowScenarioRuns $service){return $this->run($request,$service,false);}
    private function run($request,$service,bool $save){try{$result=$save?$service->save(auth()->user(),$request->validated()):$service->read(auth()->user(),$request->validated());}catch(InvalidArgumentException){return response()->json(['message'=>'Forecast record input or native scope cannot be represented safely.'],422)->header('Cache-Control','no-store');}return response()->json($result)->header('Cache-Control','no-store');}
}
