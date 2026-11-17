<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassEnrollmentService;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher Portal – Phase 7A class roster & student enrollment management.
 */
class TeacherClassEnrollmentController extends Controller
{
    public function __construct(private TeacherClassEnrollmentService $enrollments) {}

    public function students(Request $request, int $classId): JsonResponse
    {
        $profile = $this->profile($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'in:all,active,pending,rejected,removed,left'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->enrollments->rosterForTeacher($profile, $classId, $filters);
        $paginator = $result['paginator'];
        $class = $result['class'];

        return response()->json([
            'success' => true,
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'section' => $class->section !== '' ? $class->section : null,
                'class_code' => $class->class_code,
                'is_active' => (bool) $class->is_active,
                'requires_approval' => (bool) $class->requires_approval,
                'class' => $class->academicClass ? [
                    'id' => $class->academicClass->id,
                    'name' => $class->academicClass->class_name,
                    'name_um' => $class->academicClass->class_name_um,
                ] : null,
                'subject' => $class->subject ? [
                    'id' => $class->subject->id,
                    'name' => $class->subject->subject_name,
                    'name_um' => $class->subject->subject_name_um,
                ] : null,
                'curriculum_board' => $class->curriculumBoard ? [
                    'id' => $class->curriculumBoard->id,
                    'name' => $class->curriculumBoard->name,
                ] : null,
            ],
            'counts' => $result['counts'],
            'data' => $paginator->getCollection()
                ->map(fn (TeacherClassEnrollment $item) => $this->enrollments->presentForTeacher($item))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function requests(Request $request, int $classId): JsonResponse
    {
        $request->merge(['status' => TeacherClassEnrollment::STATUS_PENDING]);
        return $this->students($request, $classId);
    }

    public function show(Request $request, int $classId, int $enrollmentId): JsonResponse
    {
        $profile = $this->profile($request);
        $enrollment = $this->enrollments->enrollmentDetailForTeacher($profile, $classId, $enrollmentId);

        return response()->json([
            'success' => true,
            'data' => $this->enrollments->presentForTeacher($enrollment, true),
        ]);
    }

    public function history(Request $request, int $classId): JsonResponse
    {
        $profile = $this->profile($request);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->enrollments->historyForTeacher($profile, $classId, $filters);

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()
                ->map(fn ($event) => $this->enrollments->presentEvent($event))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function approve(Request $request, int $classId, int $enrollmentId): JsonResponse
    {
        $profile = $this->profile($request);
        $enrollment = $this->enrollments->approve($profile, $classId, $enrollmentId);

        return response()->json([
            'success' => true,
            'message' => 'Student enrollment approved.',
            'data' => $this->enrollments->presentForTeacher($enrollment),
        ]);
    }

    public function reject(Request $request, int $classId, int $enrollmentId): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $enrollment = $this->enrollments->reject($profile, $classId, $enrollmentId, $data['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Enrollment request rejected.',
            'data' => $this->enrollments->presentForTeacher($enrollment),
        ]);
    }

    public function remove(Request $request, int $classId, int $enrollmentId): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $enrollment = $this->enrollments->remove($profile, $classId, $enrollmentId, $data['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Student removed from class.',
            'data' => $this->enrollments->presentForTeacher($enrollment),
        ]);
    }

    private function profile(Request $request): TeacherProfile
    {
        $profile = $request->attributes->get('teacher_profile');
        abort_unless($profile instanceof TeacherProfile, 403, 'Teacher access required.');

        return $profile;
    }
}
