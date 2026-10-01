<?php
namespace App\Http\Controllers\Reports;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Report\{ReceiptAllocationAcceptanceRequest,ReceiptAllocationIntentRequest};
use App\Services\Receivables\AcceptReceiptAllocation;
use InvalidArgumentException;
class ReceiptAllocationController extends BaseController
{
    public function intent(ReceiptAllocationIntentRequest $request,AcceptReceiptAllocation $service){
        try{$result=$service->intent(auth()->user(),$request->validated());}
        catch(InvalidArgumentException){return response()->json(['message'=>'Current native facts are outside this bounded allocation operation.'],422)->header('Cache-Control','no-store');}
        return response()->json($result)->header('Cache-Control','no-store');
    }
    public function accept(ReceiptAllocationAcceptanceRequest $request,AcceptReceiptAllocation $service){
        try{$result=$service->accept(auth()->user(),$request->validated());}
        catch(InvalidArgumentException){return response()->json(['message'=>'Current native facts are outside this bounded allocation operation.'],422)->header('Cache-Control','no-store');}
        return response()->json($result)->header('Cache-Control','no-store');
    }
}
