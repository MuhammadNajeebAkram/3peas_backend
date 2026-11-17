<?php

namespace App\Http\Requests\Phase7G;

use Illuminate\Foundation\Http\FormRequest;

class ToggleAssessmentExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assessment_type' => ['required', 'string', 'in:mcq_test,assignment'],
            'source_id' => ['required', 'integer', 'min:1'],
            'is_excluded' => ['required', 'boolean'],
        ];
    }
}
