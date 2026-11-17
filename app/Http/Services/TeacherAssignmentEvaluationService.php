<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassAssignmentEvaluationHistory;
use App\Models\TeacherClassAssignmentRecipient;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TeacherAssignmentEvaluationService
{
    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService,
        private readonly WebUserNotificationService $notificationService
    ) {
    }

    public function evaluate(
        TeacherProfile $profile,
        int $classId,
        int $assignmentId,
        int $studentWebUserId,
        array $payload
    ): array {
        $class = $this->resourceService->resolveOwnedActiveClass($profile, $classId);
        $student = WebUser::query()->select(['id', 'name', 'email'])->find($studentWebUserId);
        if (!$student) {
            $this->resourceService->abortJson(404, 'student_not_found', 'Student record not found.');
        }

        $action = (string) ($payload['action'] ?? '');
        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }
        $feedbackText = array_key_exists('feedback_text', $payload)
            ? ($payload['feedback_text'] !== null && trim((string) $payload['feedback_text']) !== ''
                ? trim((string) $payload['feedback_text'])
                : null)
            : null;
        $hasObtainedInput = array_key_exists('obtained_marks', $payload) && $payload['obtained_marks'] !== null && $payload['obtained_marks'] !== '';
        $obtainedInput = $hasObtainedInput ? round((float) $payload['obtained_marks'], 2) : null;

        $notifyEvent = null;

        /** @var TeacherClassAssignmentEvaluation $evaluation */
        $evaluation = DB::transaction(function () use (
            $profile,
            $class,
            $assignmentId,
            $studentWebUserId,
            $payload,
            $action,
            $reason,
            $feedbackText,
            $hasObtainedInput,
            $obtainedInput,
            &$notifyEvent
        ) {
            /** @var TeacherClassAssignment|null $assignment */
            $assignment = TeacherClassAssignment::query()
                ->where('id', $assignmentId)
                ->where('teacher_class_id', $class->id)
                ->where('teacher_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if (!$assignment) {
                $this->resourceService->abortJson(404, 'assignment_not_found', 'Assignment not found.');
            }

            if ($assignment->status === TeacherClassAssignment::STATUS_ARCHIVED) {
                $this->resourceService->abortJson(422, 'assignment_archived', 'Cannot evaluate an archived assignment.');
            }

            $this->assertStudentAssociatedWithAssignment($class, $assignment, $studentWebUserId);

            /** @var TeacherClassAssignmentSubmission|null $submission */
            $submission = TeacherClassAssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $studentWebUserId)
                ->lockForUpdate()
                ->first();

            /** @var TeacherClassAssignmentEvaluation|null $eval */
            $eval = TeacherClassAssignmentEvaluation::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $studentWebUserId)
                ->lockForUpdate()
                ->first();

            $now = CarbonImmutable::now('Asia/Karachi');
            $maxMarks = $assignment->max_marks !== null ? round((float) $assignment->max_marks, 2) : null;

            $prevStatus = $eval?->status;
            $prevMarks = $eval?->obtained_marks !== null ? round((float) $eval->obtained_marks, 2) : null;
            $prevFeedback = $eval?->feedback_text;

            if ($action === 'draft') {
                $this->assertSubmitted($submission);
                if ($eval && $eval->status === TeacherClassAssignmentEvaluation::STATUS_RELEASED) {
                    $this->resourceService->abortJson(
                        409,
                        'evaluation_already_released',
                        'Released evaluations cannot be reverted to draft. Reopen or use explicit correction with a reason.'
                    );
                }
                if ($obtainedInput !== null) {
                    $this->assertValidMarksRange($obtainedInput, $maxMarks);
                }

                $nextStatus = ($eval && $eval->status === TeacherClassAssignmentEvaluation::STATUS_REOPENED)
                    ? TeacherClassAssignmentEvaluation::STATUS_REOPENED
                    : TeacherClassAssignmentEvaluation::STATUS_DRAFT;

                $eval = $this->upsertEvaluation($eval, [
                    'assignment_id' => $assignment->id,
                    'teacher_class_id' => $class->id,
                    'submission_id' => $submission->id,
                    'student_web_user_id' => $studentWebUserId,
                    'evaluated_by_web_user_id' => $profile->web_user_id,
                    'status' => $nextStatus,
                    'evaluation_type' => TeacherClassAssignmentEvaluation::TYPE_GRADED,
                    'obtained_marks' => $obtainedInput ?? $eval?->obtained_marks,
                    'max_marks_snapshot' => $maxMarks,
                    'feedback_text' => array_key_exists('feedback_text', $payload) ? $feedbackText : $eval?->feedback_text,
                    'submission_version_evaluated' => (int) $submission->submission_version,
                    'draft_saved_at' => $now,
                ]);

                $this->recordHistory($eval, (int) $profile->web_user_id, TeacherClassAssignmentEvaluationHistory::ACTION_DRAFT_SAVED, $prevStatus, $eval->status, $prevMarks, $eval->obtained_marks, $prevFeedback, $eval->feedback_text, $reason);

                return $eval;
            }

            if ($action === 'reopen') {
                if (!$eval || $eval->status !== TeacherClassAssignmentEvaluation::STATUS_RELEASED) {
                    $this->resourceService->abortJson(422, 'evaluation_not_released', 'Only released evaluations can be reopened for correction.');
                }
                if (!$reason || mb_strlen($reason) < 2) {
                    $this->resourceService->abortJson(422, 'correction_reason_required', 'A clear reason (at least 2 characters) is required to reopen an evaluation.');
                }

                $eval->update([
                    'status' => TeacherClassAssignmentEvaluation::STATUS_REOPENED,
                    'evaluated_by_web_user_id' => $profile->web_user_id,
                    'last_correction_reason' => $reason,
                    'reopened_at' => $now,
                ]);

                $this->recordHistory($eval, (int) $profile->web_user_id, TeacherClassAssignmentEvaluationHistory::ACTION_REOPENED, $prevStatus, TeacherClassAssignmentEvaluation::STATUS_REOPENED, $prevMarks, $eval->obtained_marks, $prevFeedback, $eval->feedback_text, $reason);

                return $eval->fresh();
            }

            if ($action === 'finalize_zero') {
                if ($submission && $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED) {
                    $this->resourceService->abortJson(422, 'student_already_submitted', 'Student has submitted work; grade the submission instead of marking as missing zero.');
                }
                if (!$assignment->due_at && $assignment->status !== TeacherClassAssignment::STATUS_CLOSED) {
                    $this->resourceService->abortJson(422, 'assignment_not_past_due', 'Only past-due or closed assignments without submissions can be finalized as zero.');
                }
                if ($assignment->due_at && $assignment->due_at->greaterThan($now) && $assignment->status !== TeacherClassAssignment::STATUS_CLOSED) {
                    $this->resourceService->abortJson(422, 'assignment_not_past_due', 'Cannot finalize missing assignment as zero before its due date passes.');
                }
                if ($maxMarks === null || $maxMarks <= 0) {
                    $this->resourceService->abortJson(422, 'invalid_assignment_max_marks', 'Assignment must have a positive max_marks value before finalizing as zero.');
                }
                if (!$reason || mb_strlen($reason) < 2) {
                    $this->resourceService->abortJson(422, 'correction_reason_required', 'A clear reason is required when finalizing an unsubmitted assignment as zero.');
                }

                $wasPreviouslyReleased = $eval !== null && $eval->released_at !== null;
                $eval = $this->upsertEvaluation($eval, [
                    'assignment_id' => $assignment->id,
                    'teacher_class_id' => $class->id,
                    'submission_id' => null,
                    'student_web_user_id' => $studentWebUserId,
                    'evaluated_by_web_user_id' => $profile->web_user_id,
                    'status' => TeacherClassAssignmentEvaluation::STATUS_RELEASED,
                    'evaluation_type' => TeacherClassAssignmentEvaluation::TYPE_FINALIZED_ZERO,
                    'obtained_marks' => 0.00,
                    'max_marks_snapshot' => $maxMarks,
                    'feedback_text' => $feedbackText ?? 'Finalized as 0 for unsubmitted past-due assignment.',
                    'last_correction_reason' => $reason,
                    'submission_version_evaluated' => null,
                    'released_at' => $now,
                ]);

                $this->recordHistory($eval, (int) $profile->web_user_id, TeacherClassAssignmentEvaluationHistory::ACTION_FINALIZED_ZERO, $prevStatus, $eval->status, $prevMarks, $eval->obtained_marks, $prevFeedback, $eval->feedback_text, $reason);

                $notifyEvent = [
                    'type' => $wasPreviouslyReleased
                        ? WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_CORRECTED
                        : WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_RELEASED,
                    'assignment' => $assignment,
                ];

                return $eval;
            }

            if ($action === 'correct' || ($action === 'release' && $eval && $eval->status === TeacherClassAssignmentEvaluation::STATUS_REOPENED)) {
                if (!$eval || !in_array($eval->status, [TeacherClassAssignmentEvaluation::STATUS_RELEASED, TeacherClassAssignmentEvaluation::STATUS_REOPENED], true)) {
                    $this->resourceService->abortJson(422, 'evaluation_not_correctable', 'Only released or reopened evaluations can be corrected.');
                }
                if (!$reason || mb_strlen($reason) < 2) {
                    $this->resourceService->abortJson(422, 'correction_reason_required', 'A clear correction reason (at least 2 characters) is required.');
                }

                $targetMarks = $hasObtainedInput ? $obtainedInput : ($eval->obtained_marks !== null ? round((float) $eval->obtained_marks, 2) : null);
                if ($eval->evaluation_type === TeacherClassAssignmentEvaluation::TYPE_FINALIZED_ZERO) {
                    if ($targetMarks === null || $targetMarks > 0) {
                        $this->assertSubmitted($submission);
                    }
                    $this->assertValidMarksRange((float) ($targetMarks ?? 0), $maxMarks);
                } else {
                    $this->assertSubmitted($submission);
                    if ($targetMarks === null) {
                        $this->resourceService->abortJson(422, 'marks_required', 'Obtained marks are required to finalize a correction.');
                    }
                    $this->assertValidMarksRange($targetMarks, $maxMarks);
                }

                $eval->update([
                    'submission_id' => $submission && $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED ? $submission->id : $eval->submission_id,
                    'evaluated_by_web_user_id' => $profile->web_user_id,
                    'status' => TeacherClassAssignmentEvaluation::STATUS_RELEASED,
                    'evaluation_type' => ($submission && $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED)
                        ? TeacherClassAssignmentEvaluation::TYPE_GRADED
                        : $eval->evaluation_type,
                    'obtained_marks' => $targetMarks ?? 0.00,
                    'max_marks_snapshot' => $maxMarks,
                    'feedback_text' => array_key_exists('feedback_text', $payload) ? $feedbackText : $eval->feedback_text,
                    'last_correction_reason' => $reason,
                    'submission_version_evaluated' => $submission && $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED
                        ? (int) $submission->submission_version
                        : $eval->submission_version_evaluated,
                    'released_at' => $now,
                ]);

                $this->recordHistory($eval, (int) $profile->web_user_id, TeacherClassAssignmentEvaluationHistory::ACTION_CORRECTED, $prevStatus, TeacherClassAssignmentEvaluation::STATUS_RELEASED, $prevMarks, $eval->obtained_marks, $prevFeedback, $eval->feedback_text, $reason);

                $notifyEvent = [
                    'type' => WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_CORRECTED,
                    'assignment' => $assignment,
                ];

                return $eval->fresh();
            }

            // Default: 'release' (initial evaluation release)
            $this->assertSubmitted($submission);
            if ($eval && $eval->status === TeacherClassAssignmentEvaluation::STATUS_RELEASED) {
                $this->resourceService->abortJson(409, 'evaluation_already_released', 'Evaluation is already released. Use correct with a reason to modify marks or feedback.');
            }

            $targetMarks = $hasObtainedInput ? $obtainedInput : ($eval?->obtained_marks !== null ? round((float) $eval->obtained_marks, 2) : null);
            if ($targetMarks === null) {
                $this->resourceService->abortJson(422, 'marks_required', 'Obtained marks are required when releasing an evaluation.');
            }
            $this->assertValidMarksRange($targetMarks, $maxMarks);

            $eval = $this->upsertEvaluation($eval, [
                'assignment_id' => $assignment->id,
                'teacher_class_id' => $class->id,
                'submission_id' => $submission->id,
                'student_web_user_id' => $studentWebUserId,
                'evaluated_by_web_user_id' => $profile->web_user_id,
                'status' => TeacherClassAssignmentEvaluation::STATUS_RELEASED,
                'evaluation_type' => TeacherClassAssignmentEvaluation::TYPE_GRADED,
                'obtained_marks' => $targetMarks,
                'max_marks_snapshot' => $maxMarks,
                'feedback_text' => array_key_exists('feedback_text', $payload) ? $feedbackText : $eval?->feedback_text,
                'submission_version_evaluated' => (int) $submission->submission_version,
                'released_at' => $now,
            ]);

            $this->recordHistory($eval, (int) $profile->web_user_id, TeacherClassAssignmentEvaluationHistory::ACTION_RELEASED, $prevStatus, TeacherClassAssignmentEvaluation::STATUS_RELEASED, $prevMarks, $eval->obtained_marks, $prevFeedback, $eval->feedback_text, $reason);

            $notifyEvent = [
                'type' => WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_RELEASED,
                'assignment' => $assignment,
            ];

            return $eval;
        });

        if ($notifyEvent !== null) {
            $this->dispatchEvaluationNotification($evaluation, $notifyEvent['assignment'], $notifyEvent['type'], (int) $profile->web_user_id);
        }

        $evaluation->load(['evaluator:id,name', 'histories.actor:id,name']);

        return [
            'success' => true,
            'evaluation' => $this->presentEvaluationForTeacher($evaluation),
            'history' => $this->presentHistories($evaluation),
        ];
    }

    public function presentEvaluationForTeacher(?TeacherClassAssignmentEvaluation $evaluation): ?array
    {
        if (!$evaluation) {
            return null;
        }

        $max = $evaluation->max_marks_snapshot !== null ? round((float) $evaluation->max_marks_snapshot, 2) : null;
        $obt = $evaluation->obtained_marks !== null ? round((float) $evaluation->obtained_marks, 2) : null;

        return [
            'id' => (int) $evaluation->id,
            'status' => $evaluation->status,
            'evaluation_type' => $evaluation->evaluation_type,
            'obtained_marks' => $obt,
            'max_marks' => $max,
            'percentage' => ($obt !== null && $max !== null && $max > 0) ? round(($obt / $max) * 100, 2) : null,
            'feedback_text' => $evaluation->feedback_text,
            'last_correction_reason' => $evaluation->last_correction_reason,
            'submission_version_evaluated' => $evaluation->submission_version_evaluated ? (int) $evaluation->submission_version_evaluated : null,
            'evaluated_by' => $evaluation->evaluator ? [
                'id' => (int) $evaluation->evaluator->id,
                'name' => $evaluation->evaluator->name,
            ] : null,
            'draft_saved_at' => $evaluation->draft_saved_at?->timezone('Asia/Karachi')->toIso8601String(),
            'released_at' => $evaluation->released_at?->timezone('Asia/Karachi')->toIso8601String(),
            'reopened_at' => $evaluation->reopened_at?->timezone('Asia/Karachi')->toIso8601String(),
            'updated_at' => $evaluation->updated_at?->timezone('Asia/Karachi')->toIso8601String(),
        ];
    }

    public function presentEvaluationForStudent(?TeacherClassAssignmentEvaluation $evaluation): ?array
    {
        if (!$evaluation || !$evaluation->isReleasedToStudent()) {
            return null;
        }

        $max = $evaluation->max_marks_snapshot !== null ? round((float) $evaluation->max_marks_snapshot, 2) : null;
        $obt = $evaluation->obtained_marks !== null ? round((float) $evaluation->obtained_marks, 2) : null;

        return [
            'id' => (int) $evaluation->id,
            'status' => 'released',
            'evaluation_type' => $evaluation->evaluation_type,
            'obtained_marks' => $obt,
            'max_marks' => $max,
            'percentage' => ($obt !== null && $max !== null && $max > 0) ? round(($obt / $max) * 100, 2) : null,
            'feedback_text' => $evaluation->feedback_text,
            'released_at' => $evaluation->released_at?->timezone('Asia/Karachi')->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presentHistories(TeacherClassAssignmentEvaluation $evaluation): array
    {
        return $evaluation->histories->map(fn (TeacherClassAssignmentEvaluationHistory $h) => [
            'id' => (int) $h->id,
            'action' => $h->action,
            'previous_status' => $h->previous_status,
            'new_status' => $h->new_status,
            'previous_marks' => $h->previous_marks !== null ? round((float) $h->previous_marks, 2) : null,
            'new_marks' => $h->new_marks !== null ? round((float) $h->new_marks, 2) : null,
            'previous_feedback' => $h->previous_feedback,
            'new_feedback' => $h->new_feedback,
            'reason' => $h->reason,
            'actor' => $h->actor ? [
                'id' => (int) $h->actor->id,
                'name' => $h->actor->name,
            ] : null,
            'created_at' => $h->created_at?->timezone('Asia/Karachi')->toIso8601String(),
        ])->values()->all();
    }

    private function upsertEvaluation(?TeacherClassAssignmentEvaluation $existing, array $attributes): TeacherClassAssignmentEvaluation
    {
        if ($existing) {
            $existing->update($attributes);
            return $existing->fresh();
        }

        return TeacherClassAssignmentEvaluation::create($attributes);
    }

    private function recordHistory(
        TeacherClassAssignmentEvaluation $eval,
        int $actorWebUserId,
        string $action,
        ?string $prevStatus,
        string $newStatus,
        ?float $prevMarks,
        ?float $newMarks,
        ?string $prevFeedback,
        ?string $newFeedback,
        ?string $reason
    ): void {
        TeacherClassAssignmentEvaluationHistory::create([
            'evaluation_id' => $eval->id,
            'assignment_id' => $eval->assignment_id,
            'teacher_class_id' => $eval->teacher_class_id,
            'student_web_user_id' => $eval->student_web_user_id,
            'actor_web_user_id' => $actorWebUserId,
            'action' => $action,
            'previous_status' => $prevStatus,
            'new_status' => $newStatus,
            'previous_marks' => $prevMarks,
            'new_marks' => $newMarks,
            'previous_feedback' => $prevFeedback,
            'new_feedback' => $newFeedback,
            'reason' => $reason,
            'created_at' => CarbonImmutable::now('Asia/Karachi'),
        ]);
    }

    private function assertStudentAssociatedWithAssignment(
        TeacherClass $class,
        TeacherClassAssignment $assignment,
        int $studentWebUserId
    ): void {
        $isRecipient = TeacherClassAssignmentRecipient::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $studentWebUserId)
            ->exists();

        $isEnrolled = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('student_web_user_id', $studentWebUserId)
            ->exists();

        if (!$isRecipient && !$isEnrolled) {
            $this->resourceService->abortJson(404, 'submission_student_not_in_class', 'Student is not associated with this class assignment.');
        }
    }

    private function assertSubmitted(?TeacherClassAssignmentSubmission $submission): void
    {
        if (!$submission || $submission->status !== TeacherClassAssignmentSubmission::STATUS_SUBMITTED) {
            $this->resourceService->abortJson(
                422,
                'submission_required_for_marking',
                'Only submitted work can be evaluated or marked.'
            );
        }
    }

    private function assertValidMarksRange(float $obtainedMarks, ?float $maxMarks): void
    {
        if ($maxMarks === null || $maxMarks <= 0) {
            $this->resourceService->abortJson(
                422,
                'invalid_assignment_max_marks',
                'Assignment must define a positive maximum marks value before grading.'
            );
        }

        if ($obtainedMarks < 0 || $obtainedMarks > $maxMarks) {
            $this->resourceService->abortJson(
                422,
                'invalid_obtained_marks',
                "Obtained marks must be between 0 and {$maxMarks}."
            );
        }
    }

    private function dispatchEvaluationNotification(
        TeacherClassAssignmentEvaluation $evaluation,
        TeacherClassAssignment $assignment,
        string $type,
        int $actorWebUserId
    ): void {
        $now = CarbonImmutable::now('Asia/Karachi');
        if ($type === WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_RELEASED) {
            $claimed = TeacherClassAssignmentEvaluation::query()
                ->where('id', $evaluation->id)
                ->whereNull('notified_released_at')
                ->update(['notified_released_at' => $now]);

            if ($claimed === 0) {
                return;
            }
        } elseif ($evaluation->notified_released_at === null) {
            $evaluation->update(['notified_released_at' => $now]);
        }

        $isCorrection = $type === WebUserNotification::TYPE_ASSIGNMENT_EVALUATION_CORRECTED;
        $title = $isCorrection
            ? 'Evaluation updated: ' . $assignment->title
            : 'Evaluation released: ' . $assignment->title;
        $message = $isCorrection
            ? "Your teacher updated the evaluation for \"{$assignment->title}\". Open your assignment or performance page to review it."
            : "Your evaluation for \"{$assignment->title}\" has been released by your teacher.";

        $this->notificationService->notify(
            (int) $evaluation->student_web_user_id,
            $type,
            $title,
            $message,
            [
                'actor_web_user_id' => $actorWebUserId,
                'teacher_class_id' => (int) $assignment->teacher_class_id,
                'action_url' => '/lms/student/assignments/' . $assignment->id,
                'metadata' => [
                    'assignment_id' => (int) $assignment->id,
                    'evaluation_id' => (int) $evaluation->id,
                ],
            ]
        );
    }
}
