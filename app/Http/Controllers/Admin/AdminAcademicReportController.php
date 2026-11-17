<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\AdminAcademicReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAcademicReportController extends Controller
{
    public function __construct(private readonly AdminAcademicReportService $service)
    {
    }

    public function overview(): JsonResponse
    {
        return response()->json($this->service->overview());
    }

    public function classes(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'curriculum_board_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'academic_class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'teacher_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'nullable'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->listClasses($filters));
    }

    public function classReport(Request $request, int $classId): JsonResponse
    {
        $filters = $request->validate([
            'study_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assessment_type' => ['sometimes', 'nullable', 'in:all,mcq_test,assignment'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);

        return response()->json($this->service->classReport($classId, $filters));
    }

    public function studentReport(Request $request, int $studentWebUserId): JsonResponse
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'study_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assessment_type' => ['sometimes', 'nullable', 'in:all,mcq_test,assignment'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->service->studentReport($studentWebUserId, $filters));
    }
}
