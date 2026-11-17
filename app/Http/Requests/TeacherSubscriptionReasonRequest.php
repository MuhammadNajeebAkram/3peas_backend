<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeacherSubscriptionReasonRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['admin_note' => ['required', 'string', 'min:1', 'max:1000']]; }
}
