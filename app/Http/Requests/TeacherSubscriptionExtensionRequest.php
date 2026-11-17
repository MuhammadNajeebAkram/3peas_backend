<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeacherSubscriptionExtensionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'additional_days' => ['required', 'integer', 'min:1', 'max:36500'],
            'admin_note' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
