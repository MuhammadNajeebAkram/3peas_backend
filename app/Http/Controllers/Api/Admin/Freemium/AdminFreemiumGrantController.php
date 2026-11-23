<?php

namespace App\Http\Controllers\Api\Admin\Freemium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Freemium\AssignStudentGrantRequest;
use App\Http\Services\Freemium\FreemiumAuditService;
use App\Models\Freemium\FreemiumStudentGrant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFreemiumGrantController extends Controller
{
    public function __construct(
        protected FreemiumAuditService $auditService
    ) {
    }

    /**
     * List student grants with student identity and campaign details.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FreemiumStudentGrant::query()
            ->with([
                'campaign:id,name,status,start_at,end_at',
                'student:id,name,email,phone',
                'creator:id,name',
            ]);

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->input('campaign_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $grants = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $grants->items(),
            'meta' => [
                'current_page' => $grants->currentPage(),
                'per_page'     => $grants->perPage(),
                'total'        => $grants->total(),
                'last_page'    => $grants->lastPage(),
            ],
        ]);
    }

    /**
     * Assign a targeted student grant.
     */
    public function assign(AssignStudentGrantRequest $request): JsonResponse
    {
        $data = $request->validated();

        $existing = FreemiumStudentGrant::where('campaign_id', $data['campaign_id'])
            ->where('user_id', $data['user_id'])
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                return response()->json([
                    'message' => 'An active grant already exists for this student on the specified campaign.',
                    'errors'  => [
                        'user_id' => ['Grant already assigned.'],
                    ],
                ], 422);
            }

            // If existing grant was revoked or expired, reactivate it
            $grant = DB::transaction(function () use ($existing, $data, $request) {
                $old = $existing->toArray();
                $existing->update([
                    'grant_type'   => $data['grant_type'] ?? 'direct',
                    'voucher_code' => $data['voucher_code'] ?? null,
                    'granted_at'   => Carbon::now(),
                    'expires_at'   => $data['expires_at'] ?? null,
                    'status'       => 'active',
                    'created_by'   => $request->user()?->id,
                ]);

                $this->auditService->log(
                    $request->user(),
                    'grant.reassigned',
                    'freemium_student_grant',
                    $existing->id,
                    $old,
                    $existing->fresh()->toArray(),
                    $request->ip(),
                    $request->userAgent()
                );

                return $existing->fresh();
            });

            return response()->json([
                'message' => 'Student grant reactivated successfully.',
                'data'    => $grant->load(['campaign:id,name', 'student:id,name,email,phone']),
            ], 200);
        }

        $grant = DB::transaction(function () use ($data, $request) {
            $newGrant = FreemiumStudentGrant::create([
                'campaign_id'  => $data['campaign_id'],
                'user_id'      => $data['user_id'],
                'grant_type'   => $data['grant_type'] ?? 'direct',
                'voucher_code' => $data['voucher_code'] ?? null,
                'granted_at'   => Carbon::now(),
                'expires_at'   => $data['expires_at'] ?? null,
                'status'       => 'active',
                'created_by'   => $request->user()?->id,
            ]);

            $this->auditService->log(
                $request->user(),
                'grant.assigned',
                'freemium_student_grant',
                $newGrant->id,
                null,
                $newGrant->toArray(),
                $request->ip(),
                $request->userAgent()
            );

            return $newGrant;
        });

        return response()->json([
            'message' => 'Student grant assigned successfully.',
            'data'    => $grant->load(['campaign:id,name', 'student:id,name,email,phone']),
        ], 201);
    }

    /**
     * Show student grant details.
     */
    public function show(int $id): JsonResponse
    {
        $grant = FreemiumStudentGrant::with([
            'campaign:id,name,status,start_at,end_at',
            'student:id,name,email,phone',
            'creator:id,name',
        ])->findOrFail($id);

        return response()->json([
            'data' => $grant,
        ]);
    }

    /**
     * Revoke an active student grant.
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $grant = FreemiumStudentGrant::findOrFail($id);

        if ($grant->status === 'revoked') {
            return response()->json([
                'message' => 'Grant is already revoked.',
            ], 422);
        }

        $revoked = DB::transaction(function () use ($grant, $request) {
            $old = $grant->toArray();
            $grant->update(['status' => 'revoked']);

            $this->auditService->log(
                $request->user(),
                'grant.revoked',
                'freemium_student_grant',
                $grant->id,
                $old,
                $grant->fresh()->toArray(),
                $request->ip(),
                $request->userAgent()
            );

            return $grant->fresh();
        });

        return response()->json([
            'message' => 'Student grant revoked successfully.',
            'data'    => $revoked,
        ]);
    }
}
