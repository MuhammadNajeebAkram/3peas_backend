<?php

namespace App\Http\Controllers\Api\Admin\Freemium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Freemium\StoreExamQuotaRequest;
use App\Http\Requests\Admin\Freemium\UpdateExamQuotaRequest;
use App\Http\Services\Freemium\FreemiumAuditService;
use App\Models\Freemium\FreemiumExamQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFreemiumQuotaController extends Controller
{
    public function __construct(
        protected FreemiumAuditService $auditService
    ) {
    }

    /**
     * List exam quotas with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FreemiumExamQuota::query()
            ->with(['campaign']);

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->input('campaign_id'));
        }

        if ($request->filled('exam_type')) {
            $query->where('exam_type', $request->input('exam_type'));
        }

        if ($request->filled('window_type')) {
            $query->where('window_type', $request->input('window_type'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $quotas = $query->orderBy('campaign_id', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => $quotas->items(),
            'meta' => [
                'current_page' => $quotas->currentPage(),
                'per_page'     => $quotas->perPage(),
                'total'        => $quotas->total(),
                'last_page'    => $quotas->lastPage(),
            ],
        ]);
    }

    /**
     * Define a new exam quota.
     */
    public function store(StoreExamQuotaRequest $request): JsonResponse
    {
        $data = $request->validated();

        $existing = FreemiumExamQuota::where('campaign_id', $data['campaign_id'])
            ->where('exam_type', $data['exam_type'])
            ->where('window_type', $data['window_type'])
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => "A quota for exam_type '{$data['exam_type']}' and window_type '{$data['window_type']}' already exists on this campaign.",
                'errors'  => [
                    'window_type' => ['Duplicate quota configuration for this campaign and exam type.'],
                ],
            ], 422);
        }

        $quota = DB::transaction(function () use ($data, $request) {
            $newQuota = FreemiumExamQuota::create($data);

            $this->auditService->log(
                $request->user(),
                'quota.created',
                'freemium_exam_quota',
                $newQuota->id,
                null,
                $newQuota->toArray(),
                $request->ip(),
                $request->userAgent()
            );

            return $newQuota;
        });

        return response()->json([
            'message' => 'Exam quota created successfully.',
            'data'    => $quota,
        ], 201);
    }

    /**
     * View exam quota configuration.
     */
    public function show(int $id): JsonResponse
    {
        $quota = FreemiumExamQuota::with(['campaign'])->findOrFail($id);

        return response()->json([
            'data' => $quota,
        ]);
    }

    /**
     * Update quota limits and retake policy.
     */
    public function update(UpdateExamQuotaRequest $request, int $id): JsonResponse
    {
        $quota = FreemiumExamQuota::findOrFail($id);
        $data = $request->validated();

        if (isset($data['window_type']) && $data['window_type'] !== $quota->window_type) {
            $conflict = FreemiumExamQuota::where('campaign_id', $quota->campaign_id)
                ->where('exam_type', $quota->exam_type)
                ->where('window_type', $data['window_type'])
                ->where('id', '!=', $id)
                ->exists();

            if ($conflict) {
                return response()->json([
                    'message' => "A quota for window_type '{$data['window_type']}' already exists on this campaign for this exam type.",
                    'errors'  => [
                        'window_type' => ['Duplicate quota configuration for this campaign and exam type.'],
                    ],
                ], 422);
            }
        }

        $updated = DB::transaction(function () use ($quota, $data, $request) {
            $oldValues = $quota->toArray();
            $quota->update($data);

            $this->auditService->log(
                $request->user(),
                'quota.updated',
                'freemium_exam_quota',
                $quota->id,
                $oldValues,
                $quota->fresh()->toArray(),
                $request->ip(),
                $request->userAgent()
            );

            return $quota->fresh();
        });

        return response()->json([
            'message' => 'Exam quota updated successfully.',
            'data'    => $updated,
        ]);
    }

    /**
     * Delete an exam quota definition.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $quota = FreemiumExamQuota::findOrFail($id);

        DB::transaction(function () use ($quota, $request, $id) {
            $snapshot = $quota->toArray();
            $quota->delete();

            $this->auditService->log(
                $request->user(),
                'quota.deleted',
                'freemium_exam_quota',
                $id,
                $snapshot,
                null,
                $request->ip(),
                $request->userAgent()
            );
        });

        return response()->json([
            'message' => 'Exam quota deleted successfully.',
        ]);
    }
}
