<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\AcademicPerformanceService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPerformanceController extends Controller
{
    public function __construct(private readonly AcademicPerformanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'study_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assessment_type' => ['sometimes', 'nullable', 'in:all,mcq_test,assignment'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json(
            $this->service->getStudentPerformance($this->student($request), $filters)
        );
    }

    public function showAssessment(Request $request, int $assessmentId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:mcq_test,assignment'],
        ]);

        return response()->json(
            $this->service->getStudentAssessmentDetail($this->student($request), $validated['type'], $assessmentId)
        );
    }

    private function student(Request $request): WebUser
    {
        /** @var WebUser|null $user */
        $user = $request->user();
        if (!$user || $user->role !== 'student' || $user->status !== 'active') {
            abort(403, 'Student access only.');
        }

        return $user;
    }
}
