<?php

namespace App\Http\Requests;

use App\Models\QuestionReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitQuestionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web_api') !== null || $this->user('sanctum') !== null || $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'exists:exam_question_tbl,id'],
            'report_category' => ['required', 'string', Rule::in(QuestionReport::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'portal' => ['nullable', 'string', Rule::in([QuestionReport::PORTAL_STUDENT, QuestionReport::PORTAL_TEACHER])],
            'page_context' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'question_id.required' => 'The question ID is required.',
            'question_id.exists' => 'The specified question does not exist in the question bank.',
            'report_category.required' => 'A valid report category must be selected.',
            'report_category.in' => 'The selected report category is invalid.',
            'description.max' => 'The description cannot exceed 2000 characters.',
        ];
    }
}
