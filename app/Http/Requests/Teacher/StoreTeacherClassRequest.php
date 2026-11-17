<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role/approval is enforced by the EnsureTeacherActive middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'section' => is_string($this->section) ? trim($this->section) : ($this->section ?? ''),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'class_id' => ['required', 'integer', Rule::exists('class_tbl', 'id')->where('activate', 1)],
            'subject_id' => ['required', 'integer', Rule::exists('subject_tbl', 'id')->where('activate', 1)],
            'curriculum_board_id' => ['required', 'integer', Rule::exists('curriculum_board_tbl', 'id')->where('activate', 1)],
            'section' => ['nullable', 'string', 'max:20', 'regex:/^[\pL\pN\s\-]*$/u'],
            'requires_approval' => ['sometimes', 'boolean'],
            // Never accept ownership or code from the client.
            'teacher_profile_id' => ['prohibited'],
            'class_code' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'section.regex' => 'Section may only contain letters, numbers, spaces and hyphens.',
            'class_id.exists' => 'Please select a valid class.',
            'subject_id.exists' => 'Please select a valid subject.',
            'curriculum_board_id.exists' => 'Please select a valid curriculum board.',
        ];
    }
}
