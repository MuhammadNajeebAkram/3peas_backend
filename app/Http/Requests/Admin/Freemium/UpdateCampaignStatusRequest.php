<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['draft', 'active', 'paused', 'archived'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
