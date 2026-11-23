<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessRuleRequest extends FormRequest
{
    public const PERMISSION_FIELDS = [
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
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scopeValues = ['allow', 'deny', 'inherit'];

        $rules = [
            'target_status'            => ['sometimes', Rule::in(['active', 'orphaned'])],
            'rule_priority'            => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'academic_target_snapshot' => ['sometimes', 'nullable', 'array'],
            'campaign_id'              => ['prohibited'],
            'target_scope'             => ['prohibited'],
            'curriculum_board_id'      => ['prohibited'],
            'class_id'                 => ['prohibited'],
            'subject_id'               => ['prohibited'],
            'book_id'                  => ['prohibited'],
            'chapter_id'               => ['prohibited'],
            'topic_id'                 => ['prohibited'],
            'question_id'              => ['prohibited'],
            'model_paper_id'           => ['prohibited'],
            'past_paper_id'            => ['prohibited'],
        ];

        foreach (self::PERMISSION_FIELDS as $field) {
            $rules[$field] = ['sometimes', Rule::in($scopeValues)];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'campaign_id.prohibited'  => 'Campaign association cannot be altered on an existing rule.',
            'target_scope.prohibited' => 'Target scope hierarchy level cannot be altered on an existing rule.',
        ];
    }
}
