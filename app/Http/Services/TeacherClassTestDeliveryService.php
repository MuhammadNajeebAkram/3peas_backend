<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestAttemptAnswer;
use App\Models\TeacherClassTestQuestion;
use App\Models\TeacherClassTestRecipient;
use App\Models\TeacherProfile;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Teacher Portal — Phase 7D Test Publication, Scheduling, Student Assignment,
 * Result Release & Attempt Monitoring Service.
 */
class TeacherClassTestDeliveryService
{
    public const ALLOWED_VISIBILITIES = [
        TeacherClassTest::VISIBILITY_AFTER_SUBMISSION,
        TeacherClassTest::VISIBILITY_AFTER_CLOSE,
        TeacherClassTest::VISIBILITY_MANUAL_RELEASE,
    ];

    public const ALLOWED_MODES = [
        TeacherClassTestAssignment::MODE_ENTIRE_CLASS,
        TeacherClassTestAssignment::MODE_SELECTED_STUDENTS,
    ];

    public function __construct(
        private TeacherSubjectResourceService $resources,
        private TeacherMcqTestService $tests,
        private TeacherClassTestAttemptLifecycleService $lifecycle
    ) {}

    public function resolveOwnedActiveClass(TeacherProfile $profile, int $classId): TeacherClass
    {
        return $this->resources->resolveOwnedActiveClass($profile, $classId);
    }

    public function publishTest(TeacherClass $class, TeacherProfile $profile, int $testId, array $payload): array
    {
        $test = DB::transaction(function () use ($class, $testId, $payload) {
            /** @var TeacherClassTest|null $test */
            $test = TeacherClassTest::forClass($class)->lockForUpdate()->find($testId);
            if (!$test) {
                $this->resources->abortJson(404, 'test_not_found', 'MCQ test not found.');
            }

            if (!$test->isDraft()) {
                $this->resources->abortJson(409, 'test_not_publishable', 'Only draft tests can be published.');
            }

            $questions = TeacherClassTestQuestion::where('teacher_class_test_id', $test->id)
                ->orderBy('question_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($questions->isEmpty()) {
                $this->resources->abortJson(422, 'empty_test_questions', 'Test must contain at least one valid MCQ before publishing.');
            }

            $calculatedTotalMarks = 0.0;
            foreach ($questions as $q) {
                $options = is_array($q->options_snapshot_json) ? $q->options_snapshot_json : [];
                $optionIds = collect($options)->pluck('id')->map(fn ($id) => (int) $id)->filter()->all();
                if ($optionIds === [] || !$q->correct_option_id_snapshot || !in_array((int) $q->correct_option_id_snapshot, $optionIds, true)) {
                    $this->resources->abortJson(422, 'invalid_question_snapshot', "Question #{$q->question_id} does not have a valid canonical answer snapshot.");
                }
                if ((float) $q->marks <= 0) {
                    $this->resources->abortJson(422, 'invalid_question_marks', "Question #{$q->question_id} must have marks greater than 0.");
                }
                $calculatedTotalMarks += (float) $q->marks;
            }
            $calculatedTotalMarks = round($calculatedTotalMarks, 2);
            if ($calculatedTotalMarks <= 0) {
                $this->resources->abortJson(422, 'invalid_total_marks', 'Total marks must be greater than zero.');
            }

            if (array_key_exists('title', $payload) && trim((string) $payload['title']) !== '') {
                $test->title = trim((string) $payload['title']);
            }
            if (array_key_exists('instructions', $payload)) {
                $test->instructions = ($payload['instructions'] !== null && trim((string) $payload['instructions']) !== '')
                    ? trim((string) $payload['instructions'])
                    : null;
            }

            $schedule = $this->validateScheduleAndPolicy($payload);
            $now = now();

            $test->total_questions = $questions->count();
            $test->total_marks = $calculatedTotalMarks;
            $test->test_version = max(1, (int) ($test->test_version ?? 1));
            $test->scheduled_start_at = $schedule['starts_at'];
            $test->scheduled_end_at = $schedule['ends_at'];
            $test->duration_minutes = $schedule['duration_minutes'];
            $test->max_attempts = $schedule['max_attempts'];
            $test->result_visibility = $schedule['result_visibility'];
            $test->allow_answer_review = $schedule['allow_answer_review'];
            $test->published_at = $now;
            $test->status = $schedule['starts_at']->gt($now)
                ? TeacherClassTest::STATUS_SCHEDULED
                : TeacherClassTest::STATUS_PUBLISHED;
            $test->save();

            return $test->fresh(['questions']);
        });

        return [
            'success' => true,
            'message' => $test->status === TeacherClassTest::STATUS_SCHEDULED
                ? 'MCQ test published and scheduled.'
                : 'MCQ test published and ready for assignment.',
            'data' => $this->tests->presentTestDetail($test),
        ];
    }

    public function assignTest(TeacherClass $class, TeacherProfile $profile, int $testId, array $payload): array
    {
        $created = DB::transaction(function () use ($class, $profile, $testId, $payload) {
            /** @var TeacherClassTest|null $test */
            $test = TeacherClassTest::forClass($class)->lockForUpdate()->find($testId);
            if (!$test) {
                $this->resources->abortJson(404, 'test_not_found', 'MCQ test not found.');
            }

            $this->syncTestLifecycleLocked($test);

            if (!in_array($test->status, [TeacherClassTest::STATUS_SCHEDULED, TeacherClassTest::STATUS_PUBLISHED], true)) {
                $this->resources->abortJson(
                    409,
                    'test_not_assignable',
                    'Only scheduled or published tests can be assigned to students.'
                );
            }

            $mode = (string) ($payload['assignment_mode'] ?? '');
            if (!in_array($mode, self::ALLOWED_MODES, true)) {
                $this->resources->abortJson(422, 'invalid_assignment_mode', 'Assignment mode must be entire_class or selected_students.');
            }

            $merged = [
                'starts_at' => $payload['starts_at'] ?? $test->scheduled_start_at?->toIso8601String(),
                'ends_at' => $payload['ends_at'] ?? $test->scheduled_end_at?->toIso8601String(),
                'duration_minutes' => $payload['duration_minutes'] ?? $test->duration_minutes,
                'max_attempts' => $payload['max_attempts'] ?? $test->max_attempts ?? 1,
                'result_visibility' => $payload['result_visibility'] ?? $test->result_visibility ?? TeacherClassTest::VISIBILITY_MANUAL_RELEASE,
                'allow_answer_review' => $payload['allow_answer_review'] ?? $test->allow_answer_review ?? false,
            ];
            $schedule = $this->validateScheduleAndPolicy($merged);
            $now = now();

            if ($mode === TeacherClassTestAssignment::MODE_ENTIRE_CLASS) {
                $duplicateClassAssignment = TeacherClassTestAssignment::where('teacher_class_test_id', $test->id)
                    ->where('assignment_mode', TeacherClassTestAssignment::MODE_ENTIRE_CLASS)
                    ->whereIn('status', [TeacherClassTestAssignment::STATUS_SCHEDULED, TeacherClassTestAssignment::STATUS_ACTIVE])
                    ->where('ends_at', '>', $now)
                    ->exists();

                if ($duplicateClassAssignment) {
                    $this->resources->abortJson(
                        409,
                        'duplicate_assignment',
                        'An active or scheduled entire-class assignment already exists for this test.'
                    );
                }
            }

            $activeEnrollments = TeacherClassEnrollment::query()
                ->where('teacher_class_id', $class->id)
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->whereHas('student', function ($q) {
                    $q->where('role', 'student')->where('status', 'active');
                })
                ->with('student:id,name,email')
                ->lockForUpdate()
                ->get()
                ->keyBy('student_web_user_id');

            $targetEnrollments = collect();
            if ($mode === TeacherClassTestAssignment::MODE_ENTIRE_CLASS) {
                $targetEnrollments = $activeEnrollments->values();
                if ($targetEnrollments->isEmpty()) {
                    $this->resources->abortJson(
                        422,
                        'no_eligible_students',
                        'This class currently has no actively enrolled students to assign.'
                    );
                }
            } else {
                $rawStudentIds = $payload['student_ids'] ?? [];
                if (!is_array($rawStudentIds) || $rawStudentIds === []) {
                    $this->resources->abortJson(422, 'missing_student_ids', 'Select at least one actively enrolled student.');
                }

                $requestedIds = collect($rawStudentIds)
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => $id > 0)
                    ->unique()
                    ->values();

                if ($requestedIds->isEmpty()) {
                    $this->resources->abortJson(422, 'invalid_student_ids', 'Provide valid student IDs to assign.');
                }

                foreach ($requestedIds as $studentId) {
                    $enrollment = $activeEnrollments->get($studentId);
                    if (!$enrollment) {
                        $this->resources->abortJson(
                            422,
                            'ineligible_student',
                            "Student #{$studentId} does not have an active enrollment in this class."
                        );
                    }
                    $targetEnrollments->push($enrollment);
                }
            }

            // Prevent duplicate active assignment for the same test & student
            $targetStudentIds = $targetEnrollments->pluck('student_web_user_id')->map(fn ($id) => (int) $id)->all();
            $alreadyAssignedStudents = TeacherClassTestRecipient::query()
                ->where('teacher_class_test_id', $test->id)
                ->whereIn('student_web_user_id', $targetStudentIds)
                ->whereIn('assignment_status', [TeacherClassTestRecipient::STATUS_ASSIGNED, TeacherClassTestRecipient::STATUS_IN_PROGRESS])
                ->whereHas('assignment', function ($q) use ($now) {
                    $q->whereIn('status', [TeacherClassTestAssignment::STATUS_SCHEDULED, TeacherClassTestAssignment::STATUS_ACTIVE])
                        ->where('ends_at', '>', $now);
                })
                ->pluck('student_web_user_id')
                ->all();

            if ($alreadyAssignedStudents !== []) {
                $this->resources->abortJson(
                    409,
                    'duplicate_assignment',
                    'One or more selected students already have an active or scheduled assignment for this test.'
                );
            }

            $assignment = new TeacherClassTestAssignment();
            $assignment->teacher_class_test_id = $test->id;
            $assignment->teacher_class_id = $class->id;
            $assignment->teacher_profile_id = $profile->id;
            $assignment->test_version = max(1, (int) ($test->test_version ?? 1));
            $assignment->assignment_mode = $mode;
            $assignment->title_snapshot = $test->title;
            $assignment->instructions_snapshot = $test->instructions;
            $assignment->starts_at = $schedule['starts_at'];
            $assignment->ends_at = $schedule['ends_at'];
            $assignment->duration_minutes = $schedule['duration_minutes'];
            $assignment->max_attempts = $schedule['max_attempts'];
            $assignment->result_visibility = $schedule['result_visibility'];
            $assignment->allow_answer_review = $schedule['allow_answer_review'];
            $assignment->status = $schedule['starts_at']->gt($now)
                ? TeacherClassTestAssignment::STATUS_SCHEDULED
                : TeacherClassTestAssignment::STATUS_ACTIVE;
            $assignment->assigned_count = $targetEnrollments->count();
            $assignment->assigned_by_web_user_id = $profile->web_user_id;
            $assignment->assigned_at = $now;
            $assignment->save();

            foreach ($targetEnrollments as $enrollment) {
                $recipient = new TeacherClassTestRecipient();
                $recipient->teacher_class_test_assignment_id = $assignment->id;
                $recipient->teacher_class_test_id = $test->id;
                $recipient->teacher_class_id = $class->id;
                $recipient->student_web_user_id = (int) $enrollment->student_web_user_id;
                $recipient->teacher_class_enrollment_id = (int) $enrollment->id;
                $recipient->assignment_status = TeacherClassTestRecipient::STATUS_ASSIGNED;
                $recipient->assigned_at = $now;
                $recipient->save();
            }

            return $assignment->fresh(['teacherClass']);
        });

        // Notify assigned students cleanly without duplicate notifications
        $this->lifecycle->notifyAssignmentCreatedOnce($created, (int) $profile->web_user_id);

        return [
            'success' => true,
            'message' => "Test assigned to {$created->assigned_count} student(s).",
            'data' => $this->presentAssignmentSummary($created),
        ];
    }

    public function listAssignments(TeacherClass $class, int $testId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        $this->syncTestLifecycle($test);

        $assignments = TeacherClassTestAssignment::forClass($class)
            ->where('teacher_class_test_id', $test->id)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TeacherClassTestAssignment $a) => $this->lifecycle->syncAssignment($a))
            ->values();

        return [
            'success' => true,
            'test' => $this->tests->presentTestSummary($test->fresh()),
            'data' => $assignments->map(fn (TeacherClassTestAssignment $a) => $this->presentAssignmentSummary($a))->values(),
        ];
    }

    public function showAssignmentMonitoring(TeacherClass $class, int $testId, int $assignmentId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        $assignment = TeacherClassTestAssignment::forClass($class)
            ->where('teacher_class_test_id', $test->id)
            ->findOrFail($assignmentId);

        $assignment = $this->lifecycle->syncAssignment($assignment);

        $recipients = TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $assignment->id)
            ->with([
                'student:id,name,email,status',
                'enrollment:id,status,removed_at,left_at',
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->orderBy('id')
            ->get();

        $summary = $this->computeMonitoringCounts($recipients);

        $studentRows = $recipients->map(function (TeacherClassTestRecipient $r) {
            /** @var TeacherClassTestAttempt|null $latest */
            $latest = $r->attempts->first();
            /** @var TeacherClassTestAttempt|null $bestFinal */
            $bestFinal = $r->attempts
                ->filter(fn (TeacherClassTestAttempt $att) => $att->isFinalized())
                ->sortByDesc('obtained_marks')
                ->first();

            $hasAttempted = $r->attempts->isNotEmpty();
            $hasSubmitted = $r->attempts->contains(fn (TeacherClassTestAttempt $att) => $att->isFinalized());
            $inProgress = $r->attempts->contains(fn (TeacherClassTestAttempt $att) => $att->isInProgress());

            $displayStatus = $inProgress
                ? 'in_progress'
                : ($hasSubmitted
                    ? 'submitted'
                    : ($r->assignment_status === TeacherClassTestRecipient::STATUS_EXPIRED ? 'expired' : 'not_started'));

            return [
                'recipient_id' => (int) $r->id,
                'student' => [
                    'id' => (int) $r->student_web_user_id,
                    'name' => $r->student?->name ?? 'Student',
                    'email' => $r->student?->email,
                ],
                'enrollment_status' => $r->enrollment?->status ?? 'unknown',
                'assignment_status' => $r->assignment_status,
                'monitoring_status' => $displayStatus,
                'attempts_used' => $r->attempts->count(),
                'attempted' => $hasAttempted,
                'latest_attempt' => $latest ? $this->presentAttemptForTeacher($latest) : null,
                'best_submitted_attempt' => $bestFinal ? $this->presentAttemptForTeacher($bestFinal) : null,
                'attempts' => $r->attempts->map(fn (TeacherClassTestAttempt $att) => $this->presentAttemptForTeacher($att))->values(),
            ];
        })->values();

        return [
            'success' => true,
            'test' => $this->tests->presentTestSummary($test->fresh()),
            'assignment' => $this->presentAssignmentSummary($assignment),
            'summary' => $summary,
            'recipients' => $studentRows,
        ];
    }

    public function showStudentAttempt(TeacherClass $class, int $testId, int $assignmentId, int $attemptId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        $assignment = TeacherClassTestAssignment::forClass($class)
            ->where('teacher_class_test_id', $test->id)
            ->findOrFail($assignmentId);

        $assignment = $this->lifecycle->syncAssignment($assignment);

        /** @var TeacherClassTestAttempt $attempt */
        $attempt = TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignment->id)
            ->with([
                'student:id,name,email',
                'answers.testQuestion',
            ])
            ->findOrFail($attemptId);

        $flatItems = $attempt->answers->map(fn (TeacherClassTestAttemptAnswer $ans) => $this->presentAttemptQuestion($ans, true, true))->values();

        return [
            'success' => true,
            'assignment' => $this->presentAssignmentSummary($assignment),
            'student' => [
                'id' => (int) $attempt->student_web_user_id,
                'name' => $attempt->student?->name ?? 'Student',
                'email' => $attempt->student?->email,
            ],
            'attempt' => $this->presentAttemptForTeacher($attempt),
            'questions' => $flatItems,
            'presentation' => $this->buildPresentationSections($flatItems),
        ];
    }

    public function releaseResults(TeacherClass $class, TeacherProfile $profile, int $testId, int $assignmentId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        $assignment = DB::transaction(function () use ($class, $test, $assignmentId) {
            /** @var TeacherClassTestAssignment $assignment */
            $assignment = TeacherClassTestAssignment::forClass($class)
                ->where('teacher_class_test_id', $test->id)
                ->lockForUpdate()
                ->findOrFail($assignmentId);

            if ($assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED) {
                $this->resources->abortJson(409, 'assignment_cancelled', 'Cannot release results for a cancelled assignment.');
            }

            if ($assignment->results_released_at === null) {
                $assignment->results_released_at = now();
                $assignment->save();
            }

            return $assignment->fresh();
        });

        $assignment = $this->lifecycle->syncAssignment($assignment);
        $this->lifecycle->notifyReleasedResultsOnce($assignment, (int) $profile->web_user_id);

        return [
            'success' => true,
            'message' => 'Results released to students.',
            'data' => $this->presentAssignmentSummary($assignment),
        ];
    }

    public function closeAssignment(TeacherClass $class, int $testId, int $assignmentId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        $assignment = DB::transaction(function () use ($class, $test, $assignmentId) {
            /** @var TeacherClassTestAssignment $assignment */
            $assignment = TeacherClassTestAssignment::forClass($class)
                ->where('teacher_class_test_id', $test->id)
                ->lockForUpdate()
                ->findOrFail($assignmentId);

            if ($assignment->status === TeacherClassTestAssignment::STATUS_CLOSED) {
                return $assignment;
            }
            if ($assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED) {
                $this->resources->abortJson(409, 'assignment_cancelled', 'Cancelled assignment cannot be closed.');
            }

            $now = now();
            $inProgress = TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignment->id)
                ->where('status', TeacherClassTestAttempt::STATUS_IN_PROGRESS)
                ->lockForUpdate()
                ->get();

            foreach ($inProgress as $attempt) {
                $this->lifecycle->finalizeAndGradeAttemptLocked(
                    $attempt,
                    TeacherClassTestAttempt::REASON_WINDOW_CLOSED,
                    $now,
                    true
                );
            }

            TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $assignment->id)
                ->where('assignment_status', TeacherClassTestRecipient::STATUS_ASSIGNED)
                ->update([
                    'assignment_status' => TeacherClassTestRecipient::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);

            $assignment->status = TeacherClassTestAssignment::STATUS_CLOSED;
            $assignment->closed_at = $now;
            if ($assignment->result_visibility === TeacherClassTest::VISIBILITY_AFTER_CLOSE && $assignment->results_released_at === null) {
                $assignment->results_released_at = $now;
            }
            $assignment->save();

            return $assignment->fresh();
        });

        if ($assignment->result_visibility === TeacherClassTest::VISIBILITY_AFTER_CLOSE) {
            $this->lifecycle->notifyReleasedResultsOnce($assignment);
        }

        return [
            'success' => true,
            'message' => 'Assignment closed and active attempts finalized.',
            'data' => $this->presentAssignmentSummary($assignment),
        ];
    }

    public function closeTest(TeacherClass $class, int $testId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        if ($test->status === TeacherClassTest::STATUS_ARCHIVED) {
            $this->resources->abortJson(409, 'test_archived', 'Archived test cannot be closed.');
        }
        if ($test->isDraft()) {
            $this->resources->abortJson(409, 'test_is_draft', 'Publish or delete a draft instead of closing it.');
        }

        $openAssignments = TeacherClassTestAssignment::forClass($class)
            ->where('teacher_class_test_id', $test->id)
            ->whereIn('status', [TeacherClassTestAssignment::STATUS_SCHEDULED, TeacherClassTestAssignment::STATUS_ACTIVE])
            ->pluck('id');

        foreach ($openAssignments as $assignmentId) {
            $this->closeAssignment($class, $test->id, (int) $assignmentId);
        }

        DB::transaction(function () use ($class, $testId) {
            $locked = TeacherClassTest::forClass($class)->lockForUpdate()->findOrFail($testId);
            if ($locked->status !== TeacherClassTest::STATUS_CLOSED) {
                $locked->status = TeacherClassTest::STATUS_CLOSED;
                $locked->closed_at ??= now();
                $locked->save();
            }
        });

        return [
            'success' => true,
            'message' => 'Test closed successfully.',
            'data' => $this->tests->presentTestSummary($test->fresh()),
        ];
    }

    public function archiveTest(TeacherClass $class, int $testId): array
    {
        $test = DB::transaction(function () use ($class, $testId) {
            /** @var TeacherClassTest $test */
            $test = TeacherClassTest::forClass($class)->lockForUpdate()->findOrFail($testId);
            $this->syncTestLifecycleLocked($test);

            if (!in_array($test->status, [TeacherClassTest::STATUS_DRAFT, TeacherClassTest::STATUS_CLOSED], true)) {
                $this->resources->abortJson(
                    409,
                    'test_not_archivable',
                    'Close a published or scheduled test before archiving it.'
                );
            }

            $hasOpenAssignments = TeacherClassTestAssignment::where('teacher_class_test_id', $test->id)
                ->whereIn('status', [TeacherClassTestAssignment::STATUS_SCHEDULED, TeacherClassTestAssignment::STATUS_ACTIVE])
                ->lockForUpdate()
                ->exists();

            if ($hasOpenAssignments) {
                $this->resources->abortJson(
                    409,
                    'test_has_open_assignments',
                    'Close all active or scheduled assignments before archiving this test.'
                );
            }

            $test->status = TeacherClassTest::STATUS_ARCHIVED;
            $test->archived_at = now();
            $test->save();

            return $test->fresh();
        });

        return [
            'success' => true,
            'message' => 'Test archived successfully.',
            'data' => $this->tests->presentTestSummary($test),
        ];
    }

    public function presentAssignmentSummary(TeacherClassTestAssignment $assignment): array
    {
        $recipients = $assignment->relationLoaded('recipients')
            ? $assignment->recipients
            : $assignment->recipients()->with(['attempts:id,teacher_class_test_recipient_id,status'])->get();

        $counts = $this->computeMonitoringCounts($recipients);

        return [
            'id' => (int) $assignment->id,
            'teacher_class_test_id' => (int) $assignment->teacher_class_test_id,
            'teacher_class_id' => (int) $assignment->teacher_class_id,
            'test_version' => (int) $assignment->test_version,
            'assignment_mode' => $assignment->assignment_mode,
            'title' => $assignment->title_snapshot,
            'instructions' => $assignment->instructions_snapshot,
            'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $assignment->duration_minutes,
            'max_attempts' => (int) $assignment->max_attempts,
            'result_visibility' => $assignment->result_visibility,
            'allow_answer_review' => (bool) $assignment->allow_answer_review,
            'results_released_at' => $assignment->results_released_at?->toIso8601String(),
            'results_released' => $assignment->results_released_at !== null
                || $assignment->result_visibility === TeacherClassTest::VISIBILITY_AFTER_SUBMISSION
                || ($assignment->result_visibility === TeacherClassTest::VISIBILITY_AFTER_CLOSE && $assignment->status === TeacherClassTestAssignment::STATUS_CLOSED),
            'status' => $assignment->status,
            'assigned_count' => (int) $assignment->assigned_count,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'closed_at' => $assignment->closed_at?->toIso8601String(),
            'counts' => $counts,
        ];
    }

    public function presentAttemptQuestion(
        TeacherClassTestAttemptAnswer $answer,
        bool $includeKeyAndExplanation = false,
        bool $includeAwardedMarks = false
    ): array {
        $q = $answer->testQuestion;
        $meta = is_array($q->metadata_snapshot_json) ? $q->metadata_snapshot_json : [];

        // Strip correct-answer markers from options unless explicitly authorized for review!
        $sanitizedOptions = collect($q->options_snapshot_json ?? [])->map(function ($opt) use ($includeKeyAndExplanation) {
            if (!is_array($opt)) {
                return $opt;
            }
            $row = [
                'id' => isset($opt['id']) ? (int) $opt['id'] : null,
                'option' => $opt['option'] ?? '',
                'option_um' => $opt['option_um'] ?? null,
                'option_key' => $opt['option_key'] ?? null,
                'option_order' => isset($opt['option_order']) ? (int) $opt['option_order'] : null,
            ];
            if ($includeKeyAndExplanation) {
                $row['is_answer'] = (bool) ($opt['is_answer'] ?? false);
            }
            return $row;
        })->values()->all();

        $item = [
            'attempt_answer_id' => (int) $answer->id,
            'teacher_class_test_question_id' => (int) $answer->teacher_class_test_question_id,
            'question_id' => (int) $answer->question_id,
            'question_order' => (int) $answer->question_order,
            'scenario_question_order' => isset($meta['scenario_question_order']) ? (int) $meta['scenario_question_order'] : null,
            'marks' => round((float) $answer->marks_possible, 2),
            'negative_marks' => round((float) $answer->negative_marks_possible, 2),
            'question' => $q->question_text_snapshot,
            'question_um' => $q->question_text_um_snapshot,
            'question_lang' => (int) ($meta['question_lang'] ?? 0),
            'question_um_lang' => (int) ($meta['question_um_lang'] ?? 1),
            'unit' => $meta['unit'] ?? null,
            'topic' => $meta['topic'] ?? null,
            'scenario_group' => ($q->scenario_group_id || $q->scenario_text_snapshot || $q->scenario_text_um_snapshot || $q->scenario_image_snapshot) ? [
                'id' => $q->scenario_group_id ? (int) $q->scenario_group_id : null,
                'title' => $meta['scenario_title'] ?? null,
                'scenario_text' => $q->scenario_text_snapshot,
                'scenario_text_um' => $q->scenario_text_um_snapshot,
                'scenario_image' => $this->sanitizeMediaUrl($q->scenario_image_snapshot),
            ] : null,
            'options' => $sanitizedOptions,
            'selected_option_id' => $answer->selected_option_id ? (int) $answer->selected_option_id : null,
            'selected_option_key' => $answer->selected_option_key,
            'answered_at' => $answer->answered_at?->toIso8601String(),
        ];

        if ($includeAwardedMarks) {
            $item['is_correct'] = $answer->is_correct;
            $item['marks_awarded'] = round((float) $answer->marks_awarded, 2);
        }

        if ($includeKeyAndExplanation) {
            $item['correct_option_id'] = $answer->correct_option_id_snapshot ? (int) $answer->correct_option_id_snapshot : null;
            $item['correct_option_key'] = $answer->correct_option_key_snapshot;
            $item['explanation'] = $q->explanation_snapshot;
            $item['explanation_um'] = $q->explanation_um_snapshot;
        }

        return $item;
    }

    public function buildPresentationSections(Collection $items): array
    {
        $standalone = [];
        $stimulusMap = [];
        $stimulusOrder = [];

        foreach ($items as $item) {
            $groupId = (int) ($item['scenario_group']['id'] ?? 0);
            if ($groupId > 0) {
                if (!isset($stimulusMap[$groupId])) {
                    $stimulusMap[$groupId] = [
                        'item_type' => 'stimulus_group',
                        'scenario_group_id' => $groupId,
                        'scenario_group' => $item['scenario_group'],
                        'questions' => [],
                    ];
                    $stimulusOrder[] = $groupId;
                }
                $stimulusMap[$groupId]['questions'][] = $item;
            } else {
                $standalone[] = $item;
            }
        }

        $groups = collect($stimulusOrder)->map(fn (int $gid) => $stimulusMap[$gid])->values();

        return [
            'standalone_questions' => array_values($standalone),
            'stimulus_groups' => $groups,
        ];
    }

    private function validateScheduleAndPolicy(array $payload): array
    {
        $rawStart = $payload['starts_at'] ?? null;
        $rawEnd = $payload['ends_at'] ?? null;

        if (!$rawStart || !$rawEnd) {
            $this->resources->abortJson(422, 'invalid_schedule_window', 'Both start date/time and end date/time are required.');
        }

        try {
            $startsAt = Carbon::parse((string) $rawStart)->setTimezone(config('app.timezone'));
            $endsAt = Carbon::parse((string) $rawEnd)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            $this->resources->abortJson(422, 'invalid_schedule_window', 'Invalid start or end date/time format.');
        }

        if ($endsAt->lte($startsAt)) {
            $this->resources->abortJson(422, 'invalid_schedule_window', 'End date/time must be strictly after start date/time.');
        }

        if ($endsAt->lte(now())) {
            $this->resources->abortJson(422, 'invalid_schedule_window', 'End date/time must be in the future.');
        }

        $duration = isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : 0;
        if ($duration < 1 || $duration > 600) {
            $this->resources->abortJson(422, 'invalid_duration_minutes', 'Examination duration must be between 1 and 600 minutes.');
        }

        $maxAttempts = isset($payload['max_attempts']) ? (int) $payload['max_attempts'] : 1;
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            $this->resources->abortJson(422, 'invalid_max_attempts', 'Maximum attempts must be between 1 and 10.');
        }

        $visibility = (string) ($payload['result_visibility'] ?? TeacherClassTest::VISIBILITY_MANUAL_RELEASE);
        if (!in_array($visibility, self::ALLOWED_VISIBILITIES, true)) {
            $this->resources->abortJson(422, 'invalid_result_visibility', 'Invalid result visibility policy.');
        }

        $allowReview = filter_var($payload['allow_answer_review'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_minutes' => $duration,
            'max_attempts' => $maxAttempts,
            'result_visibility' => $visibility,
            'allow_answer_review' => $allowReview,
        ];
    }

    private function findOwnedTest(TeacherClass $class, int $testId): TeacherClassTest
    {
        $test = TeacherClassTest::forClass($class)->find($testId);
        if (!$test) {
            $this->resources->abortJson(404, 'test_not_found', 'MCQ test not found.');
        }
        return $test;
    }

    private function syncTestLifecycle(TeacherClassTest $test): void
    {
        if (!in_array($test->status, [TeacherClassTest::STATUS_SCHEDULED, TeacherClassTest::STATUS_PUBLISHED], true)) {
            return;
        }
        DB::transaction(function () use ($test) {
            $locked = TeacherClassTest::lockForUpdate()->find($test->id);
            if ($locked) {
                $this->syncTestLifecycleLocked($locked);
            }
        });
    }

    private function syncTestLifecycleLocked(TeacherClassTest $test): void
    {
        $now = now();
        if ($test->status === TeacherClassTest::STATUS_SCHEDULED
            && $test->scheduled_start_at
            && $test->scheduled_start_at->lte($now)
            && (!$test->scheduled_end_at || $test->scheduled_end_at->gt($now))) {
            $test->status = TeacherClassTest::STATUS_PUBLISHED;
            $test->save();
        }
    }

    private function presentAttemptForTeacher(TeacherClassTestAttempt $attempt): array
    {
        return [
            'id' => (int) $attempt->id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status,
            'submission_reason' => $attempt->submission_reason,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'deadline_at' => $attempt->deadline_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'finalized_at' => $attempt->finalized_at?->toIso8601String(),
            'total_questions' => (int) $attempt->total_questions,
            'answered_questions' => (int) $attempt->answered_questions,
            'correct_count' => (int) $attempt->correct_count,
            'incorrect_count' => (int) $attempt->incorrect_count,
            'unanswered_count' => (int) $attempt->unanswered_count,
            'max_marks' => round((float) $attempt->max_marks, 2),
            'obtained_marks' => round((float) $attempt->obtained_marks, 2),
            'percentage' => round((float) $attempt->percentage, 2),
            'time_spent_seconds' => (int) $attempt->time_spent_seconds,
        ];
    }

    private function computeMonitoringCounts(Collection $recipients): array
    {
        $assigned = $recipients->count();
        $attempted = 0;
        $submitted = 0;
        $inProgress = 0;
        $notStarted = 0;
        $expired = 0;

        foreach ($recipients as $r) {
            $attempts = $r->attempts ?? collect();
            $hasSubmitted = $attempts->contains(fn ($a) => in_array($a->status, [
                TeacherClassTestAttempt::STATUS_SUBMITTED,
                TeacherClassTestAttempt::STATUS_AUTO_SUBMITTED,
                TeacherClassTestAttempt::STATUS_EXPIRED,
            ], true));
            $hasActive = $attempts->contains(fn ($a) => $a->status === TeacherClassTestAttempt::STATUS_IN_PROGRESS);

            if ($attempts->isNotEmpty()) {
                $attempted++;
            }
            if ($hasActive) {
                $inProgress++;
            } elseif ($hasSubmitted) {
                $submitted++;
            } elseif ($r->assignment_status === TeacherClassTestRecipient::STATUS_EXPIRED) {
                $expired++;
            } else {
                $notStarted++;
            }
        }

        return [
            'assigned_count' => $assigned,
            'attempted_count' => $attempted,
            'submitted_count' => $submitted,
            'in_progress_count' => $inProgress,
            'not_started_count' => $notStarted,
            'expired_count' => $expired,
        ];
    }

    private function sanitizeMediaUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $trimmed = trim($value);
        return preg_match('#^https?://#i', $trimmed) ? $trimmed : null;
    }
}
