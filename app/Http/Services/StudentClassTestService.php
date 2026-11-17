<?php

namespace App\Http\Services;

use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestAttemptAnswer;
use App\Models\TeacherClassTestQuestion;
use App\Models\TeacherClassTestRecipient;
use App\Models\WebUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Student Portal — Phase 7D Assigned MCQ Examinations, Server-Authoritative Timing,
 * Answer Persistence, Idempotent Submission & Controlled Result Visibility Service.
 */
class StudentClassTestService
{
    public function __construct(
        private TeacherClassTestAttemptLifecycleService $lifecycle,
        private TeacherClassTestDeliveryService $delivery,
        private StudentClassAccessService $classAccess
    ) {}

    public function listAssignedTests(WebUser $student, array $filters = []): array
    {
        $this->assertActiveStudent($student);

        $recipients = TeacherClassTestRecipient::query()
            ->where('student_web_user_id', $student->id)
            ->where('assignment_status', '!=', TeacherClassTestRecipient::STATUS_REVOKED)
            ->with([
                'assignment.teacherClass.academicClass',
                'assignment.teacherClass.subject',
                'assignment.teacherClass.teacherProfile.webUser:id,name',
                'assignment.test:id,total_questions,total_marks,marks_per_question,negative_marks_per_wrong,language_mode,status',
                'enrollment:id,status',
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();

        $statusFilter = !empty($filters['status']) ? (string) $filters['status'] : null;
        $classFilter = !empty($filters['class_id']) ? (int) $filters['class_id'] : null;

        $items = collect();
        foreach ($recipients as $recipient) {
            $assignment = $recipient->assignment;
            if (!$assignment || $assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED) {
                continue;
            }
            if ($classFilter && (int) $assignment->teacher_class_id !== $classFilter) {
                continue;
            }

            $assignment = $this->lifecycle->syncAssignment($assignment);
            $recipient = $recipient->fresh(['enrollment', 'attempts' => fn ($q) => $q->orderByDesc('attempt_number')]);

            $enrollmentStatus = $this->resolveCurrentEnrollmentStatus($student->id, (int) $assignment->teacher_class_id);
            $hasFinalizedAttempt = $recipient->attempts->contains(fn (TeacherClassTestAttempt $att) => $att->isFinalized());
            $hasActiveSub = $assignment->teacherClass ? $this->classAccess->hasActiveSubscription((int) $student->id, $assignment->teacherClass) : false;

            // Preserve historical records for left/removed or expired students only if they have submitted attempts;
            // never allow unstarted or non-active enrollment access to active/upcoming assignments.
            if (($enrollmentStatus !== TeacherClassEnrollment::STATUS_ACTIVE || !$hasActiveSub) && !$hasFinalizedAttempt) {
                continue;
            }

            $presented = $this->presentStudentAssignment($assignment, $recipient, $enrollmentStatus, false);
            if ($statusFilter && $presented['availability_status'] !== $statusFilter) {
                continue;
            }
            $items->push($presented);
        }

        $summary = [
            'total' => $items->count(),
            'upcoming' => $items->where('availability_status', 'upcoming')->count(),
            'available' => $items->where('availability_status', 'available')->count(),
            'in_progress' => $items->where('availability_status', 'in_progress')->count(),
            'submitted' => $items->where('availability_status', 'submitted')->count(),
            'expired' => $items->where('availability_status', 'expired')->count(),
            'closed' => $items->where('availability_status', 'closed')->count(),
        ];

        return [
            'success' => true,
            'summary' => $summary,
            'data' => $items->values(),
        ];
    }

    public function showAssignment(WebUser $student, int $assignmentId): array
    {
        $this->assertActiveStudent($student);

        $recipient = $this->resolveOwnedRecipient($student, $assignmentId, false);
        $assignment = $this->lifecycle->syncAssignment($recipient->assignment);
        $recipient = $recipient->fresh(['attempts' => fn ($q) => $q->orderByDesc('attempt_number')]);

        $enrollmentStatus = $this->resolveCurrentEnrollmentStatus($student->id, (int) $assignment->teacher_class_id);
        $hasFinalizedAttempt = $recipient->attempts->contains(fn (TeacherClassTestAttempt $att) => $att->isFinalized());

        if (!$hasFinalizedAttempt) {
            if ($enrollmentStatus !== TeacherClassEnrollment::STATUS_ACTIVE) {
                $this->abortJson(403, 'enrollment_not_active', 'Only students with an active enrollment in this class can access this test.');
            }
            if ($assignment->teacherClass) {
                $this->classAccess->assertStudentAccess($student, $assignment->teacherClass, 'view_test');
            }
        }

        return [
            'success' => true,
            'data' => $this->presentStudentAssignment($assignment, $recipient, $enrollmentStatus, true),
        ];
    }

    public function startOrResumeAttempt(WebUser $student, int $assignmentId): array
    {
        $this->assertActiveStudent($student);

        // Pre-sync window & enrollment before locking
        $recipient = $this->resolveOwnedRecipient($student, $assignmentId, true);
        $this->lifecycle->syncAssignment($recipient->assignment);

        $attempt = DB::transaction(function () use ($student, $assignmentId) {
            /** @var TeacherClassTestAssignment|null $assignment */
            $assignment = TeacherClassTestAssignment::lockForUpdate()->find($assignmentId);
            if (!$assignment || $assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED) {
                $this->abortJson(404, 'assignment_not_found', 'Assigned test not found.');
            }

            /** @var TeacherClassTestRecipient|null $lockedRecipient */
            $lockedRecipient = TeacherClassTestRecipient::query()
                ->where('teacher_class_test_assignment_id', $assignmentId)
                ->where('student_web_user_id', $student->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedRecipient || $lockedRecipient->assignment_status === TeacherClassTestRecipient::STATUS_REVOKED) {
                $this->abortJson(404, 'assignment_not_found', 'Assigned test not found.');
            }

            $enrollment = TeacherClassEnrollment::query()
                ->where('teacher_class_id', $lockedRecipient->teacher_class_id)
                ->where('student_web_user_id', $student->id)
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (!$enrollment) {
                $this->abortJson(403, 'enrollment_not_active', 'Active enrollment in this teacher class is required to start or continue this test.');
            }

            $class = $assignment->teacherClass ?? \App\Models\TeacherClass::findOrFail($lockedRecipient->teacher_class_id);
            $this->classAccess->assertStudentAccess($student, $class, 'start_test');

            if ($assignment->test && !in_array($assignment->test->status, ['scheduled', 'published'], true) && $assignment->test->status !== 'closed') {
                $this->abortJson(409, 'test_not_available', 'This test is not available for examination.');
            }

            $now = now();
            if ($assignment->starts_at && $assignment->starts_at->gt($now)) {
                $this->abortJson(422, 'test_not_started', 'This examination has not opened yet.');
            }

            if ($assignment->status === TeacherClassTestAssignment::STATUS_CLOSED || ($assignment->ends_at && $assignment->ends_at->lte($now))) {
                $this->abortJson(422, 'test_window_closed', 'The availability window for this examination has closed.');
            }

            // Finalize any expired in-progress attempt belonging to this recipient
            $existingInProgress = TeacherClassTestAttempt::where('teacher_class_test_recipient_id', $lockedRecipient->id)
                ->where('status', TeacherClassTestAttempt::STATUS_IN_PROGRESS)
                ->lockForUpdate()
                ->first();

            if ($existingInProgress) {
                if ($existingInProgress->deadline_at && $existingInProgress->deadline_at->lte($now)) {
                    $this->lifecycle->finalizeAndGradeAttemptLocked(
                        $existingInProgress,
                        TeacherClassTestAttempt::REASON_DEADLINE_EXPIRED,
                        $now,
                        true
                    );
                } else {
                    // Safe resume of active attempt
                    return $existingInProgress->fresh(['answers.testQuestion']);
                }
            }

            $attemptsCount = TeacherClassTestAttempt::where('teacher_class_test_recipient_id', $lockedRecipient->id)
                ->lockForUpdate()
                ->count();

            if ($attemptsCount >= (int) $assignment->max_attempts) {
                $this->abortJson(422, 'max_attempts_reached', 'You have reached the maximum number of attempts for this test.');
            }

            $testQuestions = TeacherClassTestQuestion::where('teacher_class_test_id', $assignment->teacher_class_test_id)
                ->orderBy('question_order')
                ->orderBy('id')
                ->get();

            if ($testQuestions->isEmpty()) {
                $this->abortJson(422, 'empty_test_questions', 'This examination does not have any questions.');
            }

            $durationEnd = $now->copy()->addMinutes((int) $assignment->duration_minutes);
            $deadlineAt = ($assignment->ends_at && $assignment->ends_at->lt($durationEnd))
                ? $assignment->ends_at->copy()
                : $durationEnd;

            $attempt = new TeacherClassTestAttempt();
            $attempt->teacher_class_test_assignment_id = $assignment->id;
            $attempt->teacher_class_test_recipient_id = $lockedRecipient->id;
            $attempt->teacher_class_test_id = $assignment->teacher_class_test_id;
            $attempt->teacher_class_id = $assignment->teacher_class_id;
            $attempt->student_web_user_id = $student->id;
            $attempt->attempt_number = $attemptsCount + 1;
            $attempt->status = TeacherClassTestAttempt::STATUS_IN_PROGRESS;
            $attempt->started_at = $now;
            $attempt->deadline_at = $deadlineAt;
            $attempt->total_questions = $testQuestions->count();
            $attempt->answered_questions = 0;
            $attempt->max_marks = round((float) $testQuestions->sum('marks'), 2);
            $attempt->save();

            foreach ($testQuestions as $tq) {
                $row = new TeacherClassTestAttemptAnswer();
                $row->attempt_id = $attempt->id;
                $row->teacher_class_test_question_id = $tq->id;
                $row->question_id = $tq->question_id;
                $row->scenario_group_id = $tq->scenario_group_id;
                $row->question_order = $tq->question_order;
                $row->correct_option_id_snapshot = $tq->correct_option_id_snapshot;
                $row->correct_option_key_snapshot = $tq->correct_option_key_snapshot;
                $row->marks_possible = round((float) $tq->marks, 2);
                $row->negative_marks_possible = round((float) $tq->negative_marks, 2);
                $row->save();
            }

            $lockedRecipient->assignment_status = TeacherClassTestRecipient::STATUS_IN_PROGRESS;
            $lockedRecipient->save();

            return $attempt->fresh(['answers.testQuestion']);
        });

        $assignment = $recipient->assignment->fresh(['teacherClass', 'test']);

        return [
            'success' => true,
            'message' => 'Examination session ready.',
            'data' => $this->presentStudentAttemptDetail($assignment, $attempt),
        ];
    }

    public function showAttempt(WebUser $student, int $assignmentId, int $attemptId): array
    {
        $this->assertActiveStudent($student);

        $recipient = $this->resolveOwnedRecipient($student, $assignmentId, false);
        $assignment = $this->lifecycle->syncAssignment($recipient->assignment);

        /** @var TeacherClassTestAttempt|null $attempt */
        $attempt = TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignment->id)
            ->where('teacher_class_test_recipient_id', $recipient->id)
            ->where('student_web_user_id', $student->id)
            ->with(['answers.testQuestion'])
            ->find($attemptId);

        if (!$attempt) {
            $this->abortJson(404, 'attempt_not_found', 'Examination attempt not found.');
        }

        $enrollmentStatus = $this->resolveCurrentEnrollmentStatus($student->id, (int) $assignment->teacher_class_id);
        if ($attempt->isInProgress()) {
            if ($enrollmentStatus !== TeacherClassEnrollment::STATUS_ACTIVE) {
                $this->abortJson(403, 'enrollment_not_active', 'Only actively enrolled students can continue an in-progress attempt.');
            }
            if ($assignment->teacherClass) {
                $this->classAccess->assertStudentAccess($student, $assignment->teacherClass, 'resume_test');
            }
        }
        if (!$attempt->isFinalized() && $enrollmentStatus !== TeacherClassEnrollment::STATUS_ACTIVE) {
            $this->abortJson(403, 'enrollment_not_active', 'Enrollment is no longer active.');
        }

        return [
            'success' => true,
            'data' => $this->presentStudentAttemptDetail($assignment, $attempt),
        ];
    }

    public function saveAnswers(WebUser $student, int $assignmentId, int $attemptId, array $payload): array
    {
        $this->assertActiveStudent($student);

        $result = DB::transaction(function () use ($student, $assignmentId, $attemptId, $payload) {
            /** @var TeacherClassTestAssignment|null $assignment */
            $assignment = TeacherClassTestAssignment::lockForUpdate()->find($assignmentId);

            /** @var TeacherClassTestAttempt|null $attempt */
            $attempt = TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignmentId)
                ->where('student_web_user_id', $student->id)
                ->lockForUpdate()
                ->find($attemptId);

            if (!$attempt) {
                $this->abortJson(404, 'attempt_not_found', 'Examination attempt not found.');
            }

            if (!$assignment) {
                $this->abortJson(404, 'assignment_not_found', 'Assignment not found.');
            }

            $enrollmentActive = TeacherClassEnrollment::query()
                ->where('teacher_class_id', $attempt->teacher_class_id)
                ->where('student_web_user_id', $student->id)
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->exists();

            if (!$enrollmentActive) {
                $this->abortJson(403, 'enrollment_not_active', 'Active class enrollment is required to save answers.');
            }

            $class = $assignment->teacherClass ?? \App\Models\TeacherClass::findOrFail($attempt->teacher_class_id);
            $this->classAccess->assertStudentAccess($student, $class, 'save_answers');

            $now = now();
            if ($attempt->isFinalized()) {
                $this->abortJson(409, 'attempt_already_submitted', 'This attempt has already been submitted.');
            }

            if ($assignment->status === TeacherClassTestAssignment::STATUS_CLOSED
                || $assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED
                || ($assignment->ends_at && $assignment->ends_at->lte($now))
                || ($attempt->deadline_at && $attempt->deadline_at->lte($now))) {
                $reason = ($assignment->ends_at && $assignment->ends_at->lte($now))
                    ? TeacherClassTestAttempt::REASON_WINDOW_CLOSED
                    : TeacherClassTestAttempt::REASON_DEADLINE_EXPIRED;

                $this->lifecycle->finalizeAndGradeAttemptLocked($attempt, $reason, $now, true);
                return ['expired' => true, 'attempt' => $attempt->fresh(), 'assignment' => $assignment];
            }

            $entries = $this->normalizeAnswerEntries($payload);
            $answerRows = TeacherClassTestAttemptAnswer::where('attempt_id', $attempt->id)
                ->with('testQuestion')
                ->lockForUpdate()
                ->get()
                ->keyBy('teacher_class_test_question_id');

            foreach ($entries as $entry) {
                $tqId = $entry['teacher_class_test_question_id'];
                /** @var TeacherClassTestAttemptAnswer|null $row */
                $row = $answerRows->get($tqId);
                if (!$row) {
                    $this->abortJson(422, 'question_not_in_attempt', "Question #{$tqId} does not belong to this examination attempt.");
                }

                $selectedOptionId = $entry['selected_option_id'];
                if ($selectedOptionId === null) {
                    $row->selected_option_id = null;
                    $row->selected_option_key = null;
                    $row->answered_at = null;
                    $row->save();
                    continue;
                }

                $validOptions = collect($row->testQuestion?->options_snapshot_json ?? []);
                $matched = $validOptions->first(fn ($opt) => is_array($opt) && (int) ($opt['id'] ?? 0) === (int) $selectedOptionId);
                if (!$matched) {
                    $this->abortJson(422, 'invalid_option_for_question', "Selected option #{$selectedOptionId} does not belong to question #{$tqId}.");
                }

                $row->selected_option_id = (int) $selectedOptionId;
                $row->selected_option_key = $matched['option_key'] ?? null;
                $row->answered_at = $now;
                $row->save();
            }

            $attempt->answered_questions = TeacherClassTestAttemptAnswer::where('attempt_id', $attempt->id)
                ->whereNotNull('selected_option_id')
                ->count();
            $attempt->time_spent_seconds = $attempt->started_at ? max(0, $attempt->started_at->diffInSeconds($now)) : 0;
            $attempt->save();

            return [
                'expired' => false,
                'attempt' => $attempt->fresh(['answers']),
                'assignment' => $assignment,
            ];
        });

        if ($result['expired']) {
            $this->abortJson(
                409,
                'attempt_deadline_expired',
                'Your examination time limit has expired and your saved answers were finalized automatically.'
            );
        }

        /** @var TeacherClassTestAttempt $attempt */
        $attempt = $result['attempt'];
        /** @var TeacherClassTestAssignment $assignment */
        $assignment = $result['assignment'];

        return [
            'success' => true,
            'message' => 'Answers saved.',
            'data' => [
                'attempt_id' => (int) $attempt->id,
                'answered_questions' => (int) $attempt->answered_questions,
                'total_questions' => (int) $attempt->total_questions,
                'remaining_seconds' => $this->computeRemainingSeconds($assignment, $attempt),
                'saved_answers' => $attempt->answers->map(fn (TeacherClassTestAttemptAnswer $a) => [
                    'teacher_class_test_question_id' => (int) $a->teacher_class_test_question_id,
                    'selected_option_id' => $a->selected_option_id ? (int) $a->selected_option_id : null,
                    'answered_at' => $a->answered_at?->toIso8601String(),
                ])->values(),
            ],
        ];
    }

    public function submitAttempt(WebUser $student, int $assignmentId, int $attemptId): array
    {
        $this->assertActiveStudent($student);

        $outcome = DB::transaction(function () use ($student, $assignmentId, $attemptId) {
            /** @var TeacherClassTestAssignment|null $assignment */
            $assignment = TeacherClassTestAssignment::lockForUpdate()->find($assignmentId);

            /** @var TeacherClassTestAttempt|null $attempt */
            $attempt = TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignmentId)
                ->where('student_web_user_id', $student->id)
                ->lockForUpdate()
                ->find($attemptId);

            if (!$attempt) {
                $this->abortJson(404, 'attempt_not_found', 'Examination attempt not found.');
            }

            if (!$assignment) {
                $this->abortJson(404, 'assignment_not_found', 'Assignment not found.');
            }

            // Idempotent re-submission: never double grade or duplicate results
            if ($attempt->isFinalized()) {
                return [
                    'already_finalized' => true,
                    'assignment' => $assignment,
                    'attempt' => $attempt->fresh(['answers.testQuestion']),
                ];
            }

            $now = now();
            $windowEnded = $assignment->status === TeacherClassTestAssignment::STATUS_CLOSED
                || ($assignment->ends_at && $assignment->ends_at->lte($now));
            $isAutoExpired = $windowEnded || ($attempt->deadline_at && $attempt->deadline_at->lte($now));

            if (!$isAutoExpired) {
                $enrollmentActive = TeacherClassEnrollment::query()
                    ->where('teacher_class_id', $attempt->teacher_class_id)
                    ->where('student_web_user_id', $student->id)
                    ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                    ->exists();

                if (!$enrollmentActive) {
                    $this->abortJson(403, 'enrollment_not_active', 'Active class enrollment is required to submit this examination.');
                }

                $class = $assignment->teacherClass ?? \App\Models\TeacherClass::findOrFail($attempt->teacher_class_id);
                $this->classAccess->assertStudentAccess($student, $class, 'submit_test');
            }

            $reason = TeacherClassTestAttempt::REASON_MANUAL;
            if ($isAutoExpired) {
                $reason = $windowEnded
                    ? TeacherClassTestAttempt::REASON_WINDOW_CLOSED
                    : TeacherClassTestAttempt::REASON_DEADLINE_EXPIRED;
            }

            $finalized = $this->lifecycle->finalizeAndGradeAttemptLocked($attempt, $reason, $now, $isAutoExpired);

            return [
                'already_finalized' => false,
                'assignment' => $assignment,
                'attempt' => $finalized->fresh(['answers.testQuestion']),
            ];
        });

        /** @var TeacherClassTestAssignment $assignment */
        $assignment = $this->lifecycle->syncAssignment($outcome['assignment']);
        /** @var TeacherClassTestAttempt $attempt */
        $attempt = $outcome['attempt'];

        if ($this->lifecycle->canStudentViewMarks($assignment, $attempt)) {
            $recipient = TeacherClassTestRecipient::find($attempt->teacher_class_test_recipient_id);
            if ($recipient && $recipient->notified_result_at === null && $assignment->result_visibility === TeacherClassTest::VISIBILITY_AFTER_SUBMISSION) {
                $recipient->notified_result_at = now();
                $recipient->save();
            }
        }

        return [
            'success' => true,
            'already_finalized' => $outcome['already_finalized'],
            'message' => $outcome['already_finalized']
                ? 'This examination attempt was already submitted.'
                : 'Examination submitted successfully.',
            'data' => $this->presentStudentAttemptDetail($assignment, $attempt),
        ];
    }

    private function assertActiveStudent(WebUser $user): void
    {
        if ($user->role !== 'student') {
            $this->abortJson(403, 'student_required', 'Student portal access is required.');
        }
        if ($user->status !== 'active') {
            $this->abortJson(403, 'student_inactive', 'Your student account is not active.');
        }
    }

    private function resolveOwnedRecipient(WebUser $student, int $assignmentId, bool $requireActiveEnrollment): TeacherClassTestRecipient
    {
        $recipient = TeacherClassTestRecipient::query()
            ->where('teacher_class_test_assignment_id', $assignmentId)
            ->where('student_web_user_id', $student->id)
            ->with([
                'assignment.teacherClass.academicClass',
                'assignment.teacherClass.subject',
                'assignment.teacherClass.teacherProfile.webUser:id,name',
                'assignment.test',
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->first();

        if (!$recipient || $recipient->assignment_status === TeacherClassTestRecipient::STATUS_REVOKED || !$recipient->assignment) {
            $this->abortJson(404, 'assignment_not_found', 'Assigned test not found for your account.');
        }

        if ($requireActiveEnrollment) {
            $status = $this->resolveCurrentEnrollmentStatus($student->id, (int) $recipient->teacher_class_id);
            if ($status !== TeacherClassEnrollment::STATUS_ACTIVE) {
                $this->abortJson(403, 'enrollment_not_active', 'Only actively enrolled students may access or take this test.');
            }
        }

        return $recipient;
    }

    private function resolveCurrentEnrollmentStatus(int $studentId, int $teacherClassId): string
    {
        $enrollment = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $teacherClassId)
            ->where('student_web_user_id', $studentId)
            ->first();

        return $enrollment?->status ?? 'not_enrolled';
    }

    private function presentStudentAssignment(
        TeacherClassTestAssignment $assignment,
        TeacherClassTestRecipient $recipient,
        string $enrollmentStatus,
        bool $includeAttemptsList
    ): array {
        $now = now();
        $attempts = $recipient->attempts ?? collect();
        $activeAttempt = $attempts->first(fn (TeacherClassTestAttempt $a) => $a->isInProgress());
        $finalizedAttempts = $attempts->filter(fn (TeacherClassTestAttempt $a) => $a->isFinalized());
        $attemptsUsed = $attempts->count();
        $remainingAttempts = max(0, (int) $assignment->max_attempts - $attemptsUsed);

        $windowOpen = $assignment->status === TeacherClassTestAssignment::STATUS_ACTIVE
            && (!$assignment->starts_at || $assignment->starts_at->lte($now))
            && (!$assignment->ends_at || $assignment->ends_at->gt($now));

        $isUpcoming = $assignment->status === TeacherClassTestAssignment::STATUS_SCHEDULED
            || ($assignment->starts_at && $assignment->starts_at->gt($now));

        $availabilityStatus = 'available';
        if ($activeAttempt) {
            $availabilityStatus = 'in_progress';
        } elseif ($finalizedAttempts->isNotEmpty() && ($remainingAttempts === 0 || !$windowOpen)) {
            $availabilityStatus = 'submitted';
        } elseif ($finalizedAttempts->isNotEmpty() && $windowOpen) {
            $availabilityStatus = 'submitted';
        } elseif ($assignment->status === TeacherClassTestAssignment::STATUS_CLOSED || ($assignment->ends_at && $assignment->ends_at->lte($now))) {
            $availabilityStatus = $recipient->assignment_status === TeacherClassTestRecipient::STATUS_EXPIRED ? 'expired' : 'closed';
        } elseif ($isUpcoming) {
            $availabilityStatus = 'upcoming';
        }

        $class = $assignment->teacherClass;
        $hasActiveSub = $class ? $this->classAccess->hasActiveSubscription((int) $recipient->student_web_user_id, $class) : false;
        $isActiveEnrolled = $enrollmentStatus === TeacherClassEnrollment::STATUS_ACTIVE && $hasActiveSub;
        $canStart = $isActiveEnrolled && $windowOpen && !$activeAttempt && $remainingAttempts > 0;
        $canResume = $isActiveEnrolled && $windowOpen && (bool) $activeAttempt;
        $test = $assignment->test;

        $latestFinalized = $finalizedAttempts->first();
        $bestAttempt = $finalizedAttempts->sortByDesc('obtained_marks')->first();
        $resultVisible = $bestAttempt ? $this->lifecycle->canStudentViewMarks($assignment, $bestAttempt) : false;

        $data = [
            'assignment_id' => (int) $assignment->id,
            'recipient_id' => (int) $recipient->id,
            'title' => $assignment->title_snapshot,
            'instructions' => $assignment->instructions_snapshot,
            'language_mode' => $test?->language_mode ?? 'bilingual',
            'total_questions' => (int) ($test?->total_questions ?? 0),
            'total_marks' => round((float) ($test?->total_marks ?? 0), 2),
            'negative_marks_per_wrong' => round((float) ($test?->negative_marks_per_wrong ?? 0), 2),
            'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $assignment->duration_minutes,
            'max_attempts' => (int) $assignment->max_attempts,
            'attempts_used' => $attemptsUsed,
            'remaining_attempts' => $remainingAttempts,
            'availability_status' => $availabilityStatus,
            'assignment_status' => $recipient->assignment_status,
            'enrollment_status' => $enrollmentStatus,
            'result_visibility' => $assignment->result_visibility,
            'allow_answer_review' => (bool) $assignment->allow_answer_review,
            'results_released' => $resultVisible,
            'can_start' => $canStart,
            'can_resume' => $canResume,
            'active_attempt_id' => $activeAttempt ? (int) $activeAttempt->id : null,
            'latest_attempt_id' => $latestFinalized ? (int) $latestFinalized->id : null,
            'latest_result' => $bestAttempt ? $this->presentStudentAttemptSummary($assignment, $bestAttempt) : null,
            'class' => $class ? [
                'id' => (int) $class->id,
                'name' => $class->name,
                'section' => $class->section !== '' ? $class->section : null,
                'teacher_name' => $class->teacherProfile?->webUser?->name,
                'grade_name' => $class->academicClass?->class_name,
                'subject_name' => $class->subject?->subject_name,
            ] : null,
        ];

        if ($includeAttemptsList) {
            $data['attempts'] = $attempts->map(fn (TeacherClassTestAttempt $a) => $this->presentStudentAttemptSummary($assignment, $a))->values();
        }

        return $data;
    }

    private function presentStudentAttemptSummary(TeacherClassTestAssignment $assignment, TeacherClassTestAttempt $attempt): array
    {
        $marksVisible = $this->lifecycle->canStudentViewMarks($assignment, $attempt);
        $answersVisible = $this->lifecycle->canStudentReviewAnswers($assignment, $attempt);

        $summary = [
            'id' => (int) $attempt->id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status,
            'submission_reason' => $attempt->submission_reason,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'deadline_at' => $attempt->deadline_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'remaining_seconds' => $attempt->isInProgress() ? $this->computeRemainingSeconds($assignment, $attempt) : 0,
            'total_questions' => (int) $attempt->total_questions,
            'answered_questions' => (int) $attempt->answered_questions,
            'max_marks' => round((float) $attempt->max_marks, 2),
            'result_released' => $marksVisible,
            'can_review_answers' => $answersVisible,
            'result_visibility' => $assignment->result_visibility,
        ];

        if ($marksVisible) {
            $summary['correct_count'] = (int) $attempt->correct_count;
            $summary['incorrect_count'] = (int) $attempt->incorrect_count;
            $summary['unanswered_count'] = (int) $attempt->unanswered_count;
            $summary['obtained_marks'] = round((float) $attempt->obtained_marks, 2);
            $summary['percentage'] = round((float) $attempt->percentage, 2);
            $summary['time_spent_seconds'] = (int) $attempt->time_spent_seconds;
        }

        return $summary;
    }

    private function presentStudentAttemptDetail(TeacherClassTestAssignment $assignment, TeacherClassTestAttempt $attempt): array
    {
        $marksVisible = $this->lifecycle->canStudentViewMarks($assignment, $attempt);
        $answersVisible = $this->lifecycle->canStudentReviewAnswers($assignment, $attempt);

        $class = $assignment->teacherClass;
        $summary = $this->presentStudentAttemptSummary($assignment, $attempt);
        $summary['assignment'] = [
            'id' => (int) $assignment->id,
            'title' => $assignment->title_snapshot,
            'instructions' => $assignment->instructions_snapshot,
            'language_mode' => $assignment->test?->language_mode ?? 'bilingual',
            'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $assignment->duration_minutes,
            'max_attempts' => (int) $assignment->max_attempts,
            'result_visibility' => $assignment->result_visibility,
            'allow_answer_review' => (bool) $assignment->allow_answer_review,
            'class_name' => $class?->name,
            'subject_name' => $class?->subject?->subject_name,
            'teacher_name' => $class?->teacherProfile?->webUser?->name,
        ];

        // Questions included ONLY during an in-progress exam (WITHOUT answer keys/explanations)
        // OR after results AND answer review are unlocked!
        if ($attempt->isInProgress()) {
            $flat = $attempt->answers->map(
                fn (TeacherClassTestAttemptAnswer $a) => $this->delivery->presentAttemptQuestion($a, false, false)
            )->values();
            $summary['questions'] = $flat;
            $summary['presentation'] = $this->delivery->buildPresentationSections($flat);
        } elseif ($answersVisible) {
            $flat = $attempt->answers->map(
                fn (TeacherClassTestAttemptAnswer $a) => $this->delivery->presentAttemptQuestion($a, true, true)
            )->values();
            $summary['questions'] = $flat;
            $summary['presentation'] = $this->delivery->buildPresentationSections($flat);
        } else {
            $summary['questions'] = [];
            $summary['presentation'] = ['standalone_questions' => [], 'stimulus_groups' => []];
        }

        return $summary;
    }

    private function computeRemainingSeconds(TeacherClassTestAssignment $assignment, TeacherClassTestAttempt $attempt): int
    {
        if (!$attempt->isInProgress()) {
            return 0;
        }
        $now = now();
        $deadline = $attempt->deadline_at;
        if ($assignment->ends_at && (!$deadline || $assignment->ends_at->lt($deadline))) {
            $deadline = $assignment->ends_at;
        }
        if (!$deadline || $deadline->lte($now)) {
            return 0;
        }
        return (int) $now->diffInSeconds($deadline);
    }

    private function normalizeAnswerEntries(array $payload): array
    {
        if (isset($payload['answers']) && is_array($payload['answers'])) {
            $rawList = $payload['answers'];
        } elseif (isset($payload['teacher_class_test_question_id'])) {
            $rawList = [[
                'teacher_class_test_question_id' => $payload['teacher_class_test_question_id'],
                'selected_option_id' => $payload['selected_option_id'] ?? null,
            ]];
        } else {
            $this->abortJson(422, 'invalid_answers_payload', 'Provide at least one answer entry to save.');
        }

        if ($rawList === []) {
            $this->abortJson(422, 'invalid_answers_payload', 'Answers list cannot be empty.');
        }

        $entries = [];
        foreach ($rawList as $item) {
            if (!is_array($item)) {
                $this->abortJson(422, 'invalid_answers_payload', 'Each answer entry must be an object.');
            }
            $tqId = (int) ($item['teacher_class_test_question_id'] ?? 0);
            if ($tqId <= 0) {
                $this->abortJson(422, 'invalid_answers_payload', 'Each answer entry must include a valid teacher_class_test_question_id.');
            }
            $optId = array_key_exists('selected_option_id', $item) && $item['selected_option_id'] !== null && $item['selected_option_id'] !== ''
                ? (int) $item['selected_option_id']
                : null;

            $entries[$tqId] = [
                'teacher_class_test_question_id' => $tqId,
                'selected_option_id' => $optId,
            ];
        }

        return array_values($entries);
    }

    private function abortJson(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
