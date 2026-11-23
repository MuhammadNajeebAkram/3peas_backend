<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'window_type' => [
                'sometimes',
                'required',
                Rule::in(['daily', 'weekly', 'monthly', 'annual', 'campaign', 'lifetime']),
            ],
            'max_tests' => [
                'sometimes',
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
            'campaign_id' => ['prohibited'],
            'exam_type'   => ['prohibited'],
        ];
    }
}
