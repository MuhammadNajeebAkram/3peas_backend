<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['sometimes', 'required', 'string', 'max:255'],
            'description'          => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_stackable'         => ['sometimes', 'boolean'],
            'priority'             => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'start_at'             => ['sometimes', 'required', 'date'],
            'end_at'               => ['sometimes', 'required', 'date'],
            'eligibility_criteria' => ['sometimes', 'nullable', 'array'],
            'status'               => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => 'Campaign status cannot be modified via standard update. Use the dedicated status transition endpoint.',
        ];
    }
}
