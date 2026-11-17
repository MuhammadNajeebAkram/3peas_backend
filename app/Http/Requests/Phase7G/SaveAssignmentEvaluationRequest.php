<?php

namespace App\Http\Requests\Phase7G;

use Illuminate\Foundation\Http\FormRequest;

class SaveAssignmentEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:draft,release,reopen,correct,finalize_zero'],
            'obtained_marks' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'feedback_text' => ['nullable', 'string', 'max:20000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
