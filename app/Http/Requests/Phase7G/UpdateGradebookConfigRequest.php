<?php

namespace App\Http\Requests\Phase7G;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGradebookConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'retake_score_policy' => ['required', 'string', 'in:highest,latest,average'],
            'weighting_enabled' => ['required', 'boolean'],
            'study_session_id' => ['nullable', 'integer', 'min:1'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.category_key' => ['required', 'string', 'in:mcq_test,assignment'],
            'categories.*.label' => ['nullable', 'string', 'max:80'],
            'categories.*.weight_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'categories.*.is_included' => ['required', 'boolean'],
            'categories.*.exclude_ungraded' => ['sometimes', 'boolean'],
        ];
    }
}
