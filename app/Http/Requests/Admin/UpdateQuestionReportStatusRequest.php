<?php

namespace App\Http\Requests\Admin;

use App\Models\QuestionReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null || $this->user('sanctum') !== null || $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    QuestionReport::STATUS_PENDING,
                    QuestionReport::STATUS_UNDER_REVIEW,
                    QuestionReport::STATUS_RESOLVED,
                    QuestionReport::STATUS_REJECTED,
                ]),
            ],
            'internal_note' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
