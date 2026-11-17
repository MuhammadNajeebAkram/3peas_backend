<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class SaveTeacherClassTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'selection_method' => ['required', 'string', 'in:manual,automatic,mixed'],
            'selection_criteria' => ['nullable', 'array'],
            'marks_per_question' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'negative_marks_per_wrong' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'language_mode' => ['nullable', 'string', 'in:english,urdu,bilingual'],
            'questions' => ['required', 'array', 'min:1', 'max:150'],
            'questions.*.question_id' => ['required', 'integer', 'min:1', 'distinct'],
            'questions.*.marks' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'questions.*.negative_marks' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'questions.required' => 'Please select at least one MCQ for this test.',
            'questions.min' => 'Please select at least one MCQ for this test.',
            'questions.*.question_id.distinct' => 'Duplicate MCQ questions are not allowed in the same test.',
            'marks_per_question.gt' => 'Marks per question must be greater than zero.',
            'questions.*.marks.gt' => 'Marks per question must be greater than zero.',
        ];
    }
}
