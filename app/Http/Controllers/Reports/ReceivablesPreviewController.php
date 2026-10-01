<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Report\ReceivablesPreviewRequest;
use App\Services\Receivables\NativeCollectionPreview;
use InvalidArgumentException;

class ReceivablesPreviewController extends BaseController
{
    public function __invoke(ReceivablesPreviewRequest $request, NativeCollectionPreview $preview)
    {
        $input = $request->validated();
        try {
            $result = $preview->build(auth()->user(), $input['as_of_date'], (int) $input['minimum_overdue_days']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => 'Native snapshot cannot be represented safely; manual source review required.'], 422)
                ->header('Cache-Control', 'no-store');
        }
        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
