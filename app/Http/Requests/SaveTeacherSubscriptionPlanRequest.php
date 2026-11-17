<?php

namespace App\Http\Requests;

use App\Models\TeacherSubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTeacherSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        return [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('teacher_subscription_plans', 'code')]
                : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(config('teacher_subscriptions.supported_currencies', ['PKR']))],
            'billing_interval' => [$creating ? 'required' : 'sometimes', Rule::in(['monthly', 'yearly', 'custom', 'lifetime'])],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:36500'],
            'max_active_classes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'is_free' => [$creating ? 'required' : 'sometimes', 'boolean'],
            'is_active' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
            'features' => [$creating ? 'required' : 'sometimes', 'array'],
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
            'is_default' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->all();
            $creating = $this->isMethod('post');
            $plan = $creating ? null : TeacherSubscriptionPlan::find($this->route('id'));
            $mutable = ['name', 'description', 'price', 'currency', 'billing_interval', 'duration_days', 'max_active_classes', 'is_free', 'features'];

            if (!$creating && $plan && !array_intersect(array_keys($data), $mutable)) {
                $validator->errors()->add('plan', 'At least one plan field must be supplied.');
            }

            $effective = array_merge($plan?->only(['price', 'billing_interval', 'duration_days', 'is_free']) ?? [], $data);
            if (array_key_exists('price', $effective) && array_key_exists('is_free', $effective)) {
                $isFree = filter_var($effective['is_free'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $price = is_numeric($effective['price']) ? (float) $effective['price'] : null;
                if ($isFree === true && $price !== null && $price != 0.0) $validator->errors()->add('price', 'Free plans must have a zero price.');
                if ($isFree === false && $price !== null && $price <= 0) $validator->errors()->add('price', 'Paid plans must have a positive price.');
            }

            $interval = $effective['billing_interval'] ?? null;
            $duration = $effective['duration_days'] ?? null;
            if ($interval === 'custom' && $duration === null) $validator->errors()->add('duration_days', 'Custom plans require a positive duration in days.');
            if ($creating && $interval !== null && $interval !== 'custom' && $duration !== null) $validator->errors()->add('duration_days', 'Only custom plans may specify duration_days.');
            if (!$creating && array_key_exists('duration_days', $data) && $data['duration_days'] !== null && $interval !== 'custom') {
                $validator->errors()->add('duration_days', 'Only custom plans may specify duration_days.');
            }

            if (array_key_exists('features', $data) && is_array($data['features'])) {
                foreach ($data['features'] as $key => $enabled) {
                    if (!in_array((string) $key, TeacherSubscriptionPlan::FEATURE_KEYS, true)) {
                        $validator->errors()->add('features', "Unsupported teacher feature key: {$key}.");
                    } elseif (!in_array($enabled, [true, false, 0, 1, '0', '1'], true)) {
                        $validator->errors()->add("features.{$key}", 'Feature values must be boolean.');
                    }
                }
            }
        });
    }
}
