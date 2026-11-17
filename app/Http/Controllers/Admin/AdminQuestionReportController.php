<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateQuestionReportStatusRequest;
use App\Http\Services\QuestionReportService;
use App\Models\QuestionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminQuestionReportController extends Controller
{
    public function __construct(
        protected QuestionReportService $service
    ) {}

    /**
     * Report summary statistics for admin dashboard.
     */
    public function summary(): JsonResponse
    {
        $summary = $this->service->getAdminSummary();

        return response()->json([
            'status' => 'success',
            'data' => $summary,
        ]);
    }

    /**
     * Paginated list of question reports with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'category',
            'reporter_role',
            'curriculum_board_id',
            'class_id',
            'subject_id',
            'question_id',
            'date_from',
            'date_to',
            'search',
        ]);

        $perPage = (int) $request->input('per_page', 20);
        $reports = $this->service->getAdminReports($filters, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => $reports->items(),
            'pagination' => [
                'total' => $reports->total(),
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
            ],
        ]);
    }

    /**
     * Detailed view of a single question report.
     */
    public function show(int $id): JsonResponse
    {
        $report = $this->service->getAdminReportDetail($id);

        return response()->json([
            'status' => 'success',
            'data' => $report,
        ]);
    }

    /**
     * Update report status with optional internal note.
     */
    public function updateStatus(UpdateQuestionReportStatusRequest $request, int $id): JsonResponse
    {
        $report = QuestionReport::findOrFail($id);
        $admin = $request->user('api') ?? $request->user('sanctum') ?? $request->user();
        if (!$admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $updated = $this->service->updateReportStatus(
            $report,
            $admin,
            $request->validated('status'),
            $request->validated('internal_note')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Question report status updated successfully.',
            'data' => $updated,
        ]);
    }

    /**
     * Add an internal administrative note without modifying status.
     */
    public function addNote(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'internal_note' => ['required', 'string', 'max:3000'],
        ]);

        $report = QuestionReport::findOrFail($id);
        $admin = $request->user('api') ?? $request->user('sanctum') ?? $request->user();
        if (!$admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $auditLog = $this->service->addInternalNote(
            $report,
            $admin,
            $request->input('internal_note')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Internal note added to report history.',
            'data' => $auditLog,
        ], 201);
    }

    /**
     * Bulk update status for all reports on a specific question.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'question_id' => ['required', 'integer', 'exists:exam_question_tbl,id'],
            'status' => ['required', 'string', 'in:pending,under_review,resolved,rejected'],
            'internal_note' => ['nullable', 'string', 'max:3000'],
        ]);

        $admin = $request->user('api') ?? $request->user('sanctum') ?? $request->user();
        if (!$admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $count = $this->service->bulkUpdateByQuestion(
            (int) $request->input('question_id'),
            $admin,
            $request->input('status'),
            $request->input('internal_note')
        );

        return response()->json([
            'status' => 'success',
            'message' => "Successfully updated {$count} reports for question #{$request->input('question_id')}.",
            'updated_count' => $count,
        ]);
    }

    /**
     * Quick report count helper for a specific question ID.
     */
    public function questionReportCount(int $questionId): JsonResponse
    {
        $counts = [
            'total' => QuestionReport::where('question_id', $questionId)->count(),
            'pending' => QuestionReport::where('question_id', $questionId)->where('status', QuestionReport::STATUS_PENDING)->count(),
            'under_review' => QuestionReport::where('question_id', $questionId)->where('status', QuestionReport::STATUS_UNDER_REVIEW)->count(),
            'resolved' => QuestionReport::where('question_id', $questionId)->where('status', QuestionReport::STATUS_RESOLVED)->count(),
            'rejected' => QuestionReport::where('question_id', $questionId)->where('status', QuestionReport::STATUS_REJECTED)->count(),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $counts,
        ]);
    }
}
