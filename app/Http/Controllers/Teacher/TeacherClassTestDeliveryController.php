<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassTestDeliveryService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher Portal — Phase 7D Test Publication, Scheduling, Assignment & Monitoring Controller.
 */
class TeacherClassTestDeliveryController extends Controller
{
    public function __construct(private TeacherClassTestDeliveryService $delivery) {}

    public function publish(Request $request, int $classId, int $testId): JsonResponse
    {
        $profile = $this->profile($request);
        $class = $this->delivery->resolveOwnedActiveClass($profile, $classId);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'result_visibility' => ['required', 'in:after_submission,after_close,manual_release'],
            'allow_answer_review' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->delivery->publishTest($class, $profile, $testId, $validated));
    }

    public function closeTest(Request $request, int $classId, int $testId): JsonResponse
    {
        $class = $this->delivery->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->delivery->closeTest($class, $testId));
    }

    public function archiveTest(Request $request, int $classId, int $testId): JsonResponse
    {
        $class = $this->delivery->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->delivery->archiveTest($class, $testId));
    }

    public function listAssignments(Request $request, int $classId, int $testId): JsonResponse
    {
        $class = $this->delivery->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->delivery->listAssignments($class, $testId));
    }

    public function assignTest(Request $request, int $classId, int $testId): JsonResponse
    {
        $profile = $this->profile($request);
        $class = $this->delivery->resolveOwnedActiveClass($profile, $classId);

        $validated = $request->validate([
            'assignment_mode' => ['required', 'in:entire_class,selected_students'],
            'student_ids' => ['required_if:assignment_mode,selected_students', 'array', 'min:1', 'max:1000'],
            'student_ids.*' => ['required', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'result_visibility' => ['sometimes', 'nullable', 'in:after_submission,after_close,manual_release'],
            'allow_answer_review' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->delivery->assignTest($class, $profile, $testId, $validated), 201);
    }

    public function showAssignment(Request $request, int $classId, int $testId, int $assignmentId): JsonResponse
    {
        $class = $this->delivery->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->delivery->showAssignmentMonitoring($class, $testId, $assignmentId));
    }

    public function showStudentAttempt(
        Request $request,
        int $classId,
        int $testId,
        int $assignmentId,
        int $attemptId
    ): JsonResponse {
        $class = $this->delivery->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->delivery->showStudentAttempt($class, $testId, $assignmentId, $attemptId));
    }

    public function releaseResults(Request $request, int $classId, int $testId, int $assignmentId): JsonResponse
    {
        $profile = $this->profile($request);
        $class = $this->delivery->resolveOwnedActiveClass($profile, $classId);

        return response()->json($this->delivery->releaseResults($class, $profile, $testId, $assignmentId));
    }

    public function closeAssignment(Request $request, int $classId, int $testId, int $assignmentId): JsonResponse
    {
        $class = $this->delivery->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->delivery->closeAssignment($class, $testId, $assignmentId));
    }

    private function profile(Request $request): TeacherProfile
    {
        return $request->attributes->get('teacher_profile');
    }
}
