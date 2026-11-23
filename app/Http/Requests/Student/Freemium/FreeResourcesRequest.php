<?php

namespace App\Http\Requests\Student\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FreeResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_id'            => ['sometimes', 'nullable', 'integer', 'min:1'],
            'curriculum_board_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'subject_id'          => ['sometimes', 'nullable', 'integer', 'min:1'],
            'dimension'           => ['sometimes', 'string', Rule::in(EvaluateAccessRequest::SUPPORTED_DIMENSIONS)],
            'per_page'            => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page'                => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
