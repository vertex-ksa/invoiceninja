<?php
namespace App\Http\Requests\Report;
use App\Http\Requests\Request;
use Illuminate\Validation\Validator;
class ReceiptAllocationIntentRequest extends Request
{
    public function authorize(): bool { return auth()->user() !== null; }
    public function rules(): array { return [
        'payment_id'=>['required','string','max:128','regex:/^[A-Za-z0-9]+$/D'],
        'invoice_id'=>['required','string','max:128','regex:/^[A-Za-z0-9]+$/D'],
        'amount_minor'=>['required','string','regex:/^[1-9][0-9]{0,13}$/D'],
        'expected_native_actor_id'=>['required','string','max:128','regex:/^[A-Za-z0-9]+$/D'],
    ]; }
    public function withValidator(Validator $validator): void { $validator->after(function(Validator $v){
        if(array_diff(array_keys($this->all()),array_keys($this->rules())))$v->errors()->add('input','Only allocation operation fields are accepted.');
    }); }
}
