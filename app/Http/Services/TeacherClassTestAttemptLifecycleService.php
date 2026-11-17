<?php

namespace App\Http\Services;

use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestAttemptAnswer;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestRecipient;
use App\Models\WebUserNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Shared server-authoritative timing, window synchronization, automatic MCQ marking,
 * result visibility policy checks, and deduplicated Phase 7D notifications.
 */
class TeacherClassTestAttemptLifecycleService
{
    public function __construct(private WebUserNotificationService $notifications) {}

    public function canStudentViewMarks(TeacherClassTestAssignment $assignment, TeacherClassTestAttempt $attempt): bool
    {
        if (!$attempt->isFinalized()) {
            return false;
        }

        if ($assignment->results_released_at !== null) {
            return true;
        }

        return match ($assignment->result_visibility) {
            TeacherClassTest::VISIBILITY_AFTER_SUBMISSION => true,
            TeacherClassTest::VISIBILITY_AFTER_CLOSE => $assignment->status === TeacherClassTestAssignment::STATUS_CLOSED
                || ($assignment->ends_at !== null && $assignment->ends_at->lte(now())),
            default => false,
        };
    }

    public function canStudentReviewAnswers(TeacherClassTestAssignment $assignment, TeacherClassTestAttempt $attempt): bool
    {
        return (bool) $assignment->allow_answer_review && $this->canStudentViewMarks($assignment, $attempt);
    }

    public function resolveEffectiveAssignmentStatus(TeacherClassTestAssignment $assignment, ?CarbonInterface $now = null): string
    {
        $now ??= now();

        if ($assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED) {
            return TeacherClassTestAssignment::STATUS_CANCELLED;
        }

        if ($assignment->status === TeacherClassTestAssignment::STATUS_CLOSED || ($assignment->ends_at && $assignment->ends_at->lte($now))) {
            return TeacherClassTestAssignment::STATUS_CLOSED;
        }

        if ($assignment->starts_at && $assignment->starts_at->gt($now)) {
            return TeacherClassTestAssignment::STATUS_SCHEDULED;
        }

        return TeacherClassTestAssignment::STATUS_ACTIVE;
    }

    /**
     * Synchronizes an assignment's lifecycle status, finalizes any expired in-progress
     * attempts, and dispatches availability / after-close result notifications without duplicates.
     */
    public function syncAssignment(TeacherClassTestAssignment $assignment): TeacherClassTestAssignment
    {
        $now = now();
        $effective = $this->resolveEffectiveAssignmentStatus($assignment, $now);

        if ($effective === TeacherClassTestAssignment::STATUS_CLOSED) {
            $this->finalizeExpiredOrClosedForAssignment($assignment->id, $now, true);

            if ($assignment->status !== TeacherClassTestAssignment::STATUS_CLOSED) {
                DB::transaction(function () use ($assignment, $now) {
                    $locked = TeacherClassTestAssignment::lockForUpdate()->find($assignment->id);
                    if (!$locked || $locked->status === TeacherClassTestAssignment::STATUS_CANCELLED) {
                        return;
                    }

                    $locked->status = TeacherClassTestAssignment::STATUS_CLOSED;
                    $locked->closed_at ??= ($locked->ends_at && $locked->ends_at->lte($now)) ? $locked->ends_at : $now;
                    $locked->save();

                    TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $locked->id)
                        ->where('assignment_status', TeacherClassTestRecipient::STATUS_ASSIGNED)
                        ->update([
                            'assignment_status' => TeacherClassTestRecipient::STATUS_EXPIRED,
                            'updated_at' => $now,
                        ]);

                    if ($locked->result_visibility === TeacherClassTest::VISIBILITY_AFTER_CLOSE && $locked->results_released_at === null) {
                        $locked->results_released_at = $now;
                        $locked->save();
                    }
                });
            }

            $fresh = $assignment->fresh();
            if ($fresh->result_visibility === TeacherClassTest::VISIBILITY_AFTER_CLOSE) {
                $this->notifyReleasedResultsOnce($fresh);
            }

            return $fresh;
        }

        // Not closed yet: still finalize any individual attempts whose duration deadline elapsed
        $this->finalizeExpiredOrClosedForAssignment($assignment->id, $now, false);

        if ($effective === TeacherClassTestAssignment::STATUS_ACTIVE && $assignment->status === TeacherClassTestAssignment::STATUS_SCHEDULED) {
            DB::transaction(function () use ($assignment) {
                $locked = TeacherClassTestAssignment::lockForUpdate()->find($assignment->id);
                if ($locked && $locked->status === TeacherClassTestAssignment::STATUS_SCHEDULED) {
                    $locked->status = TeacherClassTestAssignment::STATUS_ACTIVE;
                    $locked->save();
                }
            });

            $fresh = $assignment->fresh();
            $this->notifyTestAvailableOnce($fresh);
            return $fresh;
        }

        if ($effective === TeacherClassTestAssignment::STATUS_ACTIVE) {
            $this->notifyTestAvailableOnce($assignment);
        }

        return $assignment->fresh();
    }

    public function finalizeExpiredAttemptsForRecipient(TeacherClassTestRecipient $recipient): int
    {
        return DB::transaction(function () use ($recipient) {
            $assignment = TeacherClassTestAssignment::lockForUpdate()->find($recipient->teacher_class_test_assignment_id);
            if (!$assignment) {
                return 0;
            }

            $now = now();
            $windowClosed = $assignment->status === TeacherClassTestAssignment::STATUS_CLOSED
                || $assignment->status === TeacherClassTestAssignment::STATUS_CANCELLED
                || ($assignment->ends_at !== null && $assignment->ends_at->lte($now));

            $query = TeacherClassTestAttempt::where('teacher_class_test_recipient_id', $recipient->id)
                ->where('status', TeacherClassTestAttempt::STATUS_IN_PROGRESS)
                ->lockForUpdate();

            if (!$windowClosed) {
                $query->where('deadline_at', '<=', $now);
            }

            $expiredAttempts = $query->get();
            foreach ($expiredAttempts as $attempt) {
                $reason = ($windowClosed && $attempt->deadline_at->gt($assignment->ends_at ?? $now))
                    ? TeacherClassTestAttempt::REASON_WINDOW_CLOSED
                    : TeacherClassTestAttempt::REASON_DEADLINE_EXPIRED;

                $this->finalizeAndGradeAttemptLocked($attempt, $reason, $now, true);
            }

            return $expiredAttempts->count();
        });
    }

    public function finalizeAllExpiredBatch(): array
    {
        $now = now();
        $closedAssignments = 0;
        $activatedAssignments = 0;
        $finalizedAttempts = 0;

        $candidates = TeacherClassTestAssignment::whereIn('status', [
            TeacherClassTestAssignment::STATUS_SCHEDULED,
            TeacherClassTestAssignment::STATUS_ACTIVE,
        ])->get();

        foreach ($candidates as $assignment) {
            $prevStatus = $assignment->status;
            $updated = $this->syncAssignment($assignment);
            if ($prevStatus === TeacherClassTestAssignment::STATUS_SCHEDULED && $updated->status === TeacherClassTestAssignment::STATUS_ACTIVE) {
                $activatedAssignments++;
            } elseif ($prevStatus !== TeacherClassTestAssignment::STATUS_CLOSED && $updated->status === TeacherClassTestAssignment::STATUS_CLOSED) {
                $closedAssignments++;
            }
        }

        $expiredAttemptIds = TeacherClassTestAttempt::where('status', TeacherClassTestAttempt::STATUS_IN_PROGRESS)
            ->where('deadline_at', '<=', $now)
            ->pluck('id');

        foreach ($expiredAttemptIds as $attemptId) {
            DB::transaction(function () use ($attemptId, $now, &$finalizedAttempts) {
                $attempt = TeacherClassTestAttempt::lockForUpdate()->find($attemptId);
                if ($attempt && $attempt->isInProgress() && $attempt->deadline_at->lte($now)) {
                    $this->finalizeAndGradeAttemptLocked($attempt, TeacherClassTestAttempt::REASON_DEADLINE_EXPIRED, $now, true);
                    $finalizedAttempts++;
                }
            });
        }

        // Sync scheduled tests whose window already started or ended
        TeacherClassTest::where('status', TeacherClassTest::STATUS_SCHEDULED)
            ->where('scheduled_start_at', '<=', $now)
            ->where('scheduled_end_at', '>', $now)
            ->update(['status' => TeacherClassTest::STATUS_PUBLISHED, 'updated_at' => $now]);

        TeacherClassTest::whereIn('status', [TeacherClassTest::STATUS_SCHEDULED, TeacherClassTest::STATUS_PUBLISHED])
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_end_at', '<=', $now)
            ->whereDoesntHave('assignments', function ($q) use ($now) {
                $q->whereIn('status', [TeacherClassTestAssignment::STATUS_SCHEDULED, TeacherClassTestAssignment::STATUS_ACTIVE])
                    ->where('ends_at', '>', $now);
            })
            ->update(['status' => TeacherClassTest::STATUS_CLOSED, 'closed_at' => $now, 'updated_at' => $now]);

        return [
            'activated_assignments' => $activatedAssignments,
            'closed_assignments' => $closedAssignments,
            'finalized_attempts' => $finalizedAttempts,
        ];
    }

    /**
     * Performs authoritative, idempotent server-side MCQ grading on an active attempt.
     * Caller MUST hold an active transaction with a row lock on $attempt.
     */
    public function finalizeAndGradeAttemptLocked(
        TeacherClassTestAttempt $attempt,
        string $reason,
        ?CarbonInterface $finalizedAt = null,
        bool $isAuto = false
    ): TeacherClassTestAttempt {
        if ($attempt->isFinalized()) {
            return $attempt;
        }

        $now = $finalizedAt ?? now();
        $answerRows = TeacherClassTestAttemptAnswer::where('attempt_id', $attempt->id)
            ->orderBy('question_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $answered = 0;
        $correct = 0;
        $incorrect = 0;
        $unanswered = 0;
        $obtainedMarks = 0.0;
        $maxMarks = 0.0;

        foreach ($answerRows as $row) {
            $possible = round((float) $row->marks_possible, 2);
            $negative = round((float) $row->negative_marks_possible, 2);
            $maxMarks += $possible;

            if ($row->selected_option_id === null) {
                $row->is_correct = null;
                $row->marks_awarded = 0.00;
                $row->save();
                $unanswered++;
                continue;
            }

            $answered++;
            $isCorrect = $row->correct_option_id_snapshot !== null
                && (int) $row->selected_option_id === (int) $row->correct_option_id_snapshot;

            $awarded = $isCorrect ? $possible : round(-1 * $negative, 2);

            $row->is_correct = $isCorrect;
            $row->marks_awarded = $awarded;
            $row->save();

            $obtainedMarks += $awarded;
            if ($isCorrect) {
                $correct++;
            } else {
                $incorrect++;
            }
        }

        $obtainedMarks = round($obtainedMarks, 2);
        $maxMarks = round($maxMarks, 2);
        $percentage = $maxMarks > 0 ? max(0.0, round(($obtainedMarks / $maxMarks) * 100, 2)) : 0.0;

        $effectiveSubmitAt = ($attempt->deadline_at && $now->gt($attempt->deadline_at))
            ? $attempt->deadline_at
            : $now;
        $elapsed = $attempt->started_at
            ? max(0, $attempt->started_at->diffInSeconds($effectiveSubmitAt))
            : 0;

        $attempt->status = $isAuto ? TeacherClassTestAttempt::STATUS_AUTO_SUBMITTED : TeacherClassTestAttempt::STATUS_SUBMITTED;
        $attempt->submission_reason = $reason;
        $attempt->submitted_at = $effectiveSubmitAt;
        $attempt->finalized_at = $now;
        $attempt->total_questions = $answerRows->count();
        $attempt->answered_questions = $answered;
        $attempt->correct_count = $correct;
        $attempt->incorrect_count = $incorrect;
        $attempt->unanswered_count = $unanswered;
        $attempt->max_marks = $maxMarks;
        $attempt->obtained_marks = $obtainedMarks;
        $attempt->percentage = min(100.0, $percentage);
        $attempt->time_spent_seconds = $elapsed;
        $attempt->save();

        $recipient = TeacherClassTestRecipient::lockForUpdate()->find($attempt->teacher_class_test_recipient_id);
        if ($recipient && $recipient->assignment_status !== TeacherClassTestRecipient::STATUS_REVOKED) {
            $recipient->assignment_status = TeacherClassTestRecipient::STATUS_SUBMITTED;
            $recipient->save();
        }

        return $attempt->fresh(['answers']);
    }

    public function notifyAssignmentCreatedOnce(TeacherClassTestAssignment $assignment, ?int $actorWebUserId = null): void
    {
        $isAvailableNow = $this->resolveEffectiveAssignmentStatus($assignment) === TeacherClassTestAssignment::STATUS_ACTIVE;
        $recipients = TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $assignment->id)
            ->whereNull('notified_assigned_at')
            ->get();

        $now = now();
        $className = $assignment->teacherClass?->name ?? 'Class';

        foreach ($recipients as $recipient) {
            DB::transaction(function () use ($recipient, $assignment, $actorWebUserId, $isAvailableNow, $className, $now) {
                $locked = TeacherClassTestRecipient::lockForUpdate()->find($recipient->id);
                if (!$locked || $locked->notified_assigned_at !== null) {
                    return;
                }

                $type = $isAvailableNow
                    ? WebUserNotification::TYPE_TEST_AVAILABLE
                    : WebUserNotification::TYPE_TEST_ASSIGNED;
                $title = $isAvailableNow ? 'New Test Available' : 'New Test Assigned';
                $message = $isAvailableNow
                    ? "{$assignment->title_snapshot} is now open in {$className} until {$assignment->ends_at->toDayDateTimeString()}."
                    : "{$assignment->title_snapshot} has been scheduled in {$className} starting {$assignment->starts_at->toDayDateTimeString()}.";

                $this->notifications->notify(
                    (int) $locked->student_web_user_id,
                    $type,
                    $title,
                    $message,
                    [
                        'actor_web_user_id' => $actorWebUserId,
                        'teacher_class_id' => (int) $locked->teacher_class_id,
                        'action_url' => "/lms/student/tests/{$assignment->id}",
                        'metadata' => [
                            'teacher_class_test_id' => (int) $assignment->teacher_class_test_id,
                            'teacher_class_test_assignment_id' => (int) $assignment->id,
                            'teacher_class_test_recipient_id' => (int) $locked->id,
                        ],
                    ]
                );

                $locked->notified_assigned_at = $now;
                if ($isAvailableNow) {
                    $locked->notified_available_at = $now;
                }
                $locked->save();
            });
        }
    }

    public function notifyTestAvailableOnce(TeacherClassTestAssignment $assignment): void
    {
        $recipients = TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $assignment->id)
            ->where('assignment_status', '!=', TeacherClassTestRecipient::STATUS_REVOKED)
            ->whereNull('notified_available_at')
            ->get();

        $now = now();
        $className = $assignment->teacherClass?->name ?? 'Class';

        foreach ($recipients as $recipient) {
            DB::transaction(function () use ($recipient, $assignment, $className, $now) {
                $locked = TeacherClassTestRecipient::lockForUpdate()->find($recipient->id);
                if (!$locked || $locked->notified_available_at !== null) {
                    return;
                }

                $this->notifications->notify(
                    (int) $locked->student_web_user_id,
                    WebUserNotification::TYPE_TEST_AVAILABLE,
                    'Test Now Available',
                    "{$assignment->title_snapshot} in {$className} is now open for attempts.",
                    [
                        'teacher_class_id' => (int) $locked->teacher_class_id,
                        'action_url' => "/lms/student/tests/{$assignment->id}",
                        'metadata' => [
                            'teacher_class_test_id' => (int) $assignment->teacher_class_test_id,
                            'teacher_class_test_assignment_id' => (int) $assignment->id,
                        ],
                    ]
                );

                $locked->notified_available_at = $now;
                $locked->save();
            });
        }
    }

    public function notifyReleasedResultsOnce(TeacherClassTestAssignment $assignment, ?int $actorWebUserId = null): void
    {
        $recipients = TeacherClassTestRecipient::where('teacher_class_test_assignment_id', $assignment->id)
            ->whereNull('notified_result_at')
            ->whereHas('attempts', fn ($q) => $q->whereIn('status', [
                TeacherClassTestAttempt::STATUS_SUBMITTED,
                TeacherClassTestAttempt::STATUS_AUTO_SUBMITTED,
                TeacherClassTestAttempt::STATUS_EXPIRED,
            ]))
            ->get();

        $now = now();
        $className = $assignment->teacherClass?->name ?? 'Class';

        foreach ($recipients as $recipient) {
            DB::transaction(function () use ($recipient, $assignment, $actorWebUserId, $className, $now) {
                $locked = TeacherClassTestRecipient::lockForUpdate()->find($recipient->id);
                if (!$locked || $locked->notified_result_at !== null) {
                    return;
                }

                $this->notifications->notify(
                    (int) $locked->student_web_user_id,
                    WebUserNotification::TYPE_TEST_RESULT_RELEASED,
                    'Test Result Released',
                    "Your results for {$assignment->title_snapshot} ({$className}) are now available.",
                    [
                        'actor_web_user_id' => $actorWebUserId,
                        'teacher_class_id' => (int) $locked->teacher_class_id,
                        'action_url' => "/lms/student/tests/{$assignment->id}",
                        'metadata' => [
                            'teacher_class_test_id' => (int) $assignment->teacher_class_test_id,
                            'teacher_class_test_assignment_id' => (int) $assignment->id,
                        ],
                    ]
                );

                $locked->notified_result_at = $now;
                $locked->save();
            });
        }
    }

    private function finalizeExpiredOrClosedForAssignment(int $assignmentId, CarbonInterface $now, bool $windowClosed): int
    {
        $query = TeacherClassTestAttempt::where('teacher_class_test_assignment_id', $assignmentId)
            ->where('status', TeacherClassTestAttempt::STATUS_IN_PROGRESS);

        if (!$windowClosed) {
            $query->where('deadline_at', '<=', $now);
        }

        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, $windowClosed, &$count) {
                $attempt = TeacherClassTestAttempt::lockForUpdate()->find($id);
                if (!$attempt || !$attempt->isInProgress()) {
                    return;
                }
                if (!$windowClosed && $attempt->deadline_at->gt($now)) {
                    return;
                }

                $reason = ($windowClosed && $attempt->deadline_at->gt($now))
                    ? TeacherClassTestAttempt::REASON_WINDOW_CLOSED
                    : TeacherClassTestAttempt::REASON_DEADLINE_EXPIRED;

                $this->finalizeAndGradeAttemptLocked($attempt, $reason, $now, true);
                $count++;
            });
        }

        return $count;
    }
}
