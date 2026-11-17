<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTeacherSubscriptionPlanRequest;
use App\Http\Services\TeacherSubscriptionPlanAdminService;
use App\Models\TeacherSubscriptionPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTeacherSubscriptionPlanController extends Controller
{
    public function __construct(private TeacherSubscriptionPlanAdminService $plans) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'q' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'is_free' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', 'in:id,name,code,price,created_at,updated_at,assigned_teacher_count'],
            'sort_direction' => ['sometimes', 'in:asc,desc'],
        ]);

        $query = TeacherSubscriptionPlan::query()->with('features')->withCount([
            'subscriptions as assigned_teacher_count' => fn (Builder $subscriptions) => $subscriptions
                ->where('status', 'active')->where('starts_at', '<=', now())
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('teacherProfile', fn (Builder $profile) => $profile->where('status', 'active')
                    ->whereHas('webUser', fn (Builder $user) => $user->where('role', 'teacher')->where('status', 'active'))),
        ]);

        $search = $filters['search'] ?? $filters['q'] ?? null;
        if ($search !== null) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }
        foreach (['is_active', 'is_free'] as $booleanFilter) {
            if (array_key_exists($booleanFilter, $filters)) $query->where($booleanFilter, $request->boolean($booleanFilter));
        }

        $sort = $filters['sort_by'] ?? 'id';
        $direction = $filters['sort_direction'] ?? 'desc';
        $plans = $query->orderBy($sort, $direction)->paginate($filters['per_page'] ?? 20)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $plans->getCollection()->map(fn (TeacherSubscriptionPlan $plan) => $this->present($plan))->values(),
            'pagination' => [
                'current_page' => $plans->currentPage(),
                'per_page' => $plans->perPage(),
                'total' => $plans->total(),
                'last_page' => $plans->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $plan = TeacherSubscriptionPlan::with(['features', 'history.adminUser:id,name,email'])
            ->withCount(['subscriptions as assigned_teacher_count' => fn (Builder $q) => $q->where('status', 'active')
                ->where('starts_at', '<=', now())->where(fn (Builder $sub) => $sub->whereNull('expires_at')->orWhere('expires_at', '>', now()))])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->present($plan), 'history' => $plan->history->sortByDesc('created_at')->take(20)->values()]);
    }

    public function store(SaveTeacherSubscriptionPlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->validated(), (int) auth('api')->id());
        return response()->json(['success' => true, 'data' => $this->present($plan)], 201);
    }

    public function update(SaveTeacherSubscriptionPlanRequest $request, int $id): JsonResponse
    {
        $plan = $this->plans->update($id, $request->validated(), (int) auth('api')->id());
        return response()->json(['success' => true, 'data' => $this->present($plan)]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'admin_note' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $plan = $this->plans->setStatus($id, $request->boolean('is_active'), $data['admin_note'], (int) auth('api')->id());
        return response()->json(['success' => true, 'data' => $this->present($plan)]);
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['admin_note' => ['required', 'string', 'min:1', 'max:1000']]);
        $plan = $this->plans->setDefault($id, $data['admin_note'], (int) auth('api')->id());
        return response()->json(['success' => true, 'data' => $this->present($plan)]);
    }

    private function present(TeacherSubscriptionPlan $plan): array
    {
        $featureRecords = $plan->features->keyBy('feature_key');
        $features = [];
        foreach (TeacherSubscriptionPlan::FEATURE_KEYS as $key) $features[$key] = (bool) ($featureRecords->get($key)?->is_enabled ?? false);

        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'duration_days' => $plan->duration_days,
            'max_active_classes' => $plan->max_active_classes,
            'is_free' => (bool) $plan->is_free,
            'is_active' => (bool) $plan->is_active,
            'is_default' => (bool) $plan->is_default,
            'features' => $features,
            'assigned_teacher_count' => (int) ($plan->assigned_teacher_count ?? 0),
            'created_by' => $plan->created_by,
            'updated_by' => $plan->updated_by,
            'created_at' => $plan->created_at?->toISOString(),
            'updated_at' => $plan->updated_at?->toISOString(),
        ];
    }
}
