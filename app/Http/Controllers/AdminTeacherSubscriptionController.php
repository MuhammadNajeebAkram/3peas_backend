<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeacherSubscriptionAssignmentRequest;
use App\Http\Requests\TeacherSubscriptionExtensionRequest;
use App\Http\Requests\TeacherSubscriptionReasonRequest;
use App\Http\Services\TeacherSubscriptionService;
use App\Models\TeacherClass;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\TeacherSubscriptionHistory;
use App\Models\TeacherSubscriptionPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminTeacherSubscriptionController extends Controller
{
    public function __construct(private TeacherSubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:160'],
            'q' => ['sometimes', 'string', 'max:160'],
            'plan_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:active,scheduled,expired,suspended,cancelled'],
            'assignment_type' => ['sometimes', 'in:automatic_free,manual_admin,paid'],
            'expiring_within_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', 'in:id,starts_at,expires_at,status,created_at'],
            'sort_direction' => ['sometimes', 'in:asc,desc'],
        ]);

        $query = TeacherSubscription::query()
            ->with(['teacherProfile.webUser:id,name,email,role,status', 'plan.features'])
            ->addSelect(['active_class_count' => TeacherClass::query()->selectRaw('COUNT(*)')
                ->whereColumn('teacher_classes.teacher_profile_id', 'teacher_subscriptions.teacher_profile_id')
                ->where('teacher_classes.is_active', true)]);

        $search = $filters['search'] ?? $filters['q'] ?? null;
        if ($search !== null) {
            $query->whereHas('teacherProfile', fn (Builder $profile) => $profile
                ->where('teacher_code', 'like', '%'.$search.'%')
                ->orWhereHas('webUser', fn (Builder $user) => $user
                    ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
        }
        if (isset($filters['plan_id'])) $query->where('teacher_subscription_plan_id', $filters['plan_id']);
        if (isset($filters['assignment_type'])) $query->where('assignment_type', $filters['assignment_type']);
        if (isset($filters['status'])) $this->applyStatusFilter($query, $filters['status']);
        if (isset($filters['expiring_within_days'])) {
            $query->where('status', 'active')->where('starts_at', '<=', now())
                ->whereBetween('expires_at', [now(), now()->addDays($filters['expiring_within_days'])]);
        }

        $sort = $filters['sort_by'] ?? 'id';
        $rows = $query->orderBy($sort, $filters['sort_direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 20)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn (TeacherSubscription $subscription) => $this->present($subscription))->values(),
            'pagination' => $this->pagination($rows),
        ]);
    }

    public function show(int $teacherProfileId): JsonResponse
    {
        $teacher = TeacherProfile::with('webUser:id,name,email,role,status')->findOrFail($teacherProfileId);
        $current = $this->subscriptions->getCurrentSubscription($teacherProfileId);
        $classUsage = $this->subscriptions->classUsage($teacherProfileId);
        $history = TeacherSubscriptionHistory::with(['previousPlan:id,code,name', 'newPlan:id,code,name', 'assignedBy:id,name,email'])
            ->where('teacher_profile_id', $teacherProfileId)->orderByDesc('created_at')->limit(10)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'teacher' => $this->presentTeacher($teacher),
                'current_subscription' => $current ? $this->present($current) : null,
                'class_usage' => $classUsage,
                'feature_entitlements' => $this->featureMap($current?->plan),
                'recent_history' => $history->map(fn (TeacherSubscriptionHistory $entry) => $this->presentHistory($entry))->values(),
            ],
        ]);
    }

    public function history(Request $request, int $teacherProfileId): JsonResponse
    {
        TeacherProfile::findOrFail($teacherProfileId);
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $rows = TeacherSubscriptionHistory::query()
            ->with(['previousPlan:id,code,name', 'newPlan:id,code,name', 'assignedBy:id,name,email'])
            ->where('teacher_profile_id', $teacherProfileId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn (TeacherSubscriptionHistory $entry) => $this->presentHistory($entry))->values(),
            'pagination' => $this->pagination($rows),
        ]);
    }

    public function assign(TeacherSubscriptionAssignmentRequest $request, int $teacherProfileId): JsonResponse
    {
        $teacher = $this->eligibleTeacher($teacherProfileId);
        $data = $request->validated();
        $current = $this->subscriptions->getCurrentSubscription($teacher->id);
        if ($current && $current->teacher_subscription_plan_id === (int) $data['plan_id']) {
            throw new HttpResponseException(response()->json([
                'success' => false, 'code' => 'plan_already_assigned',
                'message' => 'The teacher already has this active plan.',
            ], 409));
        }

        try {
            $subscription = $this->subscriptions->changePlan($teacher->id, (int) $data['plan_id'], [
                'assignment_type' => 'manual_admin',
                'assigned_by' => (int) auth('api')->id(),
                'starts_at' => $data['starts_at'] ?? null,
                'admin_note' => $data['admin_note'],
                'reason' => $data['admin_note'],
                'event_type' => 'subscription_assigned',
            ]);
        } catch (RuntimeException $exception) {
            throw new HttpResponseException(response()->json([
                'success' => false, 'code' => 'subscription_transition_conflict', 'message' => $exception->getMessage(),
            ], 409));
        }

        return response()->json(['success' => true, 'data' => $this->present($subscription)], 201);
    }

    public function extend(TeacherSubscriptionExtensionRequest $request, int $subscriptionId): JsonResponse
    {
        $data = $request->validated();
        try {
            $subscription = $this->subscriptions->extend(
                $subscriptionId,
                (int) $data['additional_days'],
                (int) auth('api')->id(),
                $data['admin_note']
            );
        } catch (RuntimeException $exception) {
            throw new HttpResponseException(response()->json([
                'success' => false, 'code' => 'subscription_transition_conflict', 'message' => $exception->getMessage(),
            ], 409));
        }
        return response()->json(['success' => true, 'data' => $this->present($subscription)]);
    }

    public function suspend(TeacherSubscriptionReasonRequest $request, int $subscriptionId): JsonResponse
    {
        return $this->changeStatus($request, $subscriptionId, 'suspend');
    }

    public function reactivate(TeacherSubscriptionReasonRequest $request, int $subscriptionId): JsonResponse
    {
        $subscription = TeacherSubscription::with('teacherProfile.webUser')->findOrFail($subscriptionId);
        $this->assertEligibleTeacher($subscription->teacherProfile);
        return $this->changeStatus($request, $subscriptionId, 'reactivate');
    }

    public function cancel(TeacherSubscriptionReasonRequest $request, int $subscriptionId): JsonResponse
    {
        return $this->changeStatus($request, $subscriptionId, 'cancel');
    }

    private function changeStatus(TeacherSubscriptionReasonRequest $request, int $subscriptionId, string $operation): JsonResponse
    {
        $data = $request->validated();
        try {
            $subscription = match ($operation) {
                'suspend' => $this->subscriptions->suspend($subscriptionId, (int) auth('api')->id(), $data['admin_note']),
                'reactivate' => $this->subscriptions->reactivate($subscriptionId, (int) auth('api')->id(), $data['admin_note']),
                default => $this->subscriptions->cancel($subscriptionId, (int) auth('api')->id(), $data['admin_note']),
            };
        } catch (RuntimeException $exception) {
            throw new HttpResponseException(response()->json([
                'success' => false, 'code' => 'subscription_transition_conflict', 'message' => $exception->getMessage(),
            ], 409));
        }
        return response()->json(['success' => true, 'data' => $this->present($subscription)]);
    }

    private function eligibleTeacher(int $teacherProfileId): TeacherProfile
    {
        $teacher = TeacherProfile::with('webUser:id,name,email,role,status')->findOrFail($teacherProfileId);
        $this->assertEligibleTeacher($teacher);
        return $teacher;
    }

    private function assertEligibleTeacher(?TeacherProfile $teacher): void
    {
        if (!$teacher || $teacher->status !== 'active' || $teacher->webUser?->role !== 'teacher' || $teacher->webUser?->status !== 'active') {
            throw new HttpResponseException(response()->json([
                'success' => false, 'code' => 'teacher_not_eligible',
                'message' => 'A manual plan assignment or reactivation requires an active, approved teacher account.',
            ], 422));
        }
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $now = now();
        match ($status) {
            'active' => $query->where('status', 'active')->where('starts_at', '<=', $now)
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now)),
            'scheduled' => $query->where('status', 'active')->where('starts_at', '>', $now),
            'expired' => $query->where(fn (Builder $q) => $q->where('status', 'expired')
                ->orWhere(fn (Builder $active) => $active->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', $now))),
            default => $query->where('status', $status),
        };
    }

    private function present(TeacherSubscription $subscription): array
    {
        $subscription->loadMissing(['teacherProfile.webUser:id,name,email,role,status', 'plan.features']);
        $teacher = $subscription->teacherProfile;
        $current = $subscription->status === 'active' && $subscription->starts_at?->lte(now())
            && (!$subscription->expires_at || $subscription->expires_at->gt(now()))
            && $teacher?->status === 'active' && $teacher?->webUser?->status === 'active' && $teacher?->webUser?->role === 'teacher';
        $activeClasses = (int) ($subscription->active_class_count
            ?? TeacherClass::where('teacher_profile_id', $subscription->teacher_profile_id)->where('is_active', true)->count());
        $maxClasses = $subscription->plan?->max_active_classes;
        $effectiveStatus = $subscription->status;
        if ($effectiveStatus === 'active' && $subscription->expires_at && $subscription->expires_at->lte(now())) $effectiveStatus = 'expired';
        elseif ($effectiveStatus === 'active' && $subscription->starts_at?->isFuture()) $effectiveStatus = 'scheduled';

        return [
            'id' => $subscription->id,
            'teacher_profile_id' => $subscription->teacher_profile_id,
            'teacher' => $teacher ? $this->presentTeacher($teacher) : null,
            'plan' => $subscription->plan ? [
                'id' => $subscription->plan->id, 'code' => $subscription->plan->code,
                'name' => $subscription->plan->name, 'is_active' => (bool) $subscription->plan->is_active,
            ] : null,
            'status' => $effectiveStatus,
            'recorded_status' => $subscription->status,
            'starts_at' => $subscription->starts_at?->toISOString(),
            'expires_at' => $subscription->expires_at?->toISOString(),
            'assignment_type' => $subscription->assignment_type,
            'active_class_count' => $activeClasses,
            'max_active_classes' => $maxClasses,
            'remaining_class_capacity' => $maxClasses === null ? null : max(0, (int) $maxClasses - $activeClasses),
            'features' => $this->featureMap($subscription->plan, $current),
        ];
    }

    private function presentTeacher(TeacherProfile $teacher): array
    {
        return [
            'id' => $teacher->id,
            'teacher_code' => $teacher->teacher_code,
            'name' => $teacher->webUser?->name,
            'email' => $teacher->webUser?->email,
            'profile_status' => $teacher->status,
            'account_status' => $teacher->webUser?->status,
            'account_role' => $teacher->webUser?->role,
        ];
    }

    private function featureMap(?TeacherSubscriptionPlan $plan, bool $effective = true): array
    {
        $records = $plan?->features?->keyBy('feature_key') ?? collect();
        $features = [];
        foreach (TeacherSubscriptionPlan::FEATURE_KEYS as $key) $features[$key] = $effective && (bool) ($records->get($key)?->is_enabled ?? false);
        return $features;
    }

    private function presentHistory(TeacherSubscriptionHistory $entry): array
    {
        return [
            'id' => $entry->id,
            'event_type' => $entry->event_type,
            'previous_plan' => $entry->previousPlan ? ['id' => $entry->previousPlan->id, 'code' => $entry->previousPlan->code, 'name' => $entry->previousPlan->name] : null,
            'new_plan' => $entry->newPlan ? ['id' => $entry->newPlan->id, 'code' => $entry->newPlan->code, 'name' => $entry->newPlan->name] : null,
            'previous_plan_id' => $entry->previous_plan_id,
            'new_plan_id' => $entry->new_plan_id,
            'previous_status' => $entry->old_status,
            'new_status' => $entry->new_status,
            'previous_expiry' => $entry->previous_expiry?->toISOString(),
            'new_expiry' => $entry->new_expiry?->toISOString(),
            'actor' => $entry->assignedBy ? ['id' => $entry->assignedBy->id, 'name' => $entry->assignedBy->name, 'email' => $entry->assignedBy->email] : null,
            'reason' => $entry->reason,
            'metadata' => $entry->metadata,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
            'total' => $paginator->total(), 'last_page' => $paginator->lastPage(),
        ];
    }
}
