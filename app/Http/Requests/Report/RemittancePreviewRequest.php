<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\Request;
use Illuminate\Validation\Validator;

class RemittancePreviewRequest extends Request
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return app()->environment(['local', 'testing'])
            && config('ninja.remittance_preview_enabled') === true
            && $user !== null && !$user->company()->is_disabled
            && ($user->isAdmin() || $user->hasPermission('view_reports'));
    }

    public function rules(): array
    {
        return ['payment_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/D']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['payment_id'])) {
                $validator->errors()->add('input', 'Only native receipt identity is accepted.');
            }
        });
    }
}
