<?php

namespace App\Http\Controllers\Api\Student\Freemium;

use App\Http\Controllers\Controller;
use App\Http\Services\Freemium\FreemiumQuotaConsumptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student Freemium Quota Controller.
 *
 * Exposes authenticated read-only endpoints for:
 * - Querying remaining quota balances and window resets across exam types.
 * - Checking attempt and retake eligibility on existing tests.
 *
 * Invariants:
 * - Read-only operation: No quota consumption records, counter increments, or locks are executed.
 */
class StudentFreemiumQuotaController extends Controller
{
    public function __construct(
        protected FreemiumQuotaConsumptionService $quotaService
    ) {
    }

    /**
     * Inspect active quota allowances and remaining balances for authenticated student.
     */
    public function quotaAllowances(Request $request): JsonResponse
    {
        $student = $request->user();

        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
            ], 403);
        }

        $examType = $request->input('exam_type');
        if ($examType !== null && !in_array($examType, ['practice', 'formal_test', 'mock'], true)) {
            return response()->json([
                'success' => 0,
                'message' => "Invalid exam_type. Must be one of 'practice', 'formal_test', 'mock'.",
            ], 422);
        }

        $quotas = $this->quotaService->getStudentQuotaAllowances($student, $examType);

        return response()->json([
            'success' => 1,
            'data'    => [
                'quotas' => $quotas,
            ],
        ]);
    }

    /**
     * Check attempt and retake eligibility for an existing test.
     */
    public function testEligibility(Request $request, int $testId): JsonResponse
    {
        $student = $request->user();

        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
            ], 403);
        }

        $result = $this->quotaService->getTestAttemptEligibility($student, $testId);

        if (isset($result['code']) && $result['code'] === 'test_not_found') {
            return response()->json([
                'success' => 0,
                'message' => 'Test not found or access denied.',
            ], 404);
        }

        return response()->json([
            'success' => 1,
            'data'    => $result,
        ]);
    }
}
