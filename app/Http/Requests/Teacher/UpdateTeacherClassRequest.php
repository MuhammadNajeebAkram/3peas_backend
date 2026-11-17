<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role/approval is enforced by EnsureTeacherActive; ownership by the controller query.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('name') && is_string($this->name)) {
            $merge['name'] = trim($this->name);
        }
        if ($this->has('section')) {
            $merge['section'] = is_string($this->section) ? trim($this->section) : ($this->section ?? '');
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'class_id' => ['sometimes', 'required', 'integer', Rule::exists('class_tbl', 'id')->where('activate', 1)],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subject_tbl', 'id')->where('activate', 1)],
            'curriculum_board_id' => ['sometimes', 'required', 'integer', Rule::exists('curriculum_board_tbl', 'id')->where('activate', 1)],
            'section' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[\pL\pN\s\-]*$/u'],
            'requires_approval' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'teacher_profile_id' => ['prohibited'],
            'class_code' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'section.regex' => 'Section may only contain letters, numbers, spaces and hyphens.',
        ];
    }
}
