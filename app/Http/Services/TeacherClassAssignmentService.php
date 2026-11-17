<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentAttachment;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassAssignmentRecipient;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassSubmissionAttachment;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherClassAssignmentService
{
    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService,
        private readonly PrivateAttachmentStorageService $storageService,
        private readonly WebUserNotificationService $notificationService,
        private readonly TeacherAssignmentEvaluationService $evaluationService
    ) {
    }

    public function listAssignments(TeacherProfile $profile, int $classId, array $filters = []): array
    {
        $class = $this->resolveEditableClass($profile, $classId);

        $query = TeacherClassAssignment::query()
            ->where('teacher_class_id', $class->id)
            ->withCount(['attachments', 'recipients', 'submissions'])
            ->withCount([
                'submissions as submitted_count' => fn ($q) => $q->where('status', TeacherClassAssignmentSubmission::STATUS_SUBMITTED),
                'submissions as late_submitted_count' => fn ($q) => $q->where('status', TeacherClassAssignmentSubmission::STATUS_SUBMITTED)->where('is_late', true),
            ]);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', TeacherClassAssignment::STATUS_ARCHIVED);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('instructions', 'like', $like);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 50);
        $paginator = $query
            ->orderByRaw("CASE WHEN status = 'published' THEN 0 WHEN status = 'scheduled' THEN 1 WHEN status = 'draft' THEN 2 WHEN status = 'closed' THEN 3 ELSE 4 END")
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $unitNames = $this->loadUnitMap($paginator->getCollection()->pluck('book_unit_id')->filter()->all());
        $topicNames = $this->loadTopicMap($paginator->getCollection()->pluck('book_unit_topic_id')->filter()->all());
        $activeEnrolledCount = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->count();

        $items = $paginator->getCollection()
            ->map(fn (TeacherClassAssignment $a) => $this->presentAssignmentSummary($a, $unitNames, $topicNames, $activeEnrolledCount))
            ->values()
            ->all();

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'curriculum_options' => $this->curriculumOptionsForClass($class),
            'active_students' => $this->listActiveStudents($class),
            'assignments' => $items,
            'pagination' => $this->resourceService->presentPagination($paginator),
        ];
    }

    public function curriculumOptionsForClass(TeacherClass $class): array
    {
        if ((int) $class->curriculum_board_id <= 0 || (int) $class->class_id <= 0 || (int) $class->subject_id <= 0) {
            return ['units' => [], 'topics_by_unit' => new \stdClass()];
        }

        $units = DB::table('book_unit_tbl as u')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id)
            ->select(['u.id', 'u.unit_no', 'u.unit_name', 'b.book_name'])
            ->orderBy('b.id')
            ->orderBy('u.unit_no')
            ->orderBy('u.id')
            ->get();

        $unitIds = $units->pluck('id')->all();
        $topicsByUnit = [];
        if (!empty($unitIds)) {
            $topics = DB::table('book_unit_topic_tbl')
                ->whereIn('unit_id', $unitIds)
                ->where('activate', 1)
                ->select(['id', 'unit_id', 'topic_no', 'topic_name', 'topic_name_um'])
                ->orderBy('unit_id')
                ->orderBy('topic_no')
                ->orderBy('id')
                ->get();

            foreach ($topics as $t) {
                $topicsByUnit[(string) $t->unit_id][] = [
                    'id' => (int) $t->id,
                    'unit_id' => (int) $t->unit_id,
                    'topic_no' => $t->topic_no,
                    'topic_name' => $t->topic_name,
                    'topic_name_um' => $t->topic_name_um,
                ];
            }
        }

        return [
            'units' => $units->map(fn ($u) => [
                'id' => (int) $u->id,
                'unit_no' => $u->unit_no,
                'unit_name' => $u->unit_name,
                'book_name' => $u->book_name,
            ])->values()->all(),
            'topics_by_unit' => empty($topicsByUnit) ? new \stdClass() : $topicsByUnit,
        ];
    }

    public function getAssignmentDetail(TeacherProfile $profile, int $classId, int $assignmentId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);
        $assignment->load([
            'attachments',
            'recipients.student:id,name,email',
        ]);

        $unitNames = $this->loadUnitMap(array_filter([$assignment->book_unit_id]));
        $topicNames = $this->loadTopicMap(array_filter([$assignment->book_unit_topic_id]));
        $activeEnrolledCount = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->count();

        $submissionStats = $this->computeSubmissionStats($assignment);

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'assignment' => array_merge(
                $this->presentAssignmentSummary($assignment, $unitNames, $topicNames, $activeEnrolledCount),
                [
                    'attachments' => $assignment->attachments->map(
                        fn (TeacherClassAssignmentAttachment $att) => $this->presentTeacherAttachment($class->id, $assignment->id, $att)
                    )->values()->all(),
                    'recipients' => $assignment->recipients->map(fn (TeacherClassAssignmentRecipient $r) => [
                        'id' => (int) $r->id,
                        'student_web_user_id' => (int) $r->student_web_user_id,
                        'student_name' => $r->student?->name,
                        'student_email' => $r->student?->email,
                        'assigned_at' => $r->assigned_at?->toIso8601String(),
                    ])->values()->all(),
                    'submission_stats' => $submissionStats,
                ]
            ),
            'curriculum_options' => $this->curriculumOptionsForClass($class),
            'active_students' => $this->listActiveStudents($class),
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function createAssignment(TeacherProfile $profile, int $classId, array $payload, array $files = []): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $this->storageService->validateBatch(
            $files,
            0,
            (int) config('teacher_portal.attachments.max_per_assignment', 5)
        );

        [$unitId, $topicId] = $this->validateScopedUnitAndTopic(
            $class,
            isset($payload['book_unit_id']) ? (int) $payload['book_unit_id'] : null,
            isset($payload['book_unit_topic_id']) ? (int) $payload['book_unit_topic_id'] : null
        );

        $action = (string) ($payload['action'] ?? 'draft'); // draft | schedule | publish
        [$status, $scheduledAt, $publishedAt, $dueAt] = $this->resolveCreateLifecycle($action, $payload);

        $mode = (string) ($payload['assignment_mode'] ?? TeacherClassAssignment::MODE_ENTIRE_CLASS);
        $selectedStudentIds = $this->extractStudentIds($payload['student_ids'] ?? []);
        $shouldPersistRecipients = $mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS
            || $status === TeacherClassAssignment::STATUS_PUBLISHED;
        $recipientsData = $shouldPersistRecipients
            ? $this->resolveEligibleRecipients($class, $mode, $selectedStudentIds, $status === TeacherClassAssignment::STATUS_PUBLISHED)
            : [];

        $assignment = DB::transaction(function () use (
            $class,
            $profile,
            $payload,
            $unitId,
            $topicId,
            $mode,
            $status,
            $scheduledAt,
            $publishedAt,
            $dueAt,
            $recipientsData,
            $shouldPersistRecipients,
            $files
        ) {
            $assignment = TeacherClassAssignment::create([
                'teacher_class_id' => $class->id,
                'teacher_profile_id' => $profile->id,
                'created_by_web_user_id' => $profile->web_user_id,
                'title' => trim((string) $payload['title']),
                'instructions' => trim((string) $payload['instructions']),
                'book_unit_id' => $unitId,
                'book_unit_topic_id' => $topicId,
                'max_marks' => isset($payload['max_marks']) && $payload['max_marks'] !== '' ? (float) $payload['max_marks'] : null,
                'assignment_mode' => $mode,
                'status' => $status,
                'late_policy' => (string) ($payload['late_policy'] ?? TeacherClassAssignment::LATE_ALLOW),
                'allow_resubmission' => array_key_exists('allow_resubmission', $payload) ? (bool) $payload['allow_resubmission'] : true,
                'scheduled_publish_at' => $scheduledAt,
                'published_at' => $publishedAt,
                'due_at' => $dueAt,
            ]);

            if ($shouldPersistRecipients) {
                $this->syncRecipients($assignment, $recipientsData, (int) $profile->web_user_id);
            }

            foreach ($files as $file) {
                $stored = $this->storageService->storePrivateFile(
                    $file,
                    "private-lms/classes/{$class->id}/assignments/{$assignment->id}"
                );
                TeacherClassAssignmentAttachment::create(array_merge($stored, [
                    'assignment_id' => $assignment->id,
                    'uploaded_by_web_user_id' => $profile->web_user_id,
                ]));
            }

            return $assignment;
        });

        if ($assignment->status === TeacherClassAssignment::STATUS_PUBLISHED) {
            $this->dispatchPublishedNotifications($assignment);
        }

        return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function updateAssignment(TeacherProfile $profile, int $classId, int $assignmentId, array $payload, array $files = []): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if ($assignment->status === TeacherClassAssignment::STATUS_ARCHIVED) {
            $this->resourceService->abortJson(422, 'assignment_archived', 'Archived assignments cannot be edited.');
        }

        $existingCount = $assignment->attachments()->count();
        $removeIds = array_values(array_unique(array_map('intval', (array) ($payload['remove_attachment_ids'] ?? []))));
        $removableCount = empty($removeIds)
            ? 0
            : $assignment->attachments()->whereIn('id', $removeIds)->count();

        $this->storageService->validateBatch(
            $files,
            max(0, $existingCount - $removableCount),
            (int) config('teacher_portal.attachments.max_per_assignment', 5)
        );

        [$unitId, $topicId] = $this->validateScopedUnitAndTopic(
            $class,
            array_key_exists('book_unit_id', $payload)
                ? ($payload['book_unit_id'] ? (int) $payload['book_unit_id'] : null)
                : $assignment->book_unit_id,
            array_key_exists('book_unit_topic_id', $payload)
                ? ($payload['book_unit_topic_id'] ? (int) $payload['book_unit_topic_id'] : null)
                : $assignment->book_unit_topic_id
        );

        $dueAt = array_key_exists('due_at', $payload)
            ? $this->parseNullableKarachiDateTime($payload['due_at'], 'due_at')
            : $assignment->due_at?->toImmutable();

        $mode = (string) ($payload['assignment_mode'] ?? $assignment->assignment_mode);
        $hasSubmittedWork = TeacherClassAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->whereNotNull('first_submitted_at')
            ->exists();

        if ($hasSubmittedWork) {
            $nextTitle = array_key_exists('title', $payload) ? trim((string) $payload['title']) : $assignment->title;
            $nextInstructions = array_key_exists('instructions', $payload) ? trim((string) $payload['instructions']) : $assignment->instructions;
            $nextMaxMarks = array_key_exists('max_marks', $payload)
                ? ($payload['max_marks'] !== null && $payload['max_marks'] !== '' ? (float) $payload['max_marks'] : null)
                : ($assignment->max_marks !== null ? (float) $assignment->max_marks : null);
            $currentMaxMarks = $assignment->max_marks !== null ? (float) $assignment->max_marks : null;

            $promptChanged = $nextTitle !== $assignment->title
                || $nextInstructions !== $assignment->instructions
                || $nextMaxMarks !== $currentMaxMarks
                || $mode !== $assignment->assignment_mode
                || !empty($removeIds);

            if ($promptChanged) {
                $this->resourceService->abortJson(
                    422,
                    'assignment_submissions_exist_immutable',
                    'Cannot modify title, instructions, reference marks, assignment mode, or remove attachments after students have already submitted work.'
                );
            }
        }

        $scheduledAt = $assignment->scheduled_publish_at?->toImmutable();
        $nextStatus = $assignment->status;
        if ($assignment->status === TeacherClassAssignment::STATUS_DRAFT || $assignment->status === TeacherClassAssignment::STATUS_SCHEDULED) {
            if (array_key_exists('scheduled_publish_at', $payload)) {
                $scheduledAt = $this->parseNullableKarachiDateTime($payload['scheduled_publish_at'], 'scheduled_publish_at');
                if ($scheduledAt && $scheduledAt->lessThanOrEqualTo(CarbonImmutable::now('Asia/Karachi'))) {
                    $this->resourceService->abortJson(422, 'invalid_schedule_time', 'Scheduled publish time must be in the future.');
                }
                $nextStatus = $scheduledAt ? TeacherClassAssignment::STATUS_SCHEDULED : TeacherClassAssignment::STATUS_DRAFT;
            }
        }

        $shouldResyncRecipients = array_key_exists('assignment_mode', $payload) || array_key_exists('student_ids', $payload);
        $recipientsData = null;
        if ($shouldResyncRecipients) {
            if ($mode === TeacherClassAssignment::MODE_ENTIRE_CLASS && $nextStatus !== TeacherClassAssignment::STATUS_PUBLISHED) {
                $recipientsData = [];
            } else {
                $selectedIds = array_key_exists('student_ids', $payload)
                    ? $this->extractStudentIds($payload['student_ids'] ?? [])
                    : $assignment->recipients()->pluck('student_web_user_id')->map(fn ($id) => (int) $id)->all();

                if ($hasSubmittedWork && $mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS && array_key_exists('student_ids', $payload)) {
                    $submittedIds = TeacherClassAssignmentSubmission::query()
                        ->where('assignment_id', $assignment->id)
                        ->whereNotNull('first_submitted_at')
                        ->pluck('student_web_user_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    $missingSubmitted = array_diff($submittedIds, $selectedIds);
                    if (!empty($missingSubmitted)) {
                        $this->resourceService->abortJson(
                            422,
                            'cannot_remove_submitted_recipient',
                            'Students who have already submitted cannot be removed from this assignment.'
                        );
                    }
                }

                $recipientsData = $this->resolveEligibleRecipients(
                    $class,
                    $mode,
                    $selectedIds,
                    $nextStatus === TeacherClassAssignment::STATUS_PUBLISHED
                );
            }
        }

        if ($dueAt && $scheduledAt && $dueAt->lessThanOrEqualTo($scheduledAt)) {
            $this->resourceService->abortJson(422, 'invalid_due_date', 'Due date must be after the scheduled publish date.');
        }

        $now = CarbonImmutable::now('Asia/Karachi');
        $resetDueReminder = $dueAt !== null
            && ($assignment->due_at === null || !$dueAt->equalTo($assignment->due_at))
            && $dueAt->greaterThan($now->addHours(24));

        $filesToDelete = [];
        DB::transaction(function () use (
            $assignment,
            $class,
            $profile,
            $payload,
            $unitId,
            $topicId,
            $mode,
            $nextStatus,
            $scheduledAt,
            $dueAt,
            $resetDueReminder,
            $removeIds,
            $files,
            $recipientsData,
            &$filesToDelete
        ) {
            $assignment->update([
                'title' => array_key_exists('title', $payload) ? trim((string) $payload['title']) : $assignment->title,
                'instructions' => array_key_exists('instructions', $payload) ? trim((string) $payload['instructions']) : $assignment->instructions,
                'book_unit_id' => $unitId,
                'book_unit_topic_id' => $topicId,
                'max_marks' => array_key_exists('max_marks', $payload)
                    ? ($payload['max_marks'] !== null && $payload['max_marks'] !== '' ? (float) $payload['max_marks'] : null)
                    : $assignment->max_marks,
                'assignment_mode' => $mode,
                'status' => $nextStatus,
                'late_policy' => (string) ($payload['late_policy'] ?? $assignment->late_policy),
                'allow_resubmission' => array_key_exists('allow_resubmission', $payload)
                    ? (bool) $payload['allow_resubmission']
                    : $assignment->allow_resubmission,
                'scheduled_publish_at' => $scheduledAt,
                'due_at' => $dueAt,
                'reminded_due_at' => $resetDueReminder ? null : $assignment->reminded_due_at,
            ]);

            if (!empty($removeIds)) {
                $removing = $assignment->attachments()->whereIn('id', $removeIds)->get();
                foreach ($removing as $att) {
                    $filesToDelete[] = [$att->storage_disk, $att->storage_key];
                    $att->delete();
                }
            }

            foreach ($files as $file) {
                $stored = $this->storageService->storePrivateFile(
                    $file,
                    "private-lms/classes/{$class->id}/assignments/{$assignment->id}"
                );
                TeacherClassAssignmentAttachment::create(array_merge($stored, [
                    'assignment_id' => $assignment->id,
                    'uploaded_by_web_user_id' => $profile->web_user_id,
                ]));
            }

            if ($recipientsData !== null) {
                $this->syncRecipients($assignment, $recipientsData, (int) $profile->web_user_id);
            }
        });

        foreach ($filesToDelete as [$disk, $key]) {
            $this->storageService->deleteStoredFile($disk, $key);
        }

        if ($assignment->fresh()->status === TeacherClassAssignment::STATUS_PUBLISHED) {
            $this->dispatchPublishedNotifications($assignment->fresh());
        }

        return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
    }

    public function publishNow(TeacherProfile $profile, int $classId, int $assignmentId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if (!in_array($assignment->status, [TeacherClassAssignment::STATUS_DRAFT, TeacherClassAssignment::STATUS_SCHEDULED], true)) {
            $this->resourceService->abortJson(422, 'invalid_status_transition', 'Only draft or scheduled assignments can be published.');
        }

        $now = CarbonImmutable::now('Asia/Karachi');
        if ($assignment->due_at && $assignment->due_at->lessThanOrEqualTo($now)) {
            $this->resourceService->abortJson(422, 'due_date_in_past', 'Update the due date into the future before publishing.');
        }

        $recipientsData = $this->resolveEligibleRecipients(
            $class,
            $assignment->assignment_mode,
            $assignment->recipients()->pluck('student_web_user_id')->map(fn ($id) => (int) $id)->all(),
            true
        );

        DB::transaction(function () use ($assignment, $recipientsData, $profile, $now) {
            $this->syncRecipients($assignment, $recipientsData, (int) $profile->web_user_id);
            $assignment->update([
                'status' => TeacherClassAssignment::STATUS_PUBLISHED,
                'published_at' => $now,
                'scheduled_publish_at' => null,
            ]);
        });

        $this->dispatchPublishedNotifications($assignment->fresh());

        return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
    }

    public function closeAssignment(TeacherProfile $profile, int $classId, int $assignmentId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if ($assignment->status !== TeacherClassAssignment::STATUS_PUBLISHED) {
            $this->resourceService->abortJson(422, 'invalid_status_transition', 'Only published assignments can be closed.');
        }

        $assignment->update([
            'status' => TeacherClassAssignment::STATUS_CLOSED,
            'closed_at' => CarbonImmutable::now('Asia/Karachi'),
        ]);

        $this->notifyTargetedStudents(
            $assignment->fresh(),
            WebUserNotification::TYPE_ASSIGNMENT_CLOSED,
            'Assignment closed: ' . $assignment->title,
            "Submissions for \"{$assignment->title}\" have been closed by your teacher.",
            '/lms/student/assignments/' . $assignment->id
        );

        return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
    }

    public function reopenAssignment(TeacherProfile $profile, int $classId, int $assignmentId, ?string $newDueAt = null): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if ($assignment->status !== TeacherClassAssignment::STATUS_CLOSED) {
            $this->resourceService->abortJson(422, 'invalid_status_transition', 'Only closed assignments can be reopened.');
        }

        $parsedDueAt = $newDueAt !== null && trim($newDueAt) !== ''
            ? $this->parseNullableKarachiDateTime($newDueAt, 'due_at')
            : $assignment->due_at;

        $now = CarbonImmutable::now('Asia/Karachi');
        if ($newDueAt !== null && trim($newDueAt) !== '' && $parsedDueAt && $parsedDueAt->lessThanOrEqualTo($now)) {
            $this->resourceService->abortJson(422, 'invalid_due_date', 'New due date must be in the future when reopening.');
        }

        $assignment->update([
            'status' => TeacherClassAssignment::STATUS_PUBLISHED,
            'closed_at' => null,
            'due_at' => $parsedDueAt,
        ]);

        $this->notifyTargetedStudents(
            $assignment->fresh(),
            WebUserNotification::TYPE_ASSIGNMENT_REOPENED,
            'Assignment reopened: ' . $assignment->title,
            "\"{$assignment->title}\" has been reopened for student submissions.",
            '/lms/student/assignments/' . $assignment->id
        );

        return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
    }

    public function archiveAssignment(TeacherProfile $profile, int $classId, int $assignmentId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if ($assignment->status === TeacherClassAssignment::STATUS_ARCHIVED) {
            return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
        }

        $assignment->update([
            'status' => TeacherClassAssignment::STATUS_ARCHIVED,
            'archived_at' => CarbonImmutable::now('Asia/Karachi'),
        ]);

        return $this->getAssignmentDetail($profile, $class->id, $assignment->id);
    }

    public function deleteAssignment(TeacherProfile $profile, int $classId, int $assignmentId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if (!in_array($assignment->status, [TeacherClassAssignment::STATUS_DRAFT, TeacherClassAssignment::STATUS_SCHEDULED], true)) {
            $this->resourceService->abortJson(
                422,
                'cannot_delete_published_assignment',
                'Published or closed assignments cannot be permanently deleted; archive them instead.'
            );
        }

        $files = $assignment->attachments()->get(['storage_disk', 'storage_key']);
        $assignment->delete();

        foreach ($files as $att) {
            $this->storageService->deleteStoredFile($att->storage_disk, $att->storage_key);
        }

        return ['success' => true, 'deleted_id' => $assignmentId];
    }

    public function deleteAttachment(TeacherProfile $profile, int $classId, int $assignmentId, int $attachmentId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        if ($assignment->status === TeacherClassAssignment::STATUS_ARCHIVED) {
            $this->resourceService->abortJson(422, 'assignment_archived', 'Cannot remove attachments from an archived assignment.');
        }

        if ($assignment->submissions()->whereNotNull('first_submitted_at')->exists()) {
            $this->resourceService->abortJson(
                422,
                'assignment_submissions_exist_immutable',
                'Cannot remove assignment attachments after students have already submitted work.'
            );
        }

        $att = TeacherClassAssignmentAttachment::query()
            ->where('id', $attachmentId)
            ->where('assignment_id', $assignment->id)
            ->first();

        if (!$att) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Assignment attachment not found.');
        }

        $disk = $att->storage_disk;
        $key = $att->storage_key;
        $att->delete();
        $this->storageService->deleteStoredFile($disk, $key);

        return ['success' => true, 'deleted_attachment_id' => $attachmentId];
    }

    public function streamAttachmentForTeacher(
        TeacherProfile $profile,
        int $classId,
        int $assignmentId,
        int $attachmentId
    ): Response|StreamedResponse {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);
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

    public function listSubmissions(TeacherProfile $profile, int $classId, int $assignmentId, array $filters = []): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);
        $assignment->load('attachments');

        $rows = $this->buildSubmissionRoster($class, $assignment);

        $statusFilter = trim((string) ($filters['status'] ?? ''));
        $lateOnly = filter_var($filters['late_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        $filtered = $rows->filter(function (array $row) use ($statusFilter, $lateOnly, $search) {
            if ($statusFilter !== '' && $statusFilter !== 'all' && $row['computed_status'] !== $statusFilter) {
                return false;
            }
            if ($lateOnly && !$row['is_late']) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(($row['student']['name'] ?? '') . ' ' . ($row['student']['email'] ?? ''));
                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }
            return true;
        })->values()->all();

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'assignment' => $this->presentAssignmentSummary(
                $assignment,
                $this->loadUnitMap(array_filter([$assignment->book_unit_id])),
                $this->loadTopicMap(array_filter([$assignment->book_unit_topic_id])),
                count($rows)
            ),
            'summary' => [
                'total_targeted' => $rows->count(),
                'submitted' => $rows->where('computed_status', 'submitted')->count(),
                'submitted_late' => $rows->where('computed_status', 'submitted_late')->count(),
                'pending' => $rows->where('computed_status', 'pending')->count(),
                'overdue' => $rows->where('computed_status', 'overdue')->count(),
                'closed' => $rows->where('computed_status', 'closed')->count(),
            ],
            'submissions' => $filtered,
        ];
    }

    public function showSubmissionDetail(TeacherProfile $profile, int $classId, int $assignmentId, int $studentWebUserId): array
    {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        $student = WebUser::query()->select(['id', 'name', 'email'])->find($studentWebUserId);
        if (!$student) {
            $this->resourceService->abortJson(404, 'student_not_found', 'Student record not found.');
        }

        $isRecipient = TeacherClassAssignmentRecipient::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $studentWebUserId)
            ->exists();
        $isActiveOrPreviousEnrolled = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('student_web_user_id', $studentWebUserId)
            ->exists();

        if (!$isRecipient && !$isActiveOrPreviousEnrolled) {
            $this->resourceService->abortJson(404, 'submission_student_not_in_class', 'Student is not associated with this class assignment.');
        }

        $submission = TeacherClassAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_web_user_id', $studentWebUserId)
            ->with(['versions', 'activeAttachments'])
            ->first();

        $evaluation = Schema::hasTable('teacher_class_assignment_evaluations')
            ? TeacherClassAssignmentEvaluation::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $studentWebUserId)
                ->with(['evaluator:id,name', 'histories.actor:id,name'])
                ->first()
            : null;

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'assignment' => $this->presentAssignmentSummary(
                $assignment,
                $this->loadUnitMap(array_filter([$assignment->book_unit_id])),
                $this->loadTopicMap(array_filter([$assignment->book_unit_topic_id])),
                0
            ),
            'student' => [
                'id' => (int) $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'computed_status' => $this->computeStudentStatus($assignment, $submission),
            'submission' => $submission ? $this->presentSubmissionForTeacher($class->id, $assignment->id, $submission) : null,
            'evaluation' => $this->evaluationService->presentEvaluationForTeacher($evaluation),
            'evaluation_history' => $evaluation ? $this->evaluationService->presentHistories($evaluation) : [],
        ];
    }

    public function streamSubmissionAttachmentForTeacher(
        TeacherProfile $profile,
        int $classId,
        int $assignmentId,
        int $attachmentId
    ): Response|StreamedResponse {
        $class = $this->resolveEditableClass($profile, $classId);
        $assignment = $this->resolveOwnedAssignment($profile, $class, $assignmentId);

        $att = TeacherClassSubmissionAttachment::query()
            ->where('id', $attachmentId)
            ->where('is_active', true)
            ->whereHas('submission', fn ($q) => $q->where('assignment_id', $assignment->id))
            ->first();

        if (!$att) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Student submission attachment not found.');
        }

        return $this->storageService->streamStoredFile(
            $att->storage_disk,
            $att->storage_key,
            $att->original_name,
            $att->mime_type
        );
    }

    /**
     * Publish scheduled assignments whose scheduled_publish_at <= now and send due reminders within 24 hours.
     *
     * @return array{published_count:int, reminder_count:int}
     */
    public function processScheduledAndReminders(): array
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        $publishedCount = 0;

        $duePublish = TeacherClassAssignment::query()
            ->where('status', TeacherClassAssignment::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', $now)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($duePublish as $assignment) {
            $updated = false;
            DB::transaction(function () use ($assignment, $now, &$updated) {
                $fresh = TeacherClassAssignment::query()->where('id', $assignment->id)->lockForUpdate()->first();
                if (!$fresh || $fresh->status !== TeacherClassAssignment::STATUS_SCHEDULED) {
                    return;
                }
                $class = $fresh->teacherClass()->first();
                if (!$class || !$class->is_active) {
                    return;
                }

                if ($fresh->assignment_mode === TeacherClassAssignment::MODE_ENTIRE_CLASS) {
                    $activeRecipients = $this->resolveEligibleRecipients(
                        $class,
                        TeacherClassAssignment::MODE_ENTIRE_CLASS,
                        [],
                        false
                    );
                    $this->syncRecipients($fresh, $activeRecipients, (int) $fresh->created_by_web_user_id);
                } else {
                    // Revalidate selected recipients against active enrollments at publication time.
                    $activeEnrollments = TeacherClassEnrollment::query()
                        ->where('teacher_class_id', $fresh->teacher_class_id)
                        ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                        ->get(['id', 'student_web_user_id'])
                        ->keyBy('student_web_user_id');

                    $retainedRecipients = [];
                    $existingIds = TeacherClassAssignmentRecipient::query()
                        ->where('assignment_id', $fresh->id)
                        ->pluck('student_web_user_id');

                    foreach ($existingIds as $sid) {
                        $en = $activeEnrollments->get((int) $sid);
                        if ($en) {
                            $retainedRecipients[] = [
                                'student_web_user_id' => (int) $en->student_web_user_id,
                                'teacher_class_enrollment_id' => (int) $en->id,
                            ];
                        }
                    }

                    $this->syncRecipients($fresh, $retainedRecipients, (int) $fresh->created_by_web_user_id);
                }

                $fresh->update([
                    'status' => TeacherClassAssignment::STATUS_PUBLISHED,
                    'published_at' => $now,
                ]);
                $updated = true;
            });

            if ($updated) {
                $this->dispatchPublishedNotifications($assignment->fresh());
                $publishedCount++;
            }
        }

        $reminderWindowEnd = $now->addHours(24);
        $reminderCount = 0;
        $dueSoon = TeacherClassAssignment::query()
            ->where('status', TeacherClassAssignment::STATUS_PUBLISHED)
            ->whereNull('reminded_due_at')
            ->whereNotNull('due_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $reminderWindowEnd)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($dueSoon as $assignment) {
            $claimed = TeacherClassAssignment::query()
                ->where('id', $assignment->id)
                ->whereNull('reminded_due_at')
                ->update(['reminded_due_at' => $now]);

            if ($claimed > 0) {
                $submittedIds = TeacherClassAssignmentSubmission::query()
                    ->where('assignment_id', $assignment->id)
                    ->where('status', TeacherClassAssignmentSubmission::STATUS_SUBMITTED)
                    ->pluck('student_web_user_id')
                    ->all();

                $recipients = $this->resolveTargetStudentIdsForNotification($assignment)
                    ->reject(fn (int $id) => in_array($id, $submittedIds, true));

                $dueFormatted = $assignment->due_at->timezone('Asia/Karachi')->format('M d, Y h:i A');
                foreach ($recipients as $studentId) {
                    $this->notificationService->notify(
                        $studentId,
                        WebUserNotification::TYPE_ASSIGNMENT_DUE_REMINDER,
                        'Assignment due soon: ' . $assignment->title,
                        "Reminder: \"{$assignment->title}\" is due on {$dueFormatted} (PKT).",
                        [
                            'actor_web_user_id' => $assignment->created_by_web_user_id,
                            'teacher_class_id' => $assignment->teacher_class_id,
                            'action_url' => '/lms/student/assignments/' . $assignment->id,
                            'metadata' => [
                                'assignment_id' => (int) $assignment->id,
                                'due_at' => $assignment->due_at?->toIso8601String(),
                            ],
                        ]
                    );
                }
                $reminderCount++;
            }
        }

        return [
            'published_count' => $publishedCount,
            'reminder_count' => $reminderCount,
        ];
    }

    public function computeStudentStatus(
        TeacherClassAssignment $assignment,
        ?TeacherClassAssignmentSubmission $submission
    ): string {
        if ($submission && $submission->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED) {
            return $submission->is_late ? 'submitted_late' : 'submitted';
        }

        if (in_array($assignment->status, [TeacherClassAssignment::STATUS_CLOSED, TeacherClassAssignment::STATUS_ARCHIVED], true)) {
            return 'closed';
        }

        if ($assignment->due_at && CarbonImmutable::now('Asia/Karachi')->greaterThan($assignment->due_at)) {
            return 'overdue';
        }

        return 'pending';
    }

    public function presentTeacherAttachment(int $classId, int $assignmentId, TeacherClassAssignmentAttachment $att): array
    {
        return [
            'id' => (int) $att->id,
            'original_name' => $att->original_name,
            'mime_type' => $att->mime_type,
            'extension' => $att->extension,
            'size_bytes' => (int) $att->size_bytes,
            'download_url' => "/web_api/auth/teacher/classes/{$classId}/assignments/{$assignmentId}/attachments/{$att->id}/download",
            'student_download_url' => "/web_api/auth/student/assignments/{$assignmentId}/attachments/{$att->id}/download",
        ];
    }

    public function dispatchPublishedNotifications(TeacherClassAssignment $assignment): void
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        $targetStudentIds = $this->resolveTargetStudentIdsForNotification($assignment);

        // If recipient rows exist with null notified_at, notify them idempotently.
        $existingRecipients = TeacherClassAssignmentRecipient::query()
            ->where('assignment_id', $assignment->id)
            ->get()
            ->keyBy('student_web_user_id');

        $dueNote = $assignment->due_at
            ? ' Due: ' . $assignment->due_at->timezone('Asia/Karachi')->format('M d, Y h:i A') . ' (PKT).'
            : '';

        foreach ($targetStudentIds as $studentId) {
            $rec = $existingRecipients->get($studentId);
            if ($rec && $rec->notified_at !== null) {
                continue;
            }
            if (!$rec) {
                $enrollmentId = TeacherClassEnrollment::query()
                    ->where('teacher_class_id', $assignment->teacher_class_id)
                    ->where('student_web_user_id', $studentId)
                    ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                    ->value('id');
                try {
                    $rec = TeacherClassAssignmentRecipient::firstOrCreate(
                        [
                            'assignment_id' => $assignment->id,
                            'student_web_user_id' => $studentId,
                        ],
                        [
                            'teacher_class_enrollment_id' => $enrollmentId,
                            'assigned_by_web_user_id' => $assignment->created_by_web_user_id,
                            'assigned_at' => $now,
                        ]
                    );
                } catch (QueryException $e) {
                    $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
                    if ($sqlState !== '23000') {
                        throw $e;
                    }
                    $rec = TeacherClassAssignmentRecipient::query()
                        ->where('assignment_id', $assignment->id)
                        ->where('student_web_user_id', $studentId)
                        ->first();
                }
            }
            if ($rec) {
                $claimed = TeacherClassAssignmentRecipient::query()
                    ->where('id', $rec->id)
                    ->whereNull('notified_at')
                    ->update(['notified_at' => $now]);
                if ($claimed === 0) {
                    continue;
                }
            }

            $this->notificationService->notify(
                $studentId,
                WebUserNotification::TYPE_ASSIGNMENT_PUBLISHED,
                'New assignment: ' . $assignment->title,
                "Your teacher posted \"{$assignment->title}\".{$dueNote}",
                [
                    'actor_web_user_id' => $assignment->created_by_web_user_id,
                    'teacher_class_id' => $assignment->teacher_class_id,
                    'action_url' => '/lms/student/assignments/' . $assignment->id,
                    'metadata' => ['assignment_id' => (int) $assignment->id],
                ]
            );
        }

        if ($assignment->notified_published_at === null) {
            $assignment->update(['notified_published_at' => $now]);
        }
    }

    private function notifyTargetedStudents(
        TeacherClassAssignment $assignment,
        string $type,
        string $title,
        string $message,
        string $actionUrl
    ): void {
        foreach ($this->resolveTargetStudentIdsForNotification($assignment) as $studentId) {
            $this->notificationService->notify(
                $studentId,
                $type,
                $title,
                $message,
                [
                    'actor_web_user_id' => $assignment->created_by_web_user_id,
                    'teacher_class_id' => $assignment->teacher_class_id,
                    'action_url' => $actionUrl,
                    'metadata' => ['assignment_id' => (int) $assignment->id],
                ]
            );
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function resolveTargetStudentIdsForNotification(TeacherClassAssignment $assignment): Collection
    {
        if ($assignment->assignment_mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS) {
            return TeacherClassAssignmentRecipient::query()
                ->join('teacher_class_enrollments as e', function ($join) use ($assignment) {
                    $join->on('e.student_web_user_id', '=', 'teacher_class_assignment_recipients.student_web_user_id')
                        ->where('e.teacher_class_id', '=', $assignment->teacher_class_id)
                        ->where('e.status', '=', TeacherClassEnrollment::STATUS_ACTIVE);
                })
                ->where('teacher_class_assignment_recipients.assignment_id', $assignment->id)
                ->pluck('teacher_class_assignment_recipients.student_web_user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        return TeacherClassEnrollment::query()
            ->where('teacher_class_id', $assignment->teacher_class_id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->pluck('student_web_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function resolveEditableClass(TeacherProfile $profile, int $classId): TeacherClass
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

        if (!$class->is_active) {
            $this->resourceService->abortJson(403, 'teacher_class_inactive', 'Class actions are unavailable because this class is archived.');
        }

        return $class;
    }

    private function resolveOwnedAssignment(TeacherProfile $profile, TeacherClass $class, int $assignmentId): TeacherClassAssignment
    {
        $assignment = TeacherClassAssignment::query()
            ->where('id', $assignmentId)
            ->where('teacher_class_id', $class->id)
            ->where('teacher_profile_id', $profile->id)
            ->first();

        if (!$assignment) {
            $this->resourceService->abortJson(404, 'assignment_not_found', 'Assignment not found in this class.');
        }

        return $assignment;
    }

    private function validateScopedUnitAndTopic(TeacherClass $class, ?int $unitId, ?int $topicId): array
    {
        if ($unitId === null && $topicId === null) {
            return [null, null];
        }

        if ($unitId === null && $topicId !== null) {
            $this->resourceService->abortJson(422, 'unit_required_for_topic', 'Select a textbook unit before selecting a topic.');
        }

        $unitRow = DB::table('book_unit_tbl as u')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('u.id', $unitId)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id)
            ->select(['u.id'])
            ->first();

        if (!$unitRow) {
            $this->resourceService->abortJson(
                422,
                'invalid_curriculum_unit',
                'Selected unit does not belong to this class curriculum, grade, and subject.'
            );
        }

        if ($topicId !== null) {
            $topicRow = DB::table('book_unit_topic_tbl')
                ->where('id', $topicId)
                ->where('unit_id', $unitId)
                ->where('activate', 1)
                ->select(['id'])
                ->first();

            if (!$topicRow) {
                $this->resourceService->abortJson(
                    422,
                    'invalid_curriculum_topic',
                    'Selected topic does not belong to the chosen unit.'
                );
            }
        }

        return [$unitId, $topicId];
    }

    private function resolveCreateLifecycle(string $action, array $payload): array
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        $dueAt = $this->parseNullableKarachiDateTime($payload['due_at'] ?? null, 'due_at');
        $scheduledAt = $this->parseNullableKarachiDateTime($payload['scheduled_publish_at'] ?? null, 'scheduled_publish_at');

        if ($action === 'publish') {
            if ($dueAt && $dueAt->lessThanOrEqualTo($now)) {
                $this->resourceService->abortJson(422, 'due_date_in_past', 'Due date must be in the future when publishing now.');
            }
            return [TeacherClassAssignment::STATUS_PUBLISHED, null, $now, $dueAt];
        }

        if ($action === 'schedule') {
            if (!$scheduledAt || $scheduledAt->lessThanOrEqualTo($now)) {
                $this->resourceService->abortJson(422, 'invalid_schedule_time', 'Scheduled publish time must be in the future.');
            }
            if ($dueAt && $dueAt->lessThanOrEqualTo($scheduledAt)) {
                $this->resourceService->abortJson(422, 'invalid_due_date', 'Due date must be after the scheduled publish date.');
            }
            return [TeacherClassAssignment::STATUS_SCHEDULED, $scheduledAt, null, $dueAt];
        }

        return [TeacherClassAssignment::STATUS_DRAFT, $scheduledAt, null, $dueAt];
    }

    private function parseNullableKarachiDateTime(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, 'Asia/Karachi')->setTimezone('Asia/Karachi');
        } catch (\Throwable) {
            $this->resourceService->abortJson(422, 'invalid_datetime', "Invalid date/time provided for {$field}.");
        }
    }

    /**
     * @return array<int, array{student_web_user_id:int, teacher_class_enrollment_id:int}>
     */
    private function resolveEligibleRecipients(
        TeacherClass $class,
        string $mode,
        array $selectedStudentIds,
        bool $requireNonEmptyOnPublish
    ): array {
        $activeEnrollments = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->get(['id', 'student_web_user_id'])
            ->keyBy('student_web_user_id');

        if ($mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS) {
            $uniqueIds = array_values(array_unique(array_filter(array_map('intval', $selectedStudentIds), fn ($id) => $id > 0)));
            if (empty($uniqueIds)) {
                if ($requireNonEmptyOnPublish) {
                    $this->resourceService->abortJson(422, 'recipients_required', 'Select at least one active enrolled student.');
                }
                return [];
            }

            $resolved = [];
            foreach ($uniqueIds as $studentId) {
                $enrollment = $activeEnrollments->get($studentId);
                if (!$enrollment) {
                    $this->resourceService->abortJson(
                        422,
                        'ineligible_assignment_recipient',
                        'All selected students must have active enrollment in this class.'
                    );
                }
                $resolved[] = [
                    'student_web_user_id' => (int) $enrollment->student_web_user_id,
                    'teacher_class_enrollment_id' => (int) $enrollment->id,
                ];
            }
            return $resolved;
        }

        return $activeEnrollments->map(fn ($e) => [
            'student_web_user_id' => (int) $e->student_web_user_id,
            'teacher_class_enrollment_id' => (int) $e->id,
        ])->values()->all();
    }

    private function syncRecipients(TeacherClassAssignment $assignment, array $recipientsData, int $actorId): void
    {
        $desiredIds = array_map(fn ($r) => (int) $r['student_web_user_id'], $recipientsData);

        if (empty($desiredIds)) {
            TeacherClassAssignmentRecipient::query()->where('assignment_id', $assignment->id)->delete();
            return;
        }

        TeacherClassAssignmentRecipient::query()
            ->where('assignment_id', $assignment->id)
            ->whereNotIn('student_web_user_id', $desiredIds)
            ->delete();

        $now = CarbonImmutable::now('Asia/Karachi');
        foreach ($recipientsData as $r) {
            try {
                TeacherClassAssignmentRecipient::firstOrCreate(
                    [
                        'assignment_id' => $assignment->id,
                        'student_web_user_id' => $r['student_web_user_id'],
                    ],
                    [
                        'teacher_class_enrollment_id' => $r['teacher_class_enrollment_id'],
                        'assigned_by_web_user_id' => $actorId,
                        'assigned_at' => $now,
                    ]
                );
            } catch (QueryException $e) {
                $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
                if ($sqlState !== '23000') {
                    throw $e;
                }
            }
        }
    }

    private function extractStudentIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = array_filter(explode(',', $raw));
            }
        }
        return is_array($raw) ? array_values(array_unique(array_filter(array_map('intval', $raw)))) : [];
    }

    private function listActiveStudents(TeacherClass $class): array
    {
        return TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->with('student:id,name,email')
            ->orderBy('id')
            ->get()
            ->map(fn (TeacherClassEnrollment $e) => [
                'enrollment_id' => (int) $e->id,
                'student_web_user_id' => (int) $e->student_web_user_id,
                'name' => $e->student?->name,
                'email' => $e->student?->email,
            ])
            ->values()
            ->all();
    }

    private function loadUnitMap(array $unitIds): array
    {
        if (empty($unitIds)) {
            return [];
        }
        return DB::table('book_unit_tbl')
            ->whereIn('id', $unitIds)
            ->select(['id', 'unit_no', 'unit_name'])
            ->get()
            ->keyBy('id')
            ->map(fn ($u) => ['id' => (int) $u->id, 'unit_no' => $u->unit_no, 'unit_name' => $u->unit_name])
            ->all();
    }

    private function loadTopicMap(array $topicIds): array
    {
        if (empty($topicIds)) {
            return [];
        }
        return DB::table('book_unit_topic_tbl')
            ->whereIn('id', $topicIds)
            ->select(['id', 'topic_no', 'topic_name', 'topic_name_um'])
            ->get()
            ->keyBy('id')
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'topic_no' => $t->topic_no,
                'topic_name' => $t->topic_name,
                'topic_name_um' => $t->topic_name_um,
            ])
            ->all();
    }

    private function presentAssignmentSummary(
        TeacherClassAssignment $a,
        array $unitMap,
        array $topicMap,
        int $activeEnrolledCount
    ): array {
        $targetedCount = $a->assignment_mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS
            ? (int) ($a->recipients_count ?? $a->recipients()->count())
            : max((int) ($a->recipients_count ?? 0), $activeEnrolledCount);

        return [
            'id' => (int) $a->id,
            'teacher_class_id' => (int) $a->teacher_class_id,
            'title' => $a->title,
            'instructions' => $a->instructions,
            'book_unit_id' => $a->book_unit_id,
            'book_unit_topic_id' => $a->book_unit_topic_id,
            'unit' => $a->book_unit_id ? ($unitMap[$a->book_unit_id] ?? null) : null,
            'topic' => $a->book_unit_topic_id ? ($topicMap[$a->book_unit_topic_id] ?? null) : null,
            'max_marks' => $a->max_marks,
            'assignment_mode' => $a->assignment_mode,
            'status' => $a->status,
            'late_policy' => $a->late_policy,
            'allow_resubmission' => (bool) $a->allow_resubmission,
            'scheduled_publish_at' => $a->scheduled_publish_at?->timezone('Asia/Karachi')->toIso8601String(),
            'published_at' => $a->published_at?->timezone('Asia/Karachi')->toIso8601String(),
            'due_at' => $a->due_at?->timezone('Asia/Karachi')->toIso8601String(),
            'closed_at' => $a->closed_at?->timezone('Asia/Karachi')->toIso8601String(),
            'archived_at' => $a->archived_at?->timezone('Asia/Karachi')->toIso8601String(),
            'attachments_count' => (int) ($a->attachments_count ?? $a->attachments()->count()),
            'targeted_students_count' => $targetedCount,
            'submitted_count' => (int) ($a->submitted_count ?? 0),
            'late_submitted_count' => (int) ($a->late_submitted_count ?? 0),
            'created_at' => $a->created_at?->timezone('Asia/Karachi')->toIso8601String(),
            'updated_at' => $a->updated_at?->timezone('Asia/Karachi')->toIso8601String(),
        ];
    }

    private function buildSubmissionRoster(TeacherClass $class, TeacherClassAssignment $assignment): Collection
    {
        $submissionsByStudent = TeacherClassAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->with(['student:id,name,email', 'activeAttachments'])
            ->withCount('versions')
            ->get()
            ->keyBy('student_web_user_id');

        $evaluationsByStudent = Schema::hasTable('teacher_class_assignment_evaluations')
            ? TeacherClassAssignmentEvaluation::query()
                ->where('assignment_id', $assignment->id)
                ->with('evaluator:id,name')
                ->get()
                ->keyBy('student_web_user_id')
            : collect();

        $rosterStudents = collect();

        if ($assignment->assignment_mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS) {
            $recipients = TeacherClassAssignmentRecipient::query()
                ->where('assignment_id', $assignment->id)
                ->with(['student:id,name,email', 'enrollment:id,status'])
                ->get();

            foreach ($recipients as $r) {
                $rosterStudents->put((int) $r->student_web_user_id, [
                    'student' => $r->student,
                    'enrollment_status' => $r->enrollment?->status ?? 'active',
                ]);
            }
        } else {
            $activeEnrollments = TeacherClassEnrollment::query()
                ->where('teacher_class_id', $class->id)
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->with('student:id,name,email')
                ->get();

            foreach ($activeEnrollments as $en) {
                $rosterStudents->put((int) $en->student_web_user_id, [
                    'student' => $en->student,
                    'enrollment_status' => $en->status,
                ]);
            }
        }

        foreach ($submissionsByStudent as $studentId => $sub) {
            if (!$rosterStudents->has((int) $studentId)) {
                $rosterStudents->put((int) $studentId, [
                    'student' => $sub->student,
                    'enrollment_status' => 'historical',
                ]);
            }
        }

        return $rosterStudents->map(function (array $entry, int $studentId) use ($class, $assignment, $submissionsByStudent, $evaluationsByStudent) {
            /** @var TeacherClassAssignmentSubmission|null $submission */
            $submission = $submissionsByStudent->get($studentId);
            $evaluation = $evaluationsByStudent->get($studentId);
            $computed = $this->computeStudentStatus($assignment, $submission);

            return [
                'student' => [
                    'id' => $studentId,
                    'name' => $entry['student']?->name,
                    'email' => $entry['student']?->email,
                    'enrollment_status' => $entry['enrollment_status'],
                ],
                'computed_status' => $computed,
                'has_submission' => $submission !== null,
                'submission_status' => $submission?->status,
                'submission_version' => $submission ? (int) $submission->submission_version : 0,
                'versions_count' => $submission ? (int) ($submission->versions_count ?? 0) : 0,
                'is_late' => (bool) ($submission?->is_late ?? false),
                'submitted_at' => $submission?->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
                'attachments_count' => $submission ? $submission->activeAttachments->count() : 0,
                'attachments' => $submission ? $submission->activeAttachments->map(
                    fn (TeacherClassSubmissionAttachment $att) => $this->presentSubmissionAttachmentForTeacher($class->id, $assignment->id, $att)
                )->values()->all() : [],
                'evaluation' => $this->evaluationService->presentEvaluationForTeacher($evaluation),
            ];
        })->values();
    }

    private function computeSubmissionStats(TeacherClassAssignment $assignment): array
    {
        $class = $assignment->teacherClass()->first();
        if (!$class) {
            return ['total_targeted' => 0, 'submitted' => 0, 'submitted_late' => 0, 'pending' => 0, 'overdue' => 0, 'closed' => 0];
        }
        $rows = $this->buildSubmissionRoster($class, $assignment);

        return [
            'total_targeted' => $rows->count(),
            'submitted' => $rows->where('computed_status', 'submitted')->count(),
            'submitted_late' => $rows->where('computed_status', 'submitted_late')->count(),
            'pending' => $rows->where('computed_status', 'pending')->count(),
            'overdue' => $rows->where('computed_status', 'overdue')->count(),
            'closed' => $rows->where('computed_status', 'closed')->count(),
        ];
    }

    private function presentSubmissionForTeacher(
        int $classId,
        int $assignmentId,
        TeacherClassAssignmentSubmission $submission
    ): array {
        return [
            'id' => (int) $submission->id,
            'status' => $submission->status,
            'submission_version' => (int) $submission->submission_version,
            'text_response' => $submission->text_response,
            'is_late' => (bool) $submission->is_late,
            'first_submitted_at' => $submission->first_submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
            'submitted_at' => $submission->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
            'last_saved_at' => $submission->last_saved_at?->timezone('Asia/Karachi')->toIso8601String(),
            'attachments' => $submission->activeAttachments->map(
                fn (TeacherClassSubmissionAttachment $att) => $this->presentSubmissionAttachmentForTeacher($classId, $assignmentId, $att)
            )->values()->all(),
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

    private function presentSubmissionAttachmentForTeacher(
        int $classId,
        int $assignmentId,
        TeacherClassSubmissionAttachment $att
    ): array {
        return [
            'id' => (int) $att->id,
            'version_number' => (int) $att->version_number,
            'original_name' => $att->original_name,
            'mime_type' => $att->mime_type,
            'extension' => $att->extension,
            'size_bytes' => (int) $att->size_bytes,
            'download_url' => "/web_api/auth/teacher/classes/{$classId}/assignments/{$assignmentId}/submission-attachments/{$att->id}/download",
        ];
    }
}
