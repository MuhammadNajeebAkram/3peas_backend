<?php

namespace App\Http\Requests\Student\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluateAccessRequest extends FormRequest
{
    public const SUPPORTED_DIMENSIONS = [
        'perm_mcqs',
        'perm_short_questions',
        'perm_long_questions',
        'perm_exercise_questions',
        'perm_supplementary_questions',
        'perm_answers',
        'perm_explanations',
        'perm_topic_contents',
        'perm_past_papers',
        'perm_model_papers',
        'perm_view',
        'perm_download',
        'perm_solutions',
        'perm_video',
        'perm_slos',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dimension'           => ['sometimes', 'string', Rule::in(self::SUPPORTED_DIMENSIONS)],
            'exam_type'           => ['sometimes', 'nullable', 'string', Rule::in(['practice', 'formal_test', 'mock'])],
            'offered_program_id'  => ['sometimes', 'nullable', 'integer', 'min:1'],
            'curriculum_board_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'class_id'            => ['sometimes', 'nullable', 'integer', 'min:1'],
            'subject_id'          => ['sometimes', 'nullable', 'integer', 'min:1'],
            'book_id'             => ['sometimes', 'nullable', 'integer', 'min:1'],
            'chapter_id'          => ['sometimes', 'nullable', 'integer', 'min:1'],
            'topic_id'            => ['sometimes', 'nullable', 'integer', 'min:1'],
            'question_id'         => ['sometimes', 'nullable', 'integer', 'min:1'],
            'model_paper_id'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            'past_paper_id'       => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
