<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\WebUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase 7G Module G — Read-Only Admin Academic Reporting Service.
 */
class AdminAcademicReportService
{
    public function __construct(
        private readonly AcademicPerformanceService $performanceService
    ) {
    }

    public function overview(): array
    {
        $totalClasses = TeacherClass::query()->where('is_active', true)->count();
        $activeEnrollments = TeacherClassEnrollment::query()
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->count();

        $totalTestAssignments = TeacherClassTestAssignment::query()
            ->where('status', '!=', TeacherClassTestAssignment::STATUS_CANCELLED)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->count();

        $finalizedAttempts = TeacherClassTestAttempt::query()
            ->whereIn('status', [
                TeacherClassTestAttempt::STATUS_SUBMITTED,
                TeacherClassTestAttempt::STATUS_AUTO_SUBMITTED,
                TeacherClassTestAttempt::STATUS_EXPIRED,
            ])
            ->count();

        $totalAssignments = TeacherClassAssignment::query()
            ->whereIn('status', [TeacherClassAssignment::STATUS_PUBLISHED, TeacherClassAssignment::STATUS_CLOSED])
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->count();

        $submittedAssignments = TeacherClassAssignmentSubmission::query()
            ->where('status', TeacherClassAssignmentSubmission::STATUS_SUBMITTED)
            ->count();

        $releasedEvaluations = TeacherClassAssignmentEvaluation::query()
            ->where('status', TeacherClassAssignmentEvaluation::STATUS_RELEASED)
            ->count();

        return [
            'success' => true,
            'overview' => [
                'active_classes_count' => $totalClasses,
                'active_enrollments_count' => $activeEnrollments,
                'published_test_assignments_count' => $totalTestAssignments,
                'finalized_test_attempts_count' => $finalizedAttempts,
                'published_assignments_count' => $totalAssignments,
                'submitted_assignments_count' => $submittedAssignments,
                'released_assignment_evaluations_count' => $releasedEvaluations,
                'evaluation_completion_rate' => $submittedAssignments > 0
                    ? round(($releasedEvaluations / $submittedAssignments) * 100, 2)
                    : null,
            ],
        ];
    }

    public function listClasses(array $filters = []): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $subjectId = !empty($filters['subject_id']) ? (int) $filters['subject_id'] : null;
        $classId = !empty($filters['class_id'])
            ? (int) $filters['class_id']
            : (!empty($filters['academic_class_id']) ? (int) $filters['academic_class_id'] : null);
        $boardId = !empty($filters['curriculum_board_id']) ? (int) $filters['curriculum_board_id'] : null;
        $teacherId = !empty($filters['teacher_id']) ? (int) $filters['teacher_id'] : null;
        $isActiveFilter = array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== ''
            ? filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $perPage = max(5, min(50, (int) ($filters['per_page'] ?? 15)));

        $query = TeacherClass::query()
            ->with(['teacherProfile.webUser:id,name,email', 'academicClass', 'subject', 'curriculumBoard'])
            ->withCount([
                'activeEnrollments',
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('id');

        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }
        if ($classId) {
            $query->where('class_id', $classId);
        }
        if ($boardId) {
            $query->where('curriculum_board_id', $boardId);
        }
        if ($teacherId) {
            $query->where('teacher_profile_id', $teacherId);
        }
        if ($isActiveFilter !== null) {
            $query->where('is_active', $isActiveFilter);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(class_code) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('teacherProfile.webUser', fn ($tq) => $tq->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]));
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);
        $items = collect($paginator->items())->map(function (TeacherClass $class) {
            $analytics = $this->performanceService->computeClassAnalyticsPayload($class);

            return [
                'id' => (int) $class->id,
                'name' => $class->name,
                'section' => $class->section !== '' ? $class->section : null,
                'class_code' => $class->class_code,
                'is_active' => (bool) $class->is_active,
                'teacher' => [
                    'profile_id' => (int) $class->teacher_profile_id,
                    'name' => $class->teacherProfile?->webUser?->name,
                    'email' => $class->teacherProfile?->webUser?->email,
                ],
                'grade' => $class->academicClass?->class_name,
                'subject' => $class->subject?->subject_name,
                'curriculum_board' => $class->curriculumBoard?->name,
                'active_students_count' => (int) ($class->active_enrollments_count ?? 0),
                'assessments_count' => (int) ($analytics['overview']['assessments_count'] ?? 0),
                'class_average_percentage' => $analytics['overview']['class_average_percentage'],
                'completion_rate' => $analytics['overview']['completion_rate'],
                'evaluation_completion_rate' => $analytics['overview']['assignment_evaluation_completion_rate'],
            ];
        })->values()->all();

        return [
            'success' => true,
            'classes' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function classReport(int $classId, array $filters = []): array
    {
        $class = TeacherClass::query()
            ->with(['teacherProfile.webUser:id,name,email', 'academicClass', 'subject', 'curriculumBoard'])
            ->find($classId);

        if (!$class) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'class_not_found',
                'message' => 'Teacher class not found.',
            ], 404));
        }

        $analytics = $this->performanceService->computeClassAnalyticsPayload($class, $filters);
        $model = $this->performanceService->buildClassModel($class, $filters, 'teacher', null, false);

        return [
            'success' => true,
            'class' => array_merge($analytics['class'], [
                'teacher_name' => $class->teacherProfile?->webUser?->name,
                'teacher_email' => $class->teacherProfile?->webUser?->email,
            ]),
            'overview' => $analytics['overview'],
            'assessments' => $analytics['assessment_stats'],
            'students' => $model['rows']->map(fn (array $row) => [
                'student' => $row['student'],
                'completed_count' => $row['completed_count'],
                'pending_count' => $row['pending_count'],
                'excluded_count' => $row['excluded_count'],
                'total_obtained_marks' => $row['total_obtained_marks'],
                'total_available_marks' => $row['total_available_marks'],
                'overall_percentage' => $row['overall_percentage'],
            ])->values()->all(),
            'policy_summary' => $analytics['policy_summary'],
        ];
    }

    public function studentReport(int $studentWebUserId, array $filters = []): array
    {
        $student = WebUser::query()
            ->where('id', $studentWebUserId)
            ->where('role', 'student')
            ->select(['id', 'name', 'email', 'status'])
            ->first();

        if (!$student) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'student_not_found',
                'message' => 'Student record not found.',
            ], 404));
        }

        $report = $this->performanceService->getStudentPerformance($student, $filters);

        return [
            'success' => true,
            'student' => [
                'id' => (int) $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'status' => $student->status,
            ],
            'summary' => $report['summary'],
            'classes' => $report['classes'],
            'subjects' => $report['subjects'],
            'categories' => $report['categories'],
            'assessments' => $report['assessments'],
        ];
    }
}
