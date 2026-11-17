<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherSubscriptionAssignmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('teacher_subscription_plans', 'id')->where('is_active', true)],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'admin_note' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
