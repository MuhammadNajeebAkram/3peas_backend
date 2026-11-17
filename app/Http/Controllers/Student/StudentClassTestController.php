<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\StudentClassTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student Portal — Phase 7D Assigned Tests, Online Examination, Answer Saving & Results Controller.
 */
class StudentClassTestController extends Controller
{
    public function __construct(private StudentClassTestService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:upcoming,available,in_progress,submitted,expired,closed'],
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->listAssignedTests($request->user(), $filters));
    }

    public function show(Request $request, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->showAssignment($request->user(), $assignmentId));
    }

    public function start(Request $request, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->startOrResumeAttempt($request->user(), $assignmentId));
    }

    public function attemptDetail(Request $request, int $assignmentId, int $attemptId): JsonResponse
    {
        return response()->json($this->service->showAttempt($request->user(), $assignmentId, $attemptId));
    }

    public function saveAnswers(Request $request, int $assignmentId, int $attemptId): JsonResponse
    {
        $validated = $request->validate([
            'teacher_class_test_question_id' => ['required_without:answers', 'integer', 'min:1'],
            'selected_option_id' => ['nullable', 'integer', 'min:1'],
            'answers' => ['required_without:teacher_class_test_question_id', 'array', 'min:1', 'max:200'],
            'answers.*.teacher_class_test_question_id' => ['required', 'integer', 'min:1'],
            'answers.*.selected_option_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->saveAnswers($request->user(), $assignmentId, $attemptId, $validated));
    }

    public function submit(Request $request, int $assignmentId, int $attemptId): JsonResponse
    {
        return response()->json($this->service->submitAttempt($request->user(), $assignmentId, $attemptId));
    }
}
