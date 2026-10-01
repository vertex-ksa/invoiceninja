<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Report\RemittancePreviewRequest;
use App\Services\Receivables\NativeReceiptAllocationPreview;
use InvalidArgumentException;

class RemittancePreviewController extends BaseController
{
    public function __invoke(RemittancePreviewRequest $request, NativeReceiptAllocationPreview $preview)
    {
        try {
            $result = $preview->build(auth()->user(), $request->validated()['payment_id']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => 'Native receipt cannot be represented safely; manual source review required.'], 422)
                ->header('Cache-Control', 'no-store');
        }
        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
