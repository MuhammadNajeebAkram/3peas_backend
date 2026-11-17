<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassEnrollmentService;
use App\Models\TeacherClassEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student Portal – Phase 7A teacher class invitation joining & membership management.
 */
class StudentClassEnrollmentController extends Controller
{
    public function __construct(private TeacherClassEnrollmentService $enrollments) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:all,active,pending,rejected,removed,left'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->enrollments->listForStudent($request->user(), $filters);
        $paginator = $result['paginator'];

        return response()->json([
            'success' => true,
            'summary' => $result['summary'],
            'data' => $paginator->getCollection()
                ->map(fn (TeacherClassEnrollment $item) => $this->enrollments->presentForStudent($item))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_code' => ['required', 'string', 'min:4', 'max:16'],
        ]);

        $result = $this->enrollments->joinByCode($request->user(), $data['class_code']);
        $enrollment = $result['enrollment'];
        $outcome = $result['outcome'];

        return response()->json([
            'success' => true,
            'outcome' => $outcome,
            'message' => $outcome === 'enrolled'
                ? 'You have been enrolled in the class.'
                : 'Your enrollment request has been submitted and is awaiting teacher approval.',
            'data' => $this->enrollments->presentForStudent($enrollment),
        ], 201);
    }

    public function show(Request $request, int $enrollmentId): JsonResponse
    {
        $enrollment = $this->enrollments->detailForStudent($request->user(), $enrollmentId);

        return response()->json([
            'success' => true,
            'data' => $this->enrollments->presentForStudent($enrollment, true),
        ]);
    }

    public function leave(Request $request, int $enrollmentId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $enrollment = $this->enrollments->leave($request->user(), $enrollmentId, $data['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'You have left the class.',
            'data' => $this->enrollments->presentForStudent($enrollment),
        ]);
    }
}
