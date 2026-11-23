<?php

namespace App\Http\Requests\Admin\Freemium;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessRuleRequest extends FormRequest
{
    public const TARGET_SCOPES = [
        'global',
        'curriculum_board',
        'class',
        'subject',
        'book',
        'chapter',
        'topic',
        'question',
        'model_paper',
        'past_paper',
    ];

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
            'campaign_id'         => ['required', 'integer', 'exists:freemium_campaigns,id'],
            'target_scope'        => ['required', Rule::in(self::TARGET_SCOPES)],
            'target_status'       => ['sometimes', Rule::in(['active', 'orphaned'])],
            'curriculum_board_id' => ['sometimes', 'nullable', 'integer'],
            'class_id'            => ['sometimes', 'nullable', 'integer'],
            'subject_id'          => ['sometimes', 'nullable', 'integer'],
            'book_id'             => ['sometimes', 'nullable', 'integer'],
            'chapter_id'          => ['sometimes', 'nullable', 'integer'],
            'topic_id'            => ['sometimes', 'nullable', 'integer'],
            'question_id'         => ['sometimes', 'nullable', 'integer'],
            'model_paper_id'      => ['sometimes', 'nullable', 'integer'],
            'past_paper_id'       => ['sometimes', 'nullable', 'integer'],
            'rule_priority'       => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'academic_target_snapshot' => ['sometimes', 'nullable', 'array'],
        ];

        foreach (self::PERMISSION_FIELDS as $field) {
            $rules[$field] = ['sometimes', Rule::in($scopeValues)];
        }

        return $rules;
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $scope = $this->input('target_scope');

            $scopeFieldMap = [
                'curriculum_board' => 'curriculum_board_id',
                'class'            => 'class_id',
                'subject'          => 'subject_id',
                'book'             => 'book_id',
                'chapter'          => 'chapter_id',
                'topic'            => 'topic_id',
                'question'         => 'question_id',
                'model_paper'      => 'model_paper_id',
                'past_paper'       => 'past_paper_id',
            ];

            if ($scope && $scope !== 'global' && isset($scopeFieldMap[$scope])) {
                $requiredField = $scopeFieldMap[$scope];
                if (empty($this->input($requiredField))) {
                    $validator->errors()->add(
                        $requiredField,
                        "The {$requiredField} is required when target_scope is '{$scope}'."
                    );
                }
            }
        });
    }
}
