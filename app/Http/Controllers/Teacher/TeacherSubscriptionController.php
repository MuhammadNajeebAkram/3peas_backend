<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherSubscriptionController extends Controller
{
    public function __construct(private TeacherSubscriptionService $subscriptions) {}

    public function show(Request $request): JsonResponse
    {
        $subscription = $this->current($request);
        return response()->json([
            'success' => true,
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'expires_at' => $subscription->expires_at?->toIso8601String(),
                'plan' => [
                    'id' => $subscription->plan->id,
                    'code' => $subscription->plan->code,
                    'name' => $subscription->plan->name,
                    'price' => $subscription->plan->price,
                    'currency' => $subscription->plan->currency,
                    'billing_interval' => $subscription->plan->billing_interval,
                    'max_active_classes' => $subscription->plan->max_active_classes,
                ],
            ],
        ]);
    }

    public function features(Request $request): JsonResponse
    {
        $subscription = $this->current($request);
        $features = collect(\App\Models\TeacherSubscriptionPlan::FEATURE_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => (bool) $subscription->plan->features->firstWhere('feature_key', $key)?->is_enabled]);
        return response()->json(['success' => true, 'features' => $features]);
    }

    public function usage(Request $request): JsonResponse
    {
        $profile = $request->attributes->get('teacher_profile');
        $subscription = $this->current($request);
        return response()->json([
            'success' => true,
            'plan' => ['code' => $subscription->plan->code, 'name' => $subscription->plan->name],
            'usage' => $this->subscriptions->classUsage($profile->id),
        ]);
    }

    private function current(Request $request)
    {
        $profile = $request->attributes->get('teacher_profile');
        $subscription = $this->subscriptions->getCurrentSubscription($profile->id);
        abort_unless($subscription, 403, 'No active teacher subscription is assigned.');
        return $subscription;
    }
}
