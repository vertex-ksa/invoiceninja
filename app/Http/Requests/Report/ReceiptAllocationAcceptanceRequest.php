<?php
namespace App\Http\Requests\Report;
class ReceiptAllocationAcceptanceRequest extends ReceiptAllocationIntentRequest
{
    public function rules(): array {return [...parent::rules(),
        'operation_key'=>['required','string','regex:/^[A-Za-z0-9_-]{16,64}$/D'],
        'expected_financial_beforeimage'=>['required','string','regex:/^[a-f0-9]{64}$/D'],
    ];}
}
