<?php

namespace App\Http\Controllers\Api\Admin\Freemium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Freemium\StoreAccessRuleRequest;
use App\Http\Requests\Admin\Freemium\UpdateAccessRuleRequest;
use App\Http\Services\Freemium\FreemiumAuditService;
use App\Models\Freemium\FreemiumAccessRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFreemiumRuleController extends Controller
{
    public function __construct(
        protected FreemiumAuditService $auditService
    ) {
    }

    /**
     * List access rules with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FreemiumAccessRule::query()
            ->with(['campaign']);

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->input('campaign_id'));
        }

        if ($request->filled('target_scope')) {
            $query->where('target_scope', $request->input('target_scope'));
        }

        if ($request->filled('target_status')) {
            $query->where('target_status', $request->input('target_status'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $rules = $query->orderBy('rule_priority', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => $rules->items(),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'per_page'     => $rules->perPage(),
                'total'        => $rules->total(),
                'last_page'    => $rules->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new Freemium access rule.
     */
    public function store(StoreAccessRuleRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['academic_target_snapshot'])) {
            $data['academic_target_snapshot'] = [
                'target_scope' => $data['target_scope'],
                'board_id'     => $data['curriculum_board_id'] ?? null,
                'class_id'     => $data['class_id'] ?? null,
                'subject_id'   => $data['subject_id'] ?? null,
                'book_id'      => $data['book_id'] ?? null,
                'chapter_id'   => $data['chapter_id'] ?? null,
                'topic_id'     => $data['topic_id'] ?? null,
                'question_id'  => $data['question_id'] ?? null,
            ];
        }

        $rule = DB::transaction(function () use ($data, $request) {
            $newRule = FreemiumAccessRule::create($data);

            $this->auditService->log(
                $request->user(),
                'rule.created',
                'freemium_access_rule',
                $newRule->id,
                null,
                $newRule->toArray(),
                $request->ip(),
                $request->userAgent()
            );

            return $newRule;
        });

        return response()->json([
            'message' => 'Access rule created successfully.',
            'data'    => $rule,
        ], 201);
    }

    /**
     * View detailed access rule definition.
     */
    public function show(int $id): JsonResponse
    {
        $rule = FreemiumAccessRule::with(['campaign'])->findOrFail($id);

        return response()->json([
            'data' => $rule,
        ]);
    }

    /**
     * Update permissions or priority on an existing rule.
     */
    public function update(UpdateAccessRuleRequest $request, int $id): JsonResponse
    {
        $rule = FreemiumAccessRule::findOrFail($id);

        $updated = DB::transaction(function () use ($rule, $request) {
            $oldValues = $rule->toArray();
            $rule->update($request->validated());

            $this->auditService->log(
                $request->user(),
                'rule.updated',
                'freemium_access_rule',
                $rule->id,
                $oldValues,
                $rule->fresh()->toArray(),
                $request->ip(),
                $request->userAgent()
            );

            return $rule->fresh();
        });

        return response()->json([
            'message' => 'Access rule updated successfully.',
            'data'    => $updated,
        ]);
    }

    /**
     * Delete an access rule.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $rule = FreemiumAccessRule::findOrFail($id);

        DB::transaction(function () use ($rule, $request, $id) {
            $snapshot = $rule->toArray();
            $rule->delete();

            $this->auditService->log(
                $request->user(),
                'rule.deleted',
                'freemium_access_rule',
                $id,
                $snapshot,
                null,
                $request->ip(),
                $request->userAgent()
            );
        });

        return response()->json([
            'message' => 'Access rule deleted successfully.',
        ]);
    }
}
