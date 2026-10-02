<?php
namespace App\Http\Controllers\Reports;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Report\CashFlowForecastRequest;
use App\Services\Receivables\NativeCashFlowForecast;
use InvalidArgumentException;
class CashFlowForecastController extends BaseController
{
    public function __invoke(CashFlowForecastRequest $request,NativeCashFlowForecast $forecast)
    {
        try {$result=$forecast->build(auth()->user(),$request->only(['start_date','end_date','scenarios']));}
        catch(InvalidArgumentException $exception){return response()->json(['message'=>'Native forecast or assumptions cannot be represented safely.'],422)->header('Cache-Control','no-store');}
        $result['currency_precisions']=(object)$result['currency_precisions'];
        foreach($result['scenarios']as&$scenario){$scenario['totals_by_currency']=(object)$scenario['totals_by_currency'];$scenario['assumptions']['opening_minor_by_currency']=(object)$scenario['assumptions']['opening_minor_by_currency'];}unset($scenario);
        return response()->json($result)->header('Cache-Control','no-store');
    }
}
