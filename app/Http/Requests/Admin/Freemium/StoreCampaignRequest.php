<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string', 'max:5000'],
            'status'               => ['sometimes', Rule::in(['draft', 'active', 'paused', 'archived'])],
            'is_stackable'         => ['sometimes', 'boolean'],
            'priority'             => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'start_at'             => ['required', 'date'],
            'end_at'               => ['required', 'date', 'after:start_at'],
            'eligibility_criteria' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
