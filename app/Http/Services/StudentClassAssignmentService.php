<?php

namespace App\Http\Services;

use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentAttachment;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassAssignmentRecipient;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassAssignmentSubmissionVersion;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassSubmissionAttachment;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentClassAssignmentService
{
    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService,
        private readonly TeacherClassAssignmentService $assignmentService,
        private readonly PrivateAttachmentStorageService $storageService,
        private readonly WebUserNotificationService $notificationService,
        private readonly TeacherAssignmentEvaluationService $evaluationService,
        private readonly StudentClassAccessService $classAccess
    ) {
    }

    public function listForStudent(WebUser $student, array $filters = []): array
    {
        $classIdFilter = isset($filters['class_id']) ? (int) $filters['class_id'] : null;
        $statusFilter = trim((string) ($filters['status'] ?? ''));

        $allEnrollments = TeacherClassEnrollment::query()
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->when($classIdFilter, fn ($q) => $q->where('teacher_class_id', $classIdFilter))
            ->with(['teacherClass' => fn ($q) => $q->where('is_active', true)->with(['academicClass', 'subject', 'curriculumBoard'])])
            ->get()
            ->filter(fn (TeacherClassEnrollment $e) => $e->teacherClass !== null)
            ->values();

        $activeEnrollments = $allEnrollments->filter(fn (TeacherClassEnrollment $e) => $this->classAccess->hasActiveSubscription((int) $student->id, $e->teacherClass))->values();

        if ($classIdFilter && $activeEnrollments->isEmpty()) {
            $targetClass = TeacherClass::find($classIdFilter);
            if ($targetClass) {
                // If student has no submission in this class, assert access (fails with 403)
                $hasHistoricalSubmission = TeacherClassAssignmentSubmission::where('student_web_user_id', $student->id)
                    ->where('teacher_class_id', $classIdFilter)
                    ->exists();
                if (!$hasHistoricalSubmission) {
                    $this->classAccess->assertStudentAccess($student, $targetClass, 'view_assignments');
                }
            }
        }

        $activeClassIds = $activeEnrollments->pluck('teacher_class_id')->map(fn ($id) => (int) $id)->all();
        // Also include historical class IDs where student has submitted work
        $historicalClassIds = TeacherClassAssignmentSubmission::where('student_web_user_id', $student->id)
            ->when($classIdFilter, fn ($q) => $q->where('teacher_class_id', $classIdFilter))
            ->pluck('teacher_class_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $queryClassIds = array_values(array_unique(array_merge($activeClassIds, $historicalClassIds)));
        if (empty($queryClassIds)) {
            return [
                'success' => true,
                'classes' => [],
                'assignments' => [],
            ];
        }

        $assignments = TeacherClassAssignment::query()
            ->whereIn('teacher_class_id', $queryClassIds)
            ->whereIn('status', [TeacherClassAssignment::STATUS_PUBLISHED, TeacherClassAssignment::STATUS_CLOSED])
            ->where(function ($q) use ($student) {
                $q->where('assignment_mode', TeacherClassAssignment::MODE_ENTIRE_CLASS)
                    ->orWhereHas('recipients', fn ($rq) => $rq->where('student_web_user_id', $student->id));
            })
            ->with(['teacherClass.subject', 'teacherClass.academicClass', 'attachments'])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('published_at')
            ->get();

        $submissions = TeacherClassAssignmentSubmission::query()
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->where('student_web_user_id', $student->id)
            ->with(['activeAttachments'])
            ->get()
            ->keyBy('assignment_id');

        $releasedEvaluations = Schema::hasTable('teacher_class_assignment_evaluations')
            ? TeacherClassAssignmentEvaluation::query()
                ->whereIn('assignment_id', $assignments->pluck('id')->all())
                ->where('student_web_user_id', $student->id)
                ->where('status', TeacherClassAssignmentEvaluation::STATUS_RELEASED)
                ->get()
                ->keyBy('assignment_id')
            : collect();

        $items = $assignments->map(function (TeacherClassAssignment $assignment) use ($submissions, $releasedEvaluations) {
            /** @var TeacherClassAssignmentSubmission|null $submission */
            $submission = $submissions->get($assignment->id);
            $computedStatus = $this->assignmentService->computeStudentStatus($assignment, $submission);
            $releasedEval = $releasedEvaluations->get($assignment->id);

            return [
                'id' => (int) $assignment->id,
                'teacher_class_id' => (int) $assignment->teacher_class_id,
                'class_name' => $assignment->teacherClass?->name,
                'subject_name' => $assignment->teacherClass?->subject?->subject_name,
                'title' => $assignment->title,
                'instructions' => $assignment->instructions,
                'max_marks' => $assignment->max_marks,
                'status' => $assignment->status,
                'computed_status' => $computedStatus,
                'late_policy' => $assignment->late_policy,
                'allow_resubmission' => (bool) $assignment->allow_resubmission,
                'published_at' => $assignment->published_at?->timezone('Asia/Karachi')->toIso8601String(),
                'due_at' => $assignment->due_at?->timezone('Asia/Karachi')->toIso8601String(),
                'closed_at' => $assignment->closed_at?->timezone('Asia/Karachi')->toIso8601String(),
                'attachments_count' => $assignment->attachments->count(),
                'can_submit' => $this->canStudentSubmitOrResubmit($assignment, $submission),
                'submission' => $submission ? [
                    'id' => (int) $submission->id,
                    'status' => $submission->status,
                    'submission_version' => (int) $submission->submission_version,
                    'is_late' => (bool) $submission->is_late,
                    'submitted_at' => $submission->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
                    'last_saved_at' => $submission->last_saved_at?->timezone('Asia/Karachi')->toIso8601String(),
                    'attachments_count' => $submission->activeAttachments->count(),
                ] : null,
                'evaluation' => $this->evaluationService->presentEvaluationForStudent($releasedEval),
            ];
        });

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $items = $items->filter(fn (array $row) => $row['computed_status'] === $statusFilter);
        }

        return [
            'success' => true,
            'classes' => $activeEnrollments->map(fn (TeacherClassEnrollment $e) => [
                'id' => (int) $e->teacher_class_id,
                'name' => $e->teacherClass->name,
                'section' => $e->teacherClass->section,
                'subject_name' => $e->teacherClass->subject?->subject_name,
            ])->values()->all(),
            'assignments' => $items->values()->all(),
        ];
    }

    public function showForStudent(WebUser $student, int $assignmentId): array
    {
        $assignment = $this->resolveTargetedPublishedAssignmentForStudent($student, $assignmentId);
        $assignment->load([
            'teacherClass.academicClass',
            'teacherClass.subject',
            'teacherClass.curriculumBoard',
            'attachments',
        ]);

        $submission = TeacherClassAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $student->id)
            ->with(['activeAttachments', 'versions'])
            ->first();

        $evaluation = Schema::hasTable('teacher_class_assignment_evaluations')
            ? TeacherClassAssignmentEvaluation::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $student->id)
                ->where('status', TeacherClassAssignmentEvaluation::STATUS_RELEASED)
                ->first()
            : null;

        $unitRow = $assignment->book_unit_id
            ? DB::table('book_unit_tbl')->where('id', $assignment->book_unit_id)->select(['id', 'unit_no', 'unit_name'])->first()
            : null;
        $topicRow = $assignment->book_unit_topic_id
            ? DB::table('book_unit_topic_tbl')->where('id', $assignment->book_unit_topic_id)->select(['id', 'topic_no', 'topic_name'])->first()
            : null;

        return [
            'success' => true,
            'assignment' => [
                'id' => (int) $assignment->id,
                'teacher_class_id' => (int) $assignment->teacher_class_id,
                'class_name' => $assignment->teacherClass?->name,
                'subject_name' => $assignment->teacherClass?->subject?->subject_name,
                'title' => $assignment->title,
                'instructions' => $assignment->instructions,
                'max_marks' => $assignment->max_marks,
                'unit' => $unitRow ? ['id' => (int) $unitRow->id, 'unit_no' => $unitRow->unit_no, 'unit_name' => $unitRow->unit_name] : null,
                'topic' => $topicRow ? ['id' => (int) $topicRow->id, 'topic_no' => $topicRow->topic_no, 'topic_name' => $topicRow->topic_name] : null,
                'status' => $assignment->status,
                'computed_status' => $this->assignmentService->computeStudentStatus($assignment, $submission),
                'late_policy' => $assignment->late_policy,
                'allow_resubmission' => (bool) $assignment->allow_resubmission,
                'published_at' => $assignment->published_at?->timezone('Asia/Karachi')->toIso8601String(),
                'due_at' => $assignment->due_at?->timezone('Asia/Karachi')->toIso8601String(),
                'closed_at' => $assignment->closed_at?->timezone('Asia/Karachi')->toIso8601String(),
                'can_submit' => $this->canStudentSubmitOrResubmit($assignment, $submission),
                'attachments' => $assignment->attachments->map(
                    fn (TeacherClassAssignmentAttachment $att) => $this->assignmentService->presentTeacherAttachment(
                        $assignment->teacher_class_id,
                        $assignment->id,
                        $att
                    )
                )->values()->all(),
            ],
            'submission' => $submission ? $this->presentSubmissionForStudent($assignment->id, $submission) : null,
            'evaluation' => $this->evaluationService->presentEvaluationForStudent($evaluation),
        ];
    }

    /**
     * Save draft text and/or upload draft attachments without finalizing submission.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function saveDraft(WebUser $student, int $assignmentId, array $payload, array $files = []): array
    {
        $assignment = $this->resolveTargetedPublishedAssignmentForStudent($student, $assignmentId, true);
        $this->assertAssignmentOpenForWork($assignment);

        $now = CarbonImmutable::now('Asia/Karachi');
        if ($assignment->due_at !== null && $now->greaterThan($assignment->due_at) && $assignment->late_policy === TeacherClassAssignment::LATE_BLOCK) {
            $this->resourceService->abortJson(
                422,
                'assignment_late_blocked',
                'The deadline has passed and late work is not accepted for this assignment.'
            );
        }

        $removeIds = array_values(array_unique(array_map('intval', (array) ($payload['remove_attachment_ids'] ?? []))));
        $filesToDelete = [];

        DB::transaction(function () use ($assignment, $student, $payload, $files, $removeIds, $now, &$filesToDelete) {
            $submission = $this->lockOrCreateSubmission($assignment, $student, $now, $payload['text_response'] ?? null);

            if ($submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED && !$assignment->allow_resubmission) {
                $this->resourceService->abortJson(
                    422,
                    'resubmission_not_allowed',
                    'Resubmission is disabled for this assignment.'
                );
            }

            $activeCount = $submission->activeAttachments()->count();
            $removableCount = empty($removeIds)
                ? 0
                : $submission->activeAttachments()->whereIn('id', $removeIds)->count();

            $this->storageService->validateBatch(
                $files,
                max(0, $activeCount - $removableCount),
                (int) config('teacher_portal.attachments.max_per_submission', 5)
            );

            if (!empty($removeIds)) {
                $removing = $submission->activeAttachments()->whereIn('id', $removeIds)->get();
                foreach ($removing as $att) {
                    if ($submission->first_submitted_at === null) {
                        $filesToDelete[] = [$att->storage_disk, $att->storage_key];
                        $att->delete();
                    } else {
                        // Preserve historical version snapshot files when removing in a draft before resubmission.
                        $att->update(['is_active' => false]);
                    }
                }
            }

            $targetVersion = $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED
                ? $submission->submission_version + 1
                : 1;

            foreach ($files as $file) {
                $stored = $this->storageService->storePrivateFile(
                    $file,
                    "private-lms/classes/{$assignment->teacher_class_id}/assignments/{$assignment->id}/submissions/{$student->id}"
                );
                TeacherClassSubmissionAttachment::create(array_merge($stored, [
                    'submission_id' => $submission->id,
                    'version_number' => $targetVersion,
                    'uploaded_by_web_user_id' => $student->id,
                    'is_active' => true,
                ]));
            }

            $updateData = ['last_saved_at' => $now];
            if (array_key_exists('text_response', $payload)) {
                $updateData['text_response'] = $payload['text_response'];
            }
            $submission->update($updateData);
        });

        foreach ($filesToDelete as [$disk, $key]) {
            $this->storageService->deleteStoredFile($disk, $key);
        }

        return $this->showForStudent($student, $assignmentId);
    }

    /**
     * Submit or resubmit student work transactionally with immutable version history.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function submit(WebUser $student, int $assignmentId, array $payload, array $files = []): array
    {
        $assignment = $this->resolveTargetedPublishedAssignmentForStudent($student, $assignmentId, true);
        $this->assertAssignmentOpenForWork($assignment);

        $now = CarbonImmutable::now('Asia/Karachi');
        $isLate = $assignment->due_at !== null && $now->greaterThan($assignment->due_at);

        if ($isLate && $assignment->late_policy === TeacherClassAssignment::LATE_BLOCK) {
            $this->resourceService->abortJson(
                422,
                'assignment_late_blocked',
                'The deadline has passed and late submissions are not accepted for this assignment.'
            );
        }

        $removeIds = array_values(array_unique(array_map('intval', (array) ($payload['remove_attachment_ids'] ?? []))));
        $filesToDelete = [];
        $submittedVersionNumber = 1;
        $wasResubmission = false;

        DB::transaction(function () use (
            $assignment,
            $student,
            $payload,
            $files,
            $removeIds,
            $now,
            $isLate,
            &$filesToDelete,
            &$submittedVersionNumber,
            &$wasResubmission
        ) {
            $submission = $this->lockOrCreateSubmission($assignment, $student, $now, null);

            if ($submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED && !$assignment->allow_resubmission) {
                $this->resourceService->abortJson(
                    422,
                    'resubmission_not_allowed',
                    'You have already submitted this assignment and resubmission is disabled.'
                );
            }

            $wasResubmission = $submission->first_submitted_at !== null;
            $nextVersion = $wasResubmission ? ((int) $submission->submission_version + 1) : 1;

            $activeCount = $submission->activeAttachments()->count();
            $removableCount = empty($removeIds)
                ? 0
                : $submission->activeAttachments()->whereIn('id', $removeIds)->count();

            $this->storageService->validateBatch(
                $files,
                max(0, $activeCount - $removableCount),
                (int) config('teacher_portal.attachments.max_per_submission', 5)
            );

            $textResponse = array_key_exists('text_response', $payload)
                ? trim((string) ($payload['text_response'] ?? ''))
                : trim((string) ($submission->text_response ?? ''));

            $resultingAttachmentCount = max(0, $activeCount - $removableCount) + count($files);
            if ($textResponse === '' && $resultingAttachmentCount === 0) {
                $this->resourceService->abortJson(
                    422,
                    'empty_submission',
                    'Please enter a text response or attach at least one file before submitting.'
                );
            }

            if (!empty($removeIds)) {
                $removing = $submission->activeAttachments()->whereIn('id', $removeIds)->get();
                foreach ($removing as $att) {
                    if (!$wasResubmission) {
                        $filesToDelete[] = [$att->storage_disk, $att->storage_key];
                        $att->delete();
                    } else {
                        $att->update(['is_active' => false]);
                    }
                }
            }

            foreach ($files as $file) {
                $stored = $this->storageService->storePrivateFile(
                    $file,
                    "private-lms/classes/{$assignment->teacher_class_id}/assignments/{$assignment->id}/submissions/{$student->id}"
                );
                TeacherClassSubmissionAttachment::create(array_merge($stored, [
                    'submission_id' => $submission->id,
                    'version_number' => $nextVersion,
                    'uploaded_by_web_user_id' => $student->id,
                    'is_active' => true,
                ]));
            }

            $activeAttachmentsSnapshot = $submission->activeAttachments()
                ->get()
                ->map(fn (TeacherClassSubmissionAttachment $att) => [
                    'attachment_id' => (int) $att->id,
                    'original_name' => $att->original_name,
                    'mime_type' => $att->mime_type,
                    'extension' => $att->extension,
                    'size_bytes' => (int) $att->size_bytes,
                ])
                ->values()
                ->all();

            $submission->update([
                'status' => TeacherClassAssignmentSubmission::STATUS_SUBMITTED,
                'submission_version' => $nextVersion,
                'text_response' => $textResponse !== '' ? $textResponse : null,
                'is_late' => $isLate,
                'first_submitted_at' => $submission->first_submitted_at ?? $now,
                'submitted_at' => $now,
                'last_saved_at' => $now,
            ]);

            TeacherClassAssignmentSubmissionVersion::create([
                'submission_id' => $submission->id,
                'assignment_id' => $assignment->id,
                'student_web_user_id' => $student->id,
                'version_number' => $nextVersion,
                'text_response' => $textResponse !== '' ? $textResponse : null,
                'is_late' => $isLate,
                'submitted_at' => $now,
                'attachments_snapshot' => $activeAttachmentsSnapshot,
            ]);

            $submittedVersionNumber = $nextVersion;
        });

        foreach ($filesToDelete as [$disk, $key]) {
            $this->storageService->deleteStoredFile($disk, $key);
        }

        // Notify class teacher without exposing submission body or private file keys.
        $assignment->loadMissing('teacherProfile');
        if ($assignment->teacherProfile?->web_user_id) {
            $verb = $wasResubmission ? 'resubmitted' : 'submitted';
            $lateLabel = $isLate ? ' (Late)' : '';
            $this->notificationService->notify(
                (int) $assignment->teacherProfile->web_user_id,
                WebUserNotification::TYPE_ASSIGNMENT_SUBMITTED,
                "Assignment {$verb}: " . $assignment->title,
                "{$student->name} {$verb} \"{$assignment->title}\" (Version {$submittedVersionNumber}){$lateLabel}.",
                [
                    'actor_web_user_id' => $student->id,
                    'teacher_class_id' => $assignment->teacher_class_id,
                    'action_url' => "/lms/teacher/classes/{$assignment->teacher_class_id}/assignments/{$assignment->id}",
                    'metadata' => [
                        'assignment_id' => (int) $assignment->id,
                        'student_web_user_id' => (int) $student->id,
                        'version_number' => $submittedVersionNumber,
                        'is_late' => $isLate,
                    ],
                ]
            );
        }

        return $this->showForStudent($student, $assignmentId);
    }

    public function deleteOwnSubmissionAttachment(WebUser $student, int $assignmentId, int $attachmentId): array
    {
        $assignment = $this->resolveTargetedPublishedAssignmentForStudent($student, $assignmentId, true);
        $this->assertAssignmentOpenForWork($assignment);

        if ($assignment->due_at !== null && CarbonImmutable::now('Asia/Karachi')->greaterThan($assignment->due_at) && $assignment->late_policy === TeacherClassAssignment::LATE_BLOCK) {
            $this->resourceService->abortJson(
                422,
                'assignment_late_blocked',
                'The deadline has passed and submission files cannot be modified.'
            );
        }

        $submission = TeacherClassAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $student->id)
            ->first();

        if (!$submission) {
            $this->resourceService->abortJson(404, 'submission_not_found', 'Submission not found.');
        }

        if ($submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED && !$assignment->allow_resubmission) {
            $this->resourceService->abortJson(422, 'resubmission_not_allowed', 'Cannot remove attachments after submission when resubmission is disabled.');
        }

        $att = TeacherClassSubmissionAttachment::query()
            ->where('id', $attachmentId)
            ->where('submission_id', $submission->id)
            ->where('is_active', true)
            ->first();

        if (!$att) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Attachment not found.');
        }

        if ($submission->first_submitted_at === null) {
            $disk = $att->storage_disk;
            $key = $att->storage_key;
            $att->delete();
            $this->storageService->deleteStoredFile($disk, $key);
        } else {
            $att->update(['is_active' => false]);
        }

        return $this->showForStudent($student, $assignmentId);
    }

    public function streamAssignmentAttachmentForStudent(
        WebUser $student,
        int $assignmentId,
        int $attachmentId
    ): Response|StreamedResponse {
        $assignment = $this->resolveTargetedPublishedAssignmentForStudent($student, $assignmentId, true);

        $att = TeacherClassAssignmentAttachment::query()
            ->where('id', $attachmentId)
            ->where('assignment_id', $assignment->id)
            ->first();

        if (!$att) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Assignment attachment not found.');
        }

        return $this->storageService->streamStoredFile(
            $att->storage_disk,
            $att->storage_key,
            $att->original_name,
            $att->mime_type
        );
    }

    public function streamOwnSubmissionAttachment(
        WebUser $student,
        int $assignmentId,
        int $attachmentId
    ): Response|StreamedResponse {
        $this->resolveTargetedPublishedAssignmentForStudent($student, $assignmentId);

        $att = TeacherClassSubmissionAttachment::query()
            ->where('id', $attachmentId)
            ->where('is_active', true)
            ->whereHas('submission', function ($q) use ($assignmentId, $student) {
                $q->where('assignment_id', $assignmentId)
                    ->where('student_web_user_id', $student->id);
            })
            ->first();

        if (!$att) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Submission attachment not found.');
        }

        return $this->storageService->streamStoredFile(
            $att->storage_disk,
            $att->storage_key,
            $att->original_name,
            $att->mime_type
        );
    }

    /**
     * Serialize per-student concurrent draft/submit operations via the student's existing enrollment record
     * so InnoDB gap-lock deadlocks and uk_tcas_assign_student races cannot occur on first insert.
     */
    private function lockOrCreateSubmission(
        TeacherClassAssignment $assignment,
        WebUser $student,
        CarbonImmutable $now,
        ?string $initialText
    ): TeacherClassAssignmentSubmission {
        $activeEnrollment = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $assignment->teacher_class_id)
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if (!$activeEnrollment) {
            $this->resourceService->abortJson(
                403,
                'forbidden_assignment_access',
                'You must have an active enrollment in this class to submit.'
            );
        }

        $submission = TeacherClassAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $student->id)
            ->lockForUpdate()
            ->first();

        if ($submission) {
            return $submission;
        }

        try {
            return TeacherClassAssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'teacher_class_id' => $assignment->teacher_class_id,
                'student_web_user_id' => $student->id,
                'status' => TeacherClassAssignmentSubmission::STATUS_DRAFT,
                'submission_version' => 1,
                'text_response' => $initialText,
                'last_saved_at' => $now,
            ]);
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($sqlState === '23000') {
                return TeacherClassAssignmentSubmission::query()
                    ->where('assignment_id', $assignment->id)
                    ->where('student_web_user_id', $student->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            throw $e;
        }
    }

    private function resolveTargetedPublishedAssignmentForStudent(WebUser $student, int $assignmentId, bool $requireActiveSubscription = false): TeacherClassAssignment
    {
        $assignment = TeacherClassAssignment::query()->with('teacherClass')->find($assignmentId);
        if (!$assignment || !in_array($assignment->status, [TeacherClassAssignment::STATUS_PUBLISHED, TeacherClassAssignment::STATUS_CLOSED], true)) {
            $this->resourceService->abortJson(404, 'assignment_not_found', 'Assignment not found or not currently available.');
        }

        $enrollment = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $assignment->teacher_class_id)
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->first();

        if (!$enrollment) {
            $this->resourceService->abortJson(403, 'forbidden_assignment_access', 'You must have an active enrollment in this class to view this assignment.');
        }

        if ($assignment->assignment_mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS) {
            $isTargeted = TeacherClassAssignmentRecipient::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $student->id)
                ->exists();

            if (!$isTargeted) {
                $this->resourceService->abortJson(403, 'assignment_not_assigned', 'This assignment is not assigned to your account.');
            }
        }

        // If active subscription is required or student does not have a historical submission:
        $hasSubmission = TeacherClassAssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $student->id)
            ->exists();

        if ($requireActiveSubscription || !$hasSubmission) {
            if ($assignment->teacherClass) {
                $this->classAccess->assertStudentAccess($student, $assignment->teacherClass, 'assignment_access');
            }
        }

        return $assignment;
    }

    private function assertAssignmentOpenForWork(TeacherClassAssignment $assignment): void
    {
        if ($assignment->status === TeacherClassAssignment::STATUS_CLOSED) {
            $this->resourceService->abortJson(422, 'assignment_closed', 'This assignment is closed and no longer accepts submissions.');
        }
        if ($assignment->status !== TeacherClassAssignment::STATUS_PUBLISHED) {
            $this->resourceService->abortJson(422, 'assignment_unavailable', 'This assignment is not currently accepting submissions.');
        }
    }

    private function canStudentSubmitOrResubmit(
        TeacherClassAssignment $assignment,
        ?TeacherClassAssignmentSubmission $submission
    ): bool {
        if ($assignment->status !== TeacherClassAssignment::STATUS_PUBLISHED) {
            return false;
        }
        if ($assignment->due_at && CarbonImmutable::now('Asia/Karachi')->greaterThan($assignment->due_at)) {
            if ($assignment->late_policy === TeacherClassAssignment::LATE_BLOCK) {
                return false;
            }
        }
        if ($submission && $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED) {
            return (bool) $assignment->allow_resubmission;
        }
        return true;
    }

    private function presentSubmissionForStudent(int $assignmentId, TeacherClassAssignmentSubmission $submission): array
    {
        return [
            'id' => (int) $submission->id,
            'status' => $submission->status,
            'submission_version' => (int) $submission->submission_version,
            'text_response' => $submission->text_response,
            'is_late' => (bool) $submission->is_late,
            'first_submitted_at' => $submission->first_submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
            'submitted_at' => $submission->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
            'last_saved_at' => $submission->last_saved_at?->timezone('Asia/Karachi')->toIso8601String(),
            'attachments' => $submission->activeAttachments->map(fn (TeacherClassSubmissionAttachment $att) => [
                'id' => (int) $att->id,
                'version_number' => (int) $att->version_number,
                'original_name' => $att->original_name,
                'mime_type' => $att->mime_type,
                'extension' => $att->extension,
                'size_bytes' => (int) $att->size_bytes,
                'download_url' => "/web_api/auth/student/assignments/{$assignmentId}/submission-attachments/{$att->id}/download",
            ])->values()->all(),
            'versions' => $submission->versions->map(fn ($v) => [
                'id' => (int) $v->id,
                'version_number' => (int) $v->version_number,
                'text_response' => $v->text_response,
                'is_late' => (bool) $v->is_late,
                'submitted_at' => $v->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
                'attachments_snapshot' => $v->attachments_snapshot ?? [],
            ])->values()->all(),
        ];
    }
}
