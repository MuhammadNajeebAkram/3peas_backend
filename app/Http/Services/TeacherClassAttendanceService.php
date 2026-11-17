<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAttendanceAuditLog;
use App\Models\TeacherClassAttendanceRecord;
use App\Models\TeacherClassAttendanceSession;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TeacherClassAttendanceService
{
    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService,
        private readonly WebUserNotificationService $notificationService,
        private readonly StudentClassAccessService $classAccess
    ) {
    }

    public function listSessions(TeacherProfile $profile, int $classId, array $filters = []): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, false);

        $query = TeacherClassAttendanceSession::query()
            ->where('teacher_class_id', $class->id)
            ->withCount([
                'records',
                'records as present_count' => fn ($q) => $q->where('status', TeacherClassAttendanceRecord::STATUS_PRESENT),
                'records as absent_count' => fn ($q) => $q->where('status', TeacherClassAttendanceRecord::STATUS_ABSENT),
                'records as late_count' => fn ($q) => $q->where('status', TeacherClassAttendanceRecord::STATUS_LATE),
                'records as excused_count' => fn ($q) => $q->where('status', TeacherClassAttendanceRecord::STATUS_EXCUSED),
            ]);

        if (!empty($filters['date_from'])) {
            $query->where('session_date', '>=', (string) $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('session_date', '<=', (string) $filters['date_to']);
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 50);
        $paginator = $query
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'sessions' => $paginator->getCollection()->map(fn (TeacherClassAttendanceSession $s) => [
                'id' => (int) $s->id,
                'teacher_class_id' => (int) $s->teacher_class_id,
                'session_date' => $s->session_date?->format('Y-m-d'),
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'slot_key' => $s->slot_key,
                'title' => $s->title,
                'notes' => $s->notes,
                'status' => $s->status,
                'finalized_at' => $s->finalized_at?->timezone('Asia/Karachi')->toIso8601String(),
                'reopened_at' => $s->reopened_at?->timezone('Asia/Karachi')->toIso8601String(),
                'counts' => [
                    'marked' => (int) ($s->records_count ?? 0),
                    'present' => (int) ($s->present_count ?? 0),
                    'absent' => (int) ($s->absent_count ?? 0),
                    'late' => (int) ($s->late_count ?? 0),
                    'excused' => (int) ($s->excused_count ?? 0),
                ],
                'created_at' => $s->created_at?->timezone('Asia/Karachi')->toIso8601String(),
            ])->values()->all(),
            'pagination' => $this->resourceService->presentPagination($paginator),
        ];
    }

    public function createSession(TeacherProfile $profile, int $classId, array $payload): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);

        $sessionDate = (string) $payload['session_date'];
        $startTime = isset($payload['start_time']) && trim((string) $payload['start_time']) !== ''
            ? trim((string) $payload['start_time'])
            : null;
        $endTime = isset($payload['end_time']) && trim((string) $payload['end_time']) !== ''
            ? trim((string) $payload['end_time'])
            : null;

        if ($startTime === null && $endTime !== null) {
            $this->resourceService->abortJson(422, 'invalid_session_time', 'Session start time is required when end time is specified.');
        }

        if ($startTime !== null && $endTime !== null && strcmp($endTime, $startTime) <= 0) {
            $this->resourceService->abortJson(422, 'invalid_session_time', 'Session end time must be after start time.');
        }

        $slotKey = $this->computeSlotKey($startTime, $endTime);

        $activeEnrollments = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->get(['id', 'student_web_user_id'])
            ->keyBy('student_web_user_id');

        $rawInitial = is_array($payload['records'] ?? null) ? $payload['records'] : [];
        $initialByStudent = [];
        foreach ($rawInitial as $row) {
            $studentId = (int) ($row['student_web_user_id'] ?? 0);
            if (!$activeEnrollments->has($studentId)) {
                $this->resourceService->abortJson(
                    422,
                    'ineligible_attendance_student',
                    'Attendance can only be marked for students with active enrollment in this class.'
                );
            }
            $initialByStudent[$studentId] = $row;
        }
        $initialRecords = array_values($initialByStudent);

        $now = CarbonImmutable::now('Asia/Karachi');

        try {
            $session = DB::transaction(function () use ($class, $profile, $payload, $sessionDate, $startTime, $endTime, $slotKey, $activeEnrollments, $initialRecords, $now) {
                // Serialize concurrent session creation per class without gap-lock deadlocks.
                TeacherClass::query()->where('id', $class->id)->lockForUpdate()->first();

                $sameDaySessions = TeacherClassAttendanceSession::query()
                    ->where('teacher_class_id', $class->id)
                    ->where('session_date', $sessionDate)
                    ->get(['id', 'start_time', 'end_time', 'slot_key']);

                foreach ($sameDaySessions as $existingSession) {
                    if ($existingSession->slot_key === $slotKey) {
                        $this->resourceService->abortJson(
                            422,
                            'attendance_session_duplicate',
                            'An attendance session already exists for this class on the selected date and time slot.'
                        );
                    }
                    if ($this->sessionsOverlap($startTime, $endTime, $existingSession->start_time, $existingSession->end_time)) {
                        $this->resourceService->abortJson(
                            422,
                            'attendance_session_overlap',
                            'This time slot overlaps with an existing attendance session on the same date.'
                        );
                    }
                }

                $session = TeacherClassAttendanceSession::create([
                    'teacher_class_id' => $class->id,
                    'teacher_profile_id' => $profile->id,
                    'created_by_web_user_id' => $profile->web_user_id,
                    'session_date' => $sessionDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'slot_key' => $slotKey,
                    'title' => isset($payload['title']) && trim((string) $payload['title']) !== '' ? trim((string) $payload['title']) : null,
                    'notes' => isset($payload['notes']) && trim((string) $payload['notes']) !== '' ? trim((string) $payload['notes']) : null,
                    'status' => TeacherClassAttendanceSession::STATUS_OPEN,
                ]);

                TeacherClassAttendanceAuditLog::create([
                    'session_id' => $session->id,
                    'action' => 'session_created',
                    'acted_by_web_user_id' => $profile->web_user_id,
                    'created_at' => $now,
                ]);

                foreach ($initialRecords as $row) {
                    $studentId = (int) $row['student_web_user_id'];
                    $enrollment = $activeEnrollments->get($studentId);
                    $record = TeacherClassAttendanceRecord::create([
                        'session_id' => $session->id,
                        'teacher_class_id' => $class->id,
                        'student_web_user_id' => $studentId,
                        'teacher_class_enrollment_id' => $enrollment->id,
                        'status' => (string) $row['status'],
                        'remarks' => isset($row['remarks']) && trim((string) $row['remarks']) !== '' ? trim((string) $row['remarks']) : null,
                        'marked_by_web_user_id' => $profile->web_user_id,
                        'marked_at' => $now,
                    ]);

                    TeacherClassAttendanceAuditLog::create([
                        'session_id' => $session->id,
                        'record_id' => $record->id,
                        'student_web_user_id' => $studentId,
                        'action' => 'record_marked',
                        'previous_status' => null,
                        'new_status' => $record->status,
                        'acted_by_web_user_id' => $profile->web_user_id,
                        'created_at' => $now,
                    ]);
                }

                return $session;
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->resourceService->abortJson(
                    422,
                    'attendance_session_duplicate',
                    'An attendance session already exists for this class on the selected date and time slot.'
                );
            }
            throw $e;
        }

        return $this->showSession($profile, $class->id, $session->id);
    }

    public function showSession(TeacherProfile $profile, int $classId, int $sessionId): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, false);
        $session = $this->resolveOwnedSession($profile, $class, $sessionId);

        $existingRecords = TeacherClassAttendanceRecord::query()
            ->where('session_id', $session->id)
            ->with(['student:id,name,email', 'enrollment:id,status'])
            ->get()
            ->keyBy('student_web_user_id');

        $activeEnrollments = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->with('student:id,name,email')
            ->orderBy('id')
            ->get();

        $roster = collect();

        foreach ($activeEnrollments as $enrollment) {
            /** @var TeacherClassAttendanceRecord|null $rec */
            $rec = $existingRecords->get($enrollment->student_web_user_id);
            $roster->push([
                'student_web_user_id' => (int) $enrollment->student_web_user_id,
                'student_name' => $enrollment->student?->name,
                'student_email' => $enrollment->student?->email,
                'enrollment_status' => TeacherClassEnrollment::STATUS_ACTIVE,
                'record_id' => $rec ? (int) $rec->id : null,
                'status' => $rec?->status,
                'remarks' => $rec?->remarks,
                'marked_at' => $rec?->marked_at?->timezone('Asia/Karachi')->toIso8601String(),
            ]);
        }

        // Preserve historical attendance records even if the student later left or was removed.
        foreach ($existingRecords as $studentId => $rec) {
            if (!$activeEnrollments->contains('student_web_user_id', $studentId)) {
                $roster->push([
                    'student_web_user_id' => (int) $studentId,
                    'student_name' => $rec->student?->name,
                    'student_email' => $rec->student?->email,
                    'enrollment_status' => $rec->enrollment?->status ?? 'historical',
                    'record_id' => (int) $rec->id,
                    'status' => $rec->status,
                    'remarks' => $rec->remarks,
                    'marked_at' => $rec->marked_at?->timezone('Asia/Karachi')->toIso8601String(),
                ]);
            }
        }

        $auditLogs = $session->auditLogs()
            ->with(['actor:id,name', 'student:id,name'])
            ->limit(100)
            ->get()
            ->map(fn (TeacherClassAttendanceAuditLog $log) => [
                'id' => (int) $log->id,
                'action' => $log->action,
                'student_web_user_id' => $log->student_web_user_id ? (int) $log->student_web_user_id : null,
                'student_name' => $log->student?->name,
                'previous_status' => $log->previous_status,
                'new_status' => $log->new_status,
                'reason' => $log->reason,
                'actor_name' => $log->actor?->name,
                'created_at' => $log->created_at?->timezone('Asia/Karachi')->toIso8601String(),
            ])
            ->values()
            ->all();

        $counts = [
            'total_roster' => $roster->count(),
            'marked' => $roster->whereNotNull('status')->count(),
            'unmarked' => $roster->whereNull('status')->count(),
            'present' => $roster->where('status', TeacherClassAttendanceRecord::STATUS_PRESENT)->count(),
            'absent' => $roster->where('status', TeacherClassAttendanceRecord::STATUS_ABSENT)->count(),
            'late' => $roster->where('status', TeacherClassAttendanceRecord::STATUS_LATE)->count(),
            'excused' => $roster->where('status', TeacherClassAttendanceRecord::STATUS_EXCUSED)->count(),
        ];

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'session' => [
                'id' => (int) $session->id,
                'teacher_class_id' => (int) $session->teacher_class_id,
                'session_date' => $session->session_date?->format('Y-m-d'),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'slot_key' => $session->slot_key,
                'title' => $session->title,
                'notes' => $session->notes,
                'status' => $session->status,
                'finalized_at' => $session->finalized_at?->timezone('Asia/Karachi')->toIso8601String(),
                'reopened_at' => $session->reopened_at?->timezone('Asia/Karachi')->toIso8601String(),
                'counts' => $counts,
            ],
            'roster' => $roster->values()->all(),
            'audit_logs' => $auditLogs,
        ];
    }

    public function markRecords(TeacherProfile $profile, int $classId, int $sessionId, array $payload): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);
        $session = $this->resolveOwnedSession($profile, $class, $sessionId);

        if ($session->status === TeacherClassAttendanceSession::STATUS_FINALIZED) {
            $this->resourceService->abortJson(
                422,
                'attendance_session_finalized',
                'This attendance session is finalized. Reopen it before making corrections.'
            );
        }

        $activeEnrollments = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->get(['id', 'student_web_user_id'])
            ->keyBy('student_web_user_id');

        $existingByStudent = TeacherClassAttendanceRecord::query()
            ->where('session_id', $session->id)
            ->get()
            ->keyBy('student_web_user_id');

        $reason = isset($payload['reason']) && trim((string) $payload['reason']) !== ''
            ? trim((string) $payload['reason'])
            : null;
        $now = CarbonImmutable::now('Asia/Karachi');

        $entries = [];
        if (!empty($payload['bulk_status'])) {
            $bulkStatus = (string) $payload['bulk_status'];
            foreach ($activeEnrollments as $en) {
                $entries[] = [
                    'student_web_user_id' => (int) $en->student_web_user_id,
                    'status' => $bulkStatus,
                    'remarks' => null,
                ];
            }
        } else {
            $deduped = [];
            foreach ((array) ($payload['records'] ?? []) as $row) {
                $deduped[(int) $row['student_web_user_id']] = $row;
            }
            $entries = array_values($deduped);
        }

        DB::transaction(function () use ($session, $class, $profile, $entries, $activeEnrollments, $reason, $now, $payload) {
            $lockedSession = TeacherClassAttendanceSession::query()
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedSession || $lockedSession->status === TeacherClassAttendanceSession::STATUS_FINALIZED) {
                $this->resourceService->abortJson(
                    422,
                    'attendance_session_finalized',
                    'This attendance session is finalized. Reopen it before making corrections.'
                );
            }

            $existingByStudent = TeacherClassAttendanceRecord::query()
                ->where('session_id', $lockedSession->id)
                ->get()
                ->keyBy('student_web_user_id');

            if (!empty($payload['bulk_status'])) {
                TeacherClassAttendanceAuditLog::create([
                    'session_id' => $lockedSession->id,
                    'action' => 'bulk_marked',
                    'new_status' => (string) $payload['bulk_status'],
                    'reason' => $reason,
                    'acted_by_web_user_id' => $profile->web_user_id,
                    'created_at' => $now,
                ]);
            }

            foreach ($entries as $entry) {
                $studentId = (int) $entry['student_web_user_id'];
                $newStatus = (string) $entry['status'];
                $remarks = array_key_exists('remarks', $entry)
                    ? (trim((string) ($entry['remarks'] ?? '')) !== '' ? trim((string) $entry['remarks']) : null)
                    : null;

                /** @var TeacherClassAttendanceRecord|null $existing */
                $existing = $existingByStudent->get($studentId);
                $activeEnrollment = $activeEnrollments->get($studentId);

                if (!$existing && !$activeEnrollment) {
                    $this->resourceService->abortJson(
                        422,
                        'ineligible_attendance_student',
                        'New attendance records can only be added for students with active enrollment.'
                    );
                }

                if ($existing) {
                    $prevStatus = $existing->status;
                    $existing->update([
                        'status' => $newStatus,
                        'remarks' => array_key_exists('remarks', $entry) ? $remarks : $existing->remarks,
                        'marked_by_web_user_id' => $profile->web_user_id,
                        'marked_at' => $now,
                    ]);

                    if ($prevStatus !== $newStatus || $lockedSession->reopened_at !== null) {
                        TeacherClassAttendanceAuditLog::create([
                            'session_id' => $lockedSession->id,
                            'record_id' => $existing->id,
                            'student_web_user_id' => $studentId,
                            'action' => 'record_corrected',
                            'previous_status' => $prevStatus,
                            'new_status' => $newStatus,
                            'reason' => $reason,
                            'acted_by_web_user_id' => $profile->web_user_id,
                            'created_at' => $now,
                        ]);
                    }
                } else {
                    $created = TeacherClassAttendanceRecord::create([
                        'session_id' => $lockedSession->id,
                        'teacher_class_id' => $class->id,
                        'student_web_user_id' => $studentId,
                        'teacher_class_enrollment_id' => $activeEnrollment->id,
                        'status' => $newStatus,
                        'remarks' => $remarks,
                        'marked_by_web_user_id' => $profile->web_user_id,
                        'marked_at' => $now,
                    ]);
                    $existingByStudent->put($studentId, $created);

                    if (empty($payload['bulk_status'])) {
                        TeacherClassAttendanceAuditLog::create([
                            'session_id' => $lockedSession->id,
                            'record_id' => $created->id,
                            'student_web_user_id' => $studentId,
                            'action' => 'record_marked',
                            'previous_status' => null,
                            'new_status' => $newStatus,
                            'reason' => $reason,
                            'acted_by_web_user_id' => $profile->web_user_id,
                            'created_at' => $now,
                        ]);
                    }
                }
            }
        });

        return $this->showSession($profile, $class->id, $session->id);
    }

    public function finalizeSession(TeacherProfile $profile, int $classId, int $sessionId): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);
        $session = $this->resolveOwnedSession($profile, $class, $sessionId);

        if ($session->status === TeacherClassAttendanceSession::STATUS_FINALIZED) {
            return $this->showSession($profile, $class->id, $session->id);
        }

        $now = CarbonImmutable::now('Asia/Karachi');
        DB::transaction(function () use ($session, $profile, $now) {
            $lockedSession = TeacherClassAttendanceSession::query()
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedSession || $lockedSession->status === TeacherClassAttendanceSession::STATUS_FINALIZED) {
                return;
            }

            if ($lockedSession->records()->count() === 0) {
                $this->resourceService->abortJson(
                    422,
                    'attendance_records_required',
                    'Mark attendance for at least one student before finalizing the session.'
                );
            }

            $lockedSession->update([
                'status' => TeacherClassAttendanceSession::STATUS_FINALIZED,
                'finalized_at' => $now,
                'finalized_by_web_user_id' => $profile->web_user_id,
            ]);

            TeacherClassAttendanceAuditLog::create([
                'session_id' => $lockedSession->id,
                'action' => 'session_finalized',
                'acted_by_web_user_id' => $profile->web_user_id,
                'created_at' => $now,
            ]);
        });

        $claimed = TeacherClassAttendanceSession::query()
            ->where('id', $session->id)
            ->whereNull('notified_finalized_at')
            ->update(['notified_finalized_at' => $now]);

        if ($claimed > 0) {
            $records = $session->records()->get();
            $dateStr = $session->session_date?->format('M d, Y') ?? '';
            foreach ($records as $record) {
                $statusLabel = ucfirst($record->status);
                $this->notificationService->notify(
                    (int) $record->student_web_user_id,
                    WebUserNotification::TYPE_ATTENDANCE_FINALIZED,
                    "Attendance recorded for {$class->name}",
                    "Your attendance for {$class->name} on {$dateStr} was finalized as: {$statusLabel}.",
                    [
                        'actor_web_user_id' => $profile->web_user_id,
                        'teacher_class_id' => $class->id,
                        'teacher_class_enrollment_id' => $record->teacher_class_enrollment_id,
                        'action_url' => "/lms/student/attendance?class_id={$class->id}",
                        'metadata' => [
                            'session_id' => (int) $session->id,
                            'status' => $record->status,
                            'session_date' => $session->session_date?->format('Y-m-d'),
                        ],
                    ]
                );
            }
        }

        return $this->showSession($profile, $class->id, $session->id);
    }

    public function reopenSession(TeacherProfile $profile, int $classId, int $sessionId, ?string $reason = null): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);
        $session = $this->resolveOwnedSession($profile, $class, $sessionId);

        if ($session->status !== TeacherClassAttendanceSession::STATUS_FINALIZED) {
            $this->resourceService->abortJson(422, 'attendance_session_not_finalized', 'Only finalized sessions can be reopened.');
        }

        $cleanReason = $reason !== null ? trim($reason) : '';
        if (mb_strlen($cleanReason) < 2) {
            $this->resourceService->abortJson(
                422,
                'reopen_reason_required',
                'A reason (at least 2 characters) is required to reopen a finalized attendance session.'
            );
        }
        $now = CarbonImmutable::now('Asia/Karachi');

        DB::transaction(function () use ($session, $profile, $cleanReason, $now) {
            $session->update([
                'status' => TeacherClassAttendanceSession::STATUS_OPEN,
                'reopened_at' => $now,
                'reopened_by_web_user_id' => $profile->web_user_id,
            ]);

            TeacherClassAttendanceAuditLog::create([
                'session_id' => $session->id,
                'action' => 'session_reopened',
                'reason' => $cleanReason,
                'acted_by_web_user_id' => $profile->web_user_id,
                'created_at' => $now,
            ]);
        });

        return $this->showSession($profile, $class->id, $session->id);
    }

    public function studentHistoryForTeacher(TeacherProfile $profile, int $classId, int $studentWebUserId, array $filters = []): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, false);

        $student = WebUser::query()->select(['id', 'name', 'email'])->find($studentWebUserId);
        if (!$student) {
            $this->resourceService->abortJson(404, 'student_not_found', 'Student record not found.');
        }

        $enrollment = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('student_web_user_id', $studentWebUserId)
            ->first();

        $recordsQuery = TeacherClassAttendanceRecord::query()
            ->where('teacher_class_attendance_records.teacher_class_id', $class->id)
            ->where('teacher_class_attendance_records.student_web_user_id', $studentWebUserId)
            ->join('teacher_class_attendance_sessions as s', 's.id', '=', 'teacher_class_attendance_records.session_id')
            ->select([
                'teacher_class_attendance_records.*',
                's.session_date',
                's.start_time',
                's.end_time',
                's.title as session_title',
                's.status as session_status',
            ]);

        if (!empty($filters['date_from'])) {
            $recordsQuery->where('s.session_date', '>=', (string) $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $recordsQuery->where('s.session_date', '<=', (string) $filters['date_to']);
        }

        $records = $recordsQuery->orderByDesc('s.session_date')->orderByDesc('s.id')->get();

        if (!$enrollment && $records->isEmpty()) {
            $this->resourceService->abortJson(404, 'student_not_in_class', 'No enrollment or attendance history found for this student in this class.');
        }

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'student' => [
                'id' => (int) $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'enrollment_status' => $enrollment?->status ?? 'historical',
            ],
            'summary' => $this->summarizeRecords($records),
            'records' => $records->map(fn ($r) => [
                'id' => (int) $r->id,
                'session_id' => (int) $r->session_id,
                'session_date' => $r->session_date,
                'start_time' => $r->start_time,
                'end_time' => $r->end_time,
                'session_title' => $r->session_title,
                'session_status' => $r->session_status,
                'status' => $r->status,
                'remarks' => $r->remarks,
                'marked_at' => $r->marked_at?->timezone('Asia/Karachi')->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function listForStudent(WebUser $student, array $filters = []): array
    {
        $classIdFilter = isset($filters['class_id']) ? (int) $filters['class_id'] : null;

        $allActiveEnrollments = TeacherClassEnrollment::query()
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->when($classIdFilter, fn ($q) => $q->where('teacher_class_id', $classIdFilter))
            ->with(['teacherClass' => fn ($q) => $q->where('is_active', true)->with(['academicClass', 'subject', 'curriculumBoard'])])
            ->get()
            ->filter(fn (TeacherClassEnrollment $e) => $e->teacherClass !== null)
            ->values();

        $activeEnrollments = $allActiveEnrollments->filter(fn (TeacherClassEnrollment $e) => $this->classAccess->hasActiveSubscription((int) $student->id, $e->teacherClass))->values();

        if ($classIdFilter && $activeEnrollments->isEmpty()) {
            $targetClass = TeacherClass::find($classIdFilter);
            if ($targetClass) {
                $this->classAccess->assertStudentAccess($student, $targetClass, 'view_attendance');
            }
            $this->resourceService->abortJson(403, 'forbidden_attendance_access', 'You are not actively enrolled in the requested class.');
        }

        $classIds = $activeEnrollments->pluck('teacher_class_id')->map(fn ($id) => (int) $id)->all();
        if (empty($classIds)) {
            return [
                'success' => true,
                'classes' => [],
                'summary' => ['total' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0],
                'records' => [],
            ];
        }

        $query = TeacherClassAttendanceRecord::query()
            ->where('teacher_class_attendance_records.student_web_user_id', $student->id)
            ->whereIn('teacher_class_attendance_records.teacher_class_id', $classIds)
            ->join('teacher_class_attendance_sessions as s', 's.id', '=', 'teacher_class_attendance_records.session_id')
            ->join('teacher_classes as tc', 'tc.id', '=', 'teacher_class_attendance_records.teacher_class_id')
            ->leftJoin('subject_tbl as sub', 'sub.id', '=', 'tc.subject_id')
            ->select([
                'teacher_class_attendance_records.*',
                's.session_date',
                's.start_time',
                's.end_time',
                's.title as session_title',
                's.status as session_status',
                'tc.name as class_name',
                'sub.subject_name',
            ]);

        if (!empty($filters['date_from'])) {
            $query->where('s.session_date', '>=', (string) $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('s.session_date', '<=', (string) $filters['date_to']);
        }

        $records = $query->orderByDesc('s.session_date')->orderByDesc('s.id')->get();

        return [
            'success' => true,
            'classes' => $activeEnrollments->map(fn (TeacherClassEnrollment $e) => $this->resourceService->presentClassSummary($e->teacherClass))->values()->all(),
            'summary' => $this->summarizeRecords($records),
            'records' => $records->map(fn ($r) => [
                'id' => (int) $r->id,
                'session_id' => (int) $r->session_id,
                'teacher_class_id' => (int) $r->teacher_class_id,
                'class_name' => $r->class_name,
                'subject_name' => $r->subject_name,
                'session_date' => $r->session_date,
                'start_time' => $r->start_time,
                'end_time' => $r->end_time,
                'session_title' => $r->session_title,
                'session_status' => $r->session_status,
                'status' => $r->status,
                'remarks' => $r->remarks,
                'marked_at' => $r->marked_at?->timezone('Asia/Karachi')->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function summarizeRecords(\Illuminate\Support\Collection $records): array
    {
        return [
            'total' => $records->count(),
            'present' => $records->where('status', TeacherClassAttendanceRecord::STATUS_PRESENT)->count(),
            'absent' => $records->where('status', TeacherClassAttendanceRecord::STATUS_ABSENT)->count(),
            'late' => $records->where('status', TeacherClassAttendanceRecord::STATUS_LATE)->count(),
            'excused' => $records->where('status', TeacherClassAttendanceRecord::STATUS_EXCUSED)->count(),
        ];
    }

    private function computeSlotKey(?string $startTime, ?string $endTime): string
    {
        if ($startTime === null) {
            return 'full_day';
        }
        $key = $endTime ? "{$startTime}-{$endTime}" : $startTime;
        return mb_substr($key, 0, 20);
    }

    /**
     * Detect full-day or overlapping timed intervals on the same class date.
     */
    private function sessionsOverlap(?string $startA, ?string $endA, ?string $startB, ?string $endB): bool
    {
        if ($startA === null || $startB === null) {
            return true;
        }

        if ($endA !== null && $endB !== null) {
            return strcmp($startA, $endB) < 0 && strcmp($startB, $endA) < 0;
        }

        if ($startA === $startB) {
            return true;
        }

        if ($endA === null && $endB !== null) {
            return strcmp($startA, $startB) >= 0 && strcmp($startA, $endB) < 0;
        }

        if ($endA !== null && $endB === null) {
            return strcmp($startB, $startA) >= 0 && strcmp($startB, $endA) < 0;
        }

        return false;
    }

    private function resolveOwnedClass(TeacherProfile $profile, int $classId, bool $requireActive): TeacherClass
    {
        $class = TeacherClass::query()
            ->with(['academicClass', 'subject', 'curriculumBoard'])
            ->find($classId);

        if (!$class) {
            $this->resourceService->abortJson(404, 'class_not_found', 'Class not found.');
        }

        if ((int) $class->teacher_profile_id !== (int) $profile->id) {
            $this->resourceService->abortJson(403, 'forbidden_teacher_class', 'You do not have permission to access this class.');
        }

        if ($requireActive && !$class->is_active) {
            $this->resourceService->abortJson(403, 'teacher_class_inactive', 'Attendance recording is unavailable because this class is archived.');
        }

        return $class;
    }

    private function resolveOwnedSession(TeacherProfile $profile, TeacherClass $class, int $sessionId): TeacherClassAttendanceSession
    {
        $session = TeacherClassAttendanceSession::query()
            ->where('id', $sessionId)
            ->where('teacher_class_id', $class->id)
            ->where('teacher_profile_id', $profile->id)
            ->first();

        if (!$session) {
            $this->resourceService->abortJson(404, 'attendance_session_not_found', 'Attendance session not found.');
        }

        return $session;
    }
}
