<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignStudentGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_id'  => ['required', 'integer', 'exists:freemium_campaigns,id'],
            'user_id'      => ['required', 'integer', 'exists:web_users,id'],
            'grant_type'   => ['sometimes', Rule::in(['direct', 'voucher', 'pilot'])],
            'voucher_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expires_at'   => ['sometimes', 'nullable', 'date'],
        ];
    }
}
