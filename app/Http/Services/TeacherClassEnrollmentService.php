<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassEnrollmentEvent;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherClassEnrollmentService
{
    public function __construct(
        private TeacherSubscriptionService $subscriptions,
        private WebUserNotificationService $notifications,
        private StudentClassAccessService $classAccess
    ) {}

    public function normalizeCode(string $rawCode): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($rawCode)));
    }

    public function assertActiveStudent(?WebUser $user): WebUser
    {
        if (!$user || $user->role !== 'student') {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'student_required',
                'message' => 'Student access is required.',
            ], 403));
        }

        if ($user->status !== 'active' || $user->deleted_at !== null) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'student_account_inactive',
                'message' => 'Your student account is not active.',
            ], 403));
        }

        return $user;
    }

    /**
     * Join a teacher class via its existing invitation class_code.
     *
     * @return array{enrollment: TeacherClassEnrollment, outcome: string}
     */
    public function joinByCode(WebUser $user, string $rawCode): array
    {
        $student = $this->assertActiveStudent($user);
        $code = $this->normalizeCode($rawCode);

        $lookup = TeacherClass::with('teacherProfile.webUser')
            ->where('class_code', $code)
            ->first();

        if (!$lookup) {
            throw ValidationException::withMessages([
                'class_code' => 'Invalid class invitation code. Please verify the code and try again.',
            ]);
        }

        try {
            return DB::transaction(function () use ($student, $lookup, $code) {
                // Deterministic top-down lock order: teacher_classes -> web_users -> teacher_class_enrollments
                $class = TeacherClass::with(['teacherProfile.webUser', 'academicClass', 'subject', 'curriculumBoard'])
                    ->lockForUpdate()
                    ->findOrFail($lookup->id);

                $lockedStudent = WebUser::lockForUpdate()->findOrFail($student->id);
                $this->assertActiveStudent($lockedStudent);

                $this->assertClassOpenForEnrollment($class);

                // Enforce that student holds an active subscription covering this class
                $this->classAccess->assertStudentAccess($lockedStudent, $class, 'join');

                $existing = TeacherClassEnrollment::where('teacher_class_id', $class->id)
                    ->where('student_web_user_id', $lockedStudent->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->status === TeacherClassEnrollment::STATUS_ACTIVE) {
                        throw new HttpResponseException(response()->json([
                            'success' => false,
                            'code' => 'already_enrolled',
                            'message' => 'You are already enrolled in this class.',
                        ], 409));
                    }

                    if ($existing->status === TeacherClassEnrollment::STATUS_PENDING) {
                        throw new HttpResponseException(response()->json([
                            'success' => false,
                            'code' => 'enrollment_already_pending',
                            'message' => 'Your request to join this class is already pending teacher approval.',
                        ], 409));
                    }
                }

                $previousStatus = $existing?->status;
                // Previously rejected or removed students must always go through teacher approval when re-requesting.
                $mustWaitForApproval = $class->requires_approval
                    || in_array($previousStatus, [
                        TeacherClassEnrollment::STATUS_REJECTED,
                        TeacherClassEnrollment::STATUS_REMOVED,
                    ], true);

                $targetStatus = $mustWaitForApproval
                    ? TeacherClassEnrollment::STATUS_PENDING
                    : TeacherClassEnrollment::STATUS_ACTIVE;

                $now = now();

                if ($existing) {
                    $enrollment = $existing;
                    $enrollment->status = $targetStatus;
                    $enrollment->joined_via_code = $code;
                    $enrollment->requested_at = $now;
                    $enrollment->status_reason = null;
                    if ($targetStatus === TeacherClassEnrollment::STATUS_ACTIVE) {
                        $enrollment->approved_at = $now;
                        $enrollment->reviewed_by_web_user_id = $class->teacherProfile->web_user_id;
                    }
                    $enrollment->save();
                    $eventType = $targetStatus === TeacherClassEnrollment::STATUS_PENDING
                        ? 're_requested'
                        : 're_enrolled';
                } else {
                    $enrollment = TeacherClassEnrollment::create([
                        'teacher_class_id' => $class->id,
                        'student_web_user_id' => $lockedStudent->id,
                        'status' => $targetStatus,
                        'joined_via_code' => $code,
                        'requested_at' => $now,
                        'approved_at' => $targetStatus === TeacherClassEnrollment::STATUS_ACTIVE ? $now : null,
                        'reviewed_by_web_user_id' => $targetStatus === TeacherClassEnrollment::STATUS_ACTIVE
                            ? $class->teacherProfile->web_user_id
                            : null,
                    ]);
                    $eventType = $targetStatus === TeacherClassEnrollment::STATUS_PENDING
                        ? 'requested'
                        : 'auto_enrolled';
                }

                $this->recordEvent(
                    $enrollment,
                    $eventType,
                    $previousStatus,
                    $targetStatus,
                    $lockedStudent->id,
                    'student',
                    null,
                    ['class_code' => $code]
                );

                $teacherWebUserId = (int) $class->teacherProfile->web_user_id;
                if ($targetStatus === TeacherClassEnrollment::STATUS_PENDING) {
                    $this->notifications->notify(
                        $teacherWebUserId,
                        WebUserNotification::TYPE_ENROLLMENT_REQUESTED,
                        'New class enrollment request',
                        sprintf('%s requested to join %s.', $lockedStudent->name, $class->name),
                        [
                            'actor_web_user_id' => $lockedStudent->id,
                            'teacher_class_id' => $class->id,
                            'teacher_class_enrollment_id' => $enrollment->id,
                            'action_url' => "/lms/teacher/classes/{$class->id}?tab=pending",
                            'metadata' => [
                                'class_name' => $class->name,
                                'student_name' => $lockedStudent->name,
                                'status' => $targetStatus,
                            ],
                        ]
                    );
                } else {
                    $this->notifications->notify(
                        $teacherWebUserId,
                        WebUserNotification::TYPE_STUDENT_ENROLLED,
                        'New student joined your class',
                        sprintf('%s joined %s.', $lockedStudent->name, $class->name),
                        [
                            'actor_web_user_id' => $lockedStudent->id,
                            'teacher_class_id' => $class->id,
                            'teacher_class_enrollment_id' => $enrollment->id,
                            'action_url' => "/lms/teacher/classes/{$class->id}?tab=roster",
                            'metadata' => [
                                'class_name' => $class->name,
                                'student_name' => $lockedStudent->name,
                                'status' => $targetStatus,
                            ],
                        ]
                    );
                }

                $enrollment->load([
                    'teacherClass.teacherProfile.webUser:id,name',
                    'teacherClass.academicClass:id,class_name,class_name_um',
                    'teacherClass.subject:id,subject_name,subject_name_um',
                    'teacherClass.curriculumBoard:id,name',
                ]);

                return [
                    'enrollment' => $enrollment,
                    'outcome' => $targetStatus === TeacherClassEnrollment::STATUS_ACTIVE ? 'enrolled' : 'pending',
                ];
            });
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;
            if ($sqlState === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'duplicate_enrollment',
                    'message' => 'An enrollment record already exists for this class.',
                ], 409));
            }
            throw $e;
        }
    }

    public function approve(TeacherProfile $teacherProfile, int $classId, int $enrollmentId): TeacherClassEnrollment
    {
        return DB::transaction(function () use ($teacherProfile, $classId, $enrollmentId) {
            $this->subscriptions->requireFeature($teacherProfile->id, 'students.enroll');

            $class = TeacherClass::ownedBy($teacherProfile)
                ->with(['academicClass:id,class_name,class_name_um', 'subject:id,subject_name,subject_name_um', 'curriculumBoard:id,name'])
                ->lockForUpdate()
                ->findOrFail($classId);

            if (!$class->is_active) {
                throw ValidationException::withMessages([
                    'class' => 'Cannot approve enrollments for an inactive or archived class.',
                ]);
            }

            $enrollment = TeacherClassEnrollment::where('teacher_class_id', $class->id)
                ->where('id', $enrollmentId)
                ->with('student')
                ->lockForUpdate()
                ->firstOrFail();

            if ($enrollment->status !== TeacherClassEnrollment::STATUS_PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'invalid_enrollment_transition',
                    'message' => sprintf('Only pending enrollment requests can be approved (current status: %s).', $enrollment->status),
                ], 409));
            }

            // Revalidate student's active subscription at approval time
            $studentUser = $enrollment->student ?? WebUser::findOrFail($enrollment->student_web_user_id);
            $accessCheck = $this->classAccess->checkStudentAccess($studentUser, $class);
            if (!$accessCheck['has_access']) {
                $programPayload = $accessCheck['matching_program'] ? [
                    'id' => (int) $accessCheck['matching_program']->id,
                    'title' => $accessCheck['matching_program']->title,
                    'slug' => $accessCheck['matching_program']->slug,
                ] : null;

                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => $accessCheck['code'],
                    'message' => 'Cannot approve enrollment: The student does not have an active subscription covering this class (' . $accessCheck['message'] . ').',
                    'program' => $programPayload,
                    'subscription_url' => $accessCheck['subscription_url'],
                ], 422));
            }

            $previousStatus = $enrollment->status;
            $enrollment->status = TeacherClassEnrollment::STATUS_ACTIVE;
            $enrollment->approved_at = now();
            $enrollment->reviewed_by_web_user_id = $teacherProfile->web_user_id;
            $enrollment->status_reason = null;
            $enrollment->save();

            $this->recordEvent(
                $enrollment,
                'approved',
                $previousStatus,
                TeacherClassEnrollment::STATUS_ACTIVE,
                $teacherProfile->web_user_id,
                'teacher'
            );

            $this->notifications->notify(
                (int) $enrollment->student_web_user_id,
                WebUserNotification::TYPE_ENROLLMENT_APPROVED,
                'Class enrollment approved',
                sprintf('Your request to join %s has been approved.', $class->name),
                [
                    'actor_web_user_id' => $teacherProfile->web_user_id,
                    'teacher_class_id' => $class->id,
                    'teacher_class_enrollment_id' => $enrollment->id,
                    'action_url' => '/lms/my-classes',
                    'metadata' => [
                        'class_name' => $class->name,
                        'status' => TeacherClassEnrollment::STATUS_ACTIVE,
                    ],
                ]
            );

            return $enrollment->fresh([
                'student:id,name,email,phone,avatar,status',
                'reviewedBy:id,name',
                'removedBy:id,name',
            ]);
        });
    }

    public function reject(TeacherProfile $teacherProfile, int $classId, int $enrollmentId, string $reason): TeacherClassEnrollment
    {
        $cleanReason = trim($reason);

        return DB::transaction(function () use ($teacherProfile, $classId, $enrollmentId, $cleanReason) {
            $this->subscriptions->requireFeature($teacherProfile->id, 'students.enroll');

            $class = TeacherClass::ownedBy($teacherProfile)
                ->lockForUpdate()
                ->findOrFail($classId);

            $enrollment = TeacherClassEnrollment::where('teacher_class_id', $class->id)
                ->where('id', $enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($enrollment->status !== TeacherClassEnrollment::STATUS_PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'invalid_enrollment_transition',
                    'message' => sprintf('Only pending enrollment requests can be rejected (current status: %s).', $enrollment->status),
                ], 409));
            }

            $previousStatus = $enrollment->status;
            $enrollment->status = TeacherClassEnrollment::STATUS_REJECTED;
            $enrollment->rejected_at = now();
            $enrollment->reviewed_by_web_user_id = $teacherProfile->web_user_id;
            $enrollment->status_reason = $cleanReason;
            $enrollment->save();

            $this->recordEvent(
                $enrollment,
                'rejected',
                $previousStatus,
                TeacherClassEnrollment::STATUS_REJECTED,
                $teacherProfile->web_user_id,
                'teacher',
                $cleanReason
            );

            $this->notifications->notify(
                (int) $enrollment->student_web_user_id,
                WebUserNotification::TYPE_ENROLLMENT_REJECTED,
                'Class enrollment request declined',
                sprintf('Your request to join %s was declined. Reason: %s', $class->name, $cleanReason),
                [
                    'actor_web_user_id' => $teacherProfile->web_user_id,
                    'teacher_class_id' => $class->id,
                    'teacher_class_enrollment_id' => $enrollment->id,
                    'action_url' => '/lms/my-classes',
                    'metadata' => [
                        'class_name' => $class->name,
                        'status' => TeacherClassEnrollment::STATUS_REJECTED,
                        'reason' => $cleanReason,
                    ],
                ]
            );

            return $enrollment->fresh([
                'student:id,name,email,phone,avatar,status',
                'reviewedBy:id,name',
                'removedBy:id,name',
            ]);
        });
    }

    public function remove(TeacherProfile $teacherProfile, int $classId, int $enrollmentId, string $reason): TeacherClassEnrollment
    {
        $cleanReason = trim($reason);

        return DB::transaction(function () use ($teacherProfile, $classId, $enrollmentId, $cleanReason) {
            $this->subscriptions->requireFeature($teacherProfile->id, 'students.enroll');

            $class = TeacherClass::ownedBy($teacherProfile)
                ->lockForUpdate()
                ->findOrFail($classId);

            $enrollment = TeacherClassEnrollment::where('teacher_class_id', $class->id)
                ->where('id', $enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($enrollment->status !== TeacherClassEnrollment::STATUS_ACTIVE) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'invalid_enrollment_transition',
                    'message' => sprintf('Only active students can be removed from a class (current status: %s).', $enrollment->status),
                ], 409));
            }

            $previousStatus = $enrollment->status;
            $enrollment->status = TeacherClassEnrollment::STATUS_REMOVED;
            $enrollment->removed_at = now();
            $enrollment->removed_by_web_user_id = $teacherProfile->web_user_id;
            $enrollment->status_reason = $cleanReason;
            $enrollment->save();

            $this->recordEvent(
                $enrollment,
                'removed',
                $previousStatus,
                TeacherClassEnrollment::STATUS_REMOVED,
                $teacherProfile->web_user_id,
                'teacher',
                $cleanReason
            );

            $this->notifications->notify(
                (int) $enrollment->student_web_user_id,
                WebUserNotification::TYPE_ENROLLMENT_REMOVED,
                'Removed from class',
                sprintf('You have been removed from %s. Reason: %s', $class->name, $cleanReason),
                [
                    'actor_web_user_id' => $teacherProfile->web_user_id,
                    'teacher_class_id' => $class->id,
                    'teacher_class_enrollment_id' => $enrollment->id,
                    'action_url' => '/lms/my-classes',
                    'metadata' => [
                        'class_name' => $class->name,
                        'status' => TeacherClassEnrollment::STATUS_REMOVED,
                        'reason' => $cleanReason,
                    ],
                ]
            );

            return $enrollment->fresh([
                'student:id,name,email,phone,avatar,status',
                'reviewedBy:id,name',
                'removedBy:id,name',
            ]);
        });
    }

    public function leave(WebUser $user, int $enrollmentId, ?string $reason = null): TeacherClassEnrollment
    {
        $student = $this->assertActiveStudent($user);
        $cleanReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return DB::transaction(function () use ($student, $enrollmentId, $cleanReason) {
            $owned = TeacherClassEnrollment::where('student_web_user_id', $student->id)
                ->findOrFail($enrollmentId);

            $class = TeacherClass::with('teacherProfile')
                ->lockForUpdate()
                ->findOrFail($owned->teacher_class_id);

            $enrollment = TeacherClassEnrollment::where('student_web_user_id', $student->id)
                ->lockForUpdate()
                ->findOrFail($enrollmentId);

            if (!in_array($enrollment->status, [
                TeacherClassEnrollment::STATUS_ACTIVE,
                TeacherClassEnrollment::STATUS_PENDING,
            ], true)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'invalid_enrollment_transition',
                    'message' => sprintf('Cannot leave a class enrollment with status "%s".', $enrollment->status),
                ], 409));
            }

            $previousStatus = $enrollment->status;
            $enrollment->status = TeacherClassEnrollment::STATUS_LEFT;
            $enrollment->left_at = now();
            $enrollment->status_reason = $cleanReason;
            $enrollment->save();

            $eventType = $previousStatus === TeacherClassEnrollment::STATUS_PENDING
                ? 'request_withdrawn'
                : 'left';

            $this->recordEvent(
                $enrollment,
                $eventType,
                $previousStatus,
                TeacherClassEnrollment::STATUS_LEFT,
                $student->id,
                'student',
                $cleanReason
            );

            if ($previousStatus === TeacherClassEnrollment::STATUS_ACTIVE && $class->teacherProfile) {
                $this->notifications->notify(
                    (int) $class->teacherProfile->web_user_id,
                    WebUserNotification::TYPE_ENROLLMENT_LEFT,
                    'Student left class',
                    sprintf('%s left %s.', $student->name, $class->name),
                    [
                        'actor_web_user_id' => $student->id,
                        'teacher_class_id' => $class->id,
                        'teacher_class_enrollment_id' => $enrollment->id,
                        'action_url' => "/lms/teacher/classes/{$class->id}?tab=history",
                        'metadata' => [
                            'class_name' => $class->name,
                            'student_name' => $student->name,
                            'status' => TeacherClassEnrollment::STATUS_LEFT,
                            'reason' => $cleanReason,
                        ],
                    ]
                );
            }

            return $enrollment->fresh([
                'teacherClass.teacherProfile.webUser:id,name',
                'teacherClass.academicClass:id,class_name,class_name_um',
                'teacherClass.subject:id,subject_name,subject_name_um',
                'teacherClass.curriculumBoard:id,name',
            ]);
        });
    }

    public function rosterForTeacher(TeacherProfile $teacherProfile, int $classId, array $filters = []): array
    {
        $class = TeacherClass::ownedBy($teacherProfile)
            ->with([
                'academicClass:id,class_name,class_name_um',
                'subject:id,subject_name,subject_name_um',
                'curriculumBoard:id,name',
            ])
            ->findOrFail($classId);

        $status = $filters['status'] ?? 'all';
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->with([
                'student:id,name,email,phone,avatar,status',
                'reviewedBy:id,name',
                'removedBy:id,name',
            ]);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->whereHas('student', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'active' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $counts = $this->classStatusCounts($class->id);

        return [
            'class' => $class,
            'counts' => $counts,
            'paginator' => $paginator,
        ];
    }

    public function classStatusCounts(int $classId): array
    {
        $rows = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $classId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'all' => 0,
            TeacherClassEnrollment::STATUS_ACTIVE => (int) ($rows[TeacherClassEnrollment::STATUS_ACTIVE] ?? 0),
            TeacherClassEnrollment::STATUS_PENDING => (int) ($rows[TeacherClassEnrollment::STATUS_PENDING] ?? 0),
            TeacherClassEnrollment::STATUS_REJECTED => (int) ($rows[TeacherClassEnrollment::STATUS_REJECTED] ?? 0),
            TeacherClassEnrollment::STATUS_REMOVED => (int) ($rows[TeacherClassEnrollment::STATUS_REMOVED] ?? 0),
            TeacherClassEnrollment::STATUS_LEFT => (int) ($rows[TeacherClassEnrollment::STATUS_LEFT] ?? 0),
        ];
        $counts['all'] = $counts['active'] + $counts['pending'] + $counts['rejected'] + $counts['removed'] + $counts['left'];

        return $counts;
    }

    public function enrollmentDetailForTeacher(TeacherProfile $teacherProfile, int $classId, int $enrollmentId): TeacherClassEnrollment
    {
        $class = TeacherClass::ownedBy($teacherProfile)->findOrFail($classId);

        return TeacherClassEnrollment::where('teacher_class_id', $class->id)
            ->with([
                'student:id,name,email,phone,avatar,status',
                'reviewedBy:id,name',
                'removedBy:id,name',
                'events.actor:id,name',
            ])
            ->findOrFail($enrollmentId);
    }

    public function historyForTeacher(TeacherProfile $teacherProfile, int $classId, array $filters = []): LengthAwarePaginator
    {
        $class = TeacherClass::ownedBy($teacherProfile)->findOrFail($classId);
        $perPage = (int) ($filters['per_page'] ?? 25);

        return TeacherClassEnrollmentEvent::where('teacher_class_id', $class->id)
            ->with([
                'student:id,name,email,phone',
                'actor:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function listForStudent(WebUser $user, array $filters = []): array
    {
        $student = $this->assertActiveStudent($user);
        $status = $filters['status'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = TeacherClassEnrollment::query()
            ->where('student_web_user_id', $student->id)
            ->with([
                'teacherClass.teacherProfile.webUser:id,name',
                'teacherClass.academicClass:id,class_name,class_name_um',
                'teacherClass.subject:id,subject_name,subject_name_um',
                'teacherClass.curriculumBoard:id,name',
            ]);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = TeacherClassEnrollment::where('student_web_user_id', $student->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $summary = [
            'all' => 0,
            'active' => (int) ($rows['active'] ?? 0),
            'pending' => (int) ($rows['pending'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
            'removed' => (int) ($rows['removed'] ?? 0),
            'left' => (int) ($rows['left'] ?? 0),
        ];
        $summary['all'] = $summary['active'] + $summary['pending'] + $summary['rejected'] + $summary['removed'] + $summary['left'];

        return [
            'summary' => $summary,
            'paginator' => $paginator,
        ];
    }

    public function detailForStudent(WebUser $user, int $enrollmentId): TeacherClassEnrollment
    {
        $student = $this->assertActiveStudent($user);

        return TeacherClassEnrollment::where('student_web_user_id', $student->id)
            ->with([
                'teacherClass.teacherProfile.webUser:id,name',
                'teacherClass.academicClass:id,class_name,class_name_um',
                'teacherClass.subject:id,subject_name,subject_name_um',
                'teacherClass.curriculumBoard:id,name',
                'events.actor:id,name',
            ])
            ->findOrFail($enrollmentId);
    }

    public function presentForTeacher(TeacherClassEnrollment $enrollment, bool $includeEvents = false): array
    {
        $payload = [
            'id' => $enrollment->id,
            'teacher_class_id' => $enrollment->teacher_class_id,
            'status' => $enrollment->status,
            'joined_via_code' => $enrollment->joined_via_code,
            'requested_at' => $enrollment->requested_at?->toIso8601String(),
            'approved_at' => $enrollment->approved_at?->toIso8601String(),
            'rejected_at' => $enrollment->rejected_at?->toIso8601String(),
            'removed_at' => $enrollment->removed_at?->toIso8601String(),
            'left_at' => $enrollment->left_at?->toIso8601String(),
            'status_reason' => $enrollment->status_reason,
            'reviewed_by' => $enrollment->reviewedBy ? [
                'id' => $enrollment->reviewedBy->id,
                'name' => $enrollment->reviewedBy->name,
            ] : null,
            'removed_by' => $enrollment->removedBy ? [
                'id' => $enrollment->removedBy->id,
                'name' => $enrollment->removedBy->name,
            ] : null,
            'student' => $enrollment->student ? [
                'id' => $enrollment->student->id,
                'name' => $enrollment->student->name,
                'email' => $enrollment->student->email,
                'phone' => $enrollment->student->phone,
                'avatar' => $enrollment->student->avatar,
                'status' => $enrollment->student->status,
            ] : null,
            'created_at' => $enrollment->created_at?->toIso8601String(),
            'updated_at' => $enrollment->updated_at?->toIso8601String(),
        ];

        if ($includeEvents) {
            $payload['events'] = $enrollment->events->map(fn (TeacherClassEnrollmentEvent $event) => $this->presentEvent($event))->values();
        }

        return $payload;
    }

    public function presentForStudent(TeacherClassEnrollment $enrollment, bool $includeEvents = false): array
    {
        $class = $enrollment->teacherClass;
        $hasSubscription = $class ? $this->classAccess->hasActiveSubscription((int) $enrollment->student_web_user_id, $class) : false;
        $hasAccess = $enrollment->status === TeacherClassEnrollment::STATUS_ACTIVE && (bool) $class?->is_active && $hasSubscription;

        $payload = [
            'id' => $enrollment->id,
            'teacher_class_id' => $enrollment->teacher_class_id,
            'status' => $enrollment->status,
            'has_workspace_access' => $hasAccess,
            'has_active_subscription' => $hasSubscription,
            'joined_via_code' => $enrollment->joined_via_code,
            'requested_at' => $enrollment->requested_at?->toIso8601String(),
            'approved_at' => $enrollment->approved_at?->toIso8601String(),
            'rejected_at' => $enrollment->rejected_at?->toIso8601String(),
            'removed_at' => $enrollment->removed_at?->toIso8601String(),
            'left_at' => $enrollment->left_at?->toIso8601String(),
            'status_reason' => $enrollment->status_reason,
            'class' => $class ? [
                'id' => $class->id,
                'name' => $class->name,
                'section' => $class->section !== '' ? $class->section : null,
                'is_active' => (bool) $class->is_active,
                'requires_approval' => (bool) $class->requires_approval,
                'teacher_name' => $class->teacherProfile?->webUser?->name,
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
            ] : null,
            'created_at' => $enrollment->created_at?->toIso8601String(),
            'updated_at' => $enrollment->updated_at?->toIso8601String(),
        ];

        if ($includeEvents) {
            $payload['events'] = $enrollment->events->map(fn (TeacherClassEnrollmentEvent $event) => $this->presentEvent($event))->values();
        }

        return $payload;
    }

    public function presentEvent(TeacherClassEnrollmentEvent $event): array
    {
        return [
            'id' => $event->id,
            'teacher_class_enrollment_id' => $event->teacher_class_enrollment_id,
            'event_type' => $event->event_type,
            'previous_status' => $event->previous_status,
            'new_status' => $event->new_status,
            'actor_role' => $event->actor_role,
            'actor' => $event->actor ? [
                'id' => $event->actor->id,
                'name' => $event->actor->name,
            ] : null,
            'student' => $event->relationLoaded('student') && $event->student ? [
                'id' => $event->student->id,
                'name' => $event->student->name,
                'email' => $event->student->email,
            ] : null,
            'reason' => $event->reason,
            'metadata' => $event->metadata,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function assertClassOpenForEnrollment(TeacherClass $class): void
    {
        $teacherProfile = $class->teacherProfile;
        $teacherUser = $teacherProfile?->webUser;

        if (
            !$class->is_active
            || !$teacherProfile
            || $teacherProfile->status !== 'active'
            || !$teacherUser
            || $teacherUser->role !== 'teacher'
            || $teacherUser->status !== 'active'
        ) {
            throw ValidationException::withMessages([
                'class_code' => 'This class is currently inactive and is not accepting new enrollments.',
            ]);
        }

        if (!$this->subscriptions->canUseFeature($teacherProfile->id, 'students.enroll')) {
            throw ValidationException::withMessages([
                'class_code' => 'This class is currently unable to accept new student enrollments.',
            ]);
        }
    }

    private function recordEvent(
        TeacherClassEnrollment $enrollment,
        string $eventType,
        ?string $previousStatus,
        string $newStatus,
        ?int $actorWebUserId,
        string $actorRole,
        ?string $reason = null,
        ?array $metadata = null
    ): TeacherClassEnrollmentEvent {
        return TeacherClassEnrollmentEvent::create([
            'teacher_class_enrollment_id' => $enrollment->id,
            'teacher_class_id' => $enrollment->teacher_class_id,
            'student_web_user_id' => $enrollment->student_web_user_id,
            'event_type' => $eventType,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'actor_web_user_id' => $actorWebUserId,
            'actor_role' => $actorRole,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
