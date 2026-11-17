<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitQuestionReportRequest;
use App\Http\Services\QuestionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionReportController extends Controller
{
    public function __construct(
        protected QuestionReportService $service
    ) {}

    /**
     * Submit a report for a question.
     */
    public function store(SubmitQuestionReportRequest $request): JsonResponse
    {
        $user = $request->user('web_api') ?? $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $report = $this->service->submitReport($user, $request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Thank you. Your report has been submitted to the academic content team for review.',
            'data' => [
                'id' => $report->id,
                'question_id' => $report->question_id,
                'report_category' => $report->report_category,
                'status' => $report->status,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Get the authenticated user's submitted reports.
     */
    public function myReports(Request $request): JsonResponse
    {
        $user = $request->user('web_api') ?? $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $perPage = (int) $request->input('per_page', 15);
        $reports = $this->service->getUserReports($user, $perPage);

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
}
