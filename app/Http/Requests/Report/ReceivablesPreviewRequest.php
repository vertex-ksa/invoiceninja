<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\Request;

class ReceivablesPreviewRequest extends Request
{
    public function authorize(): bool
    {
        $user = auth()->user();
        // Local synthetic capability only; independently enforce object permissions in adapter.
        return app()->environment(['local', 'testing'])
            && config('ninja.receivables_preview_enabled') === true
            && $user !== null
            && !$user->company()->is_disabled
            && ($user->isAdmin() || $user->hasPermission('view_reports'));
    }

    public function rules(): array
    {
        return [
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'minimum_overdue_days' => ['required', 'integer', 'min:1', 'max:3650'],
            // Scope comes exclusively from the current token/company. This endpoint cannot send.
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'send_email' => ['prohibited'],
        ];
    }
}
