<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_id' => [
                'required',
                'integer',
                'exists:freemium_campaigns,id',
            ],
            'exam_type' => [
                'required',
                Rule::in(['practice', 'formal_test', 'mock']),
            ],
            'window_type' => [
                'required',
                Rule::in(['daily', 'weekly', 'monthly', 'annual', 'campaign', 'lifetime']),
            ],
            'max_tests' => [
                'nullable',
                'integer',
                'min:-1',
            ],
            'allow_retakes' => [
                'sometimes',
                'boolean',
            ],
            'max_attempts_per_test' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],
            'time_limit_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }
}
