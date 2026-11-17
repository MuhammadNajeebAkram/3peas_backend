<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassDiscussion;
use App\Models\TeacherClassDiscussionReply;
use App\Models\TeacherClassDiscussionReport;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7E — Class Discussions Service (Topics, Replies, Moderation & Reporting).
 */
class TeacherClassDiscussionService
{
    public function __construct(
        private TeacherSubjectResourceService $resources,
        private WebUserNotificationService $notifications,
        private StudentClassAccessService $classAccess
    ) {}

    public function resolveOwnedActiveClass(TeacherProfile $profile, int $classId): TeacherClass
    {
        return $this->resources->resolveOwnedActiveClass($profile, $classId);
    }

    public function resolveStudentActiveClass(WebUser $student, int $classId): TeacherClass
    {
        if ($student->role !== 'student' || $student->status !== 'active') {
            $this->resources->abortJson(403, 'student_required', 'Active student account required.');
        }

        $class = TeacherClass::with(['academicClass:id,class_name', 'subject:id,subject_name', 'teacherProfile.webUser:id,name'])
            ->find($classId);

        if (!$class || !$class->is_active) {
            $this->resources->abortJson(404, 'class_not_found', 'Class not found.');
        }

        $isActiveEnrolled = TeacherClassEnrollment::where('teacher_class_id', $class->id)
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->exists();

        if (!$isActiveEnrolled) {
            $this->resources->abortJson(403, 'enrollment_not_active', 'Only actively enrolled students can access class discussions.');
        }

        $this->classAccess->assertStudentAccess($student, $class, 'discussions');

        return $class;
    }

    public function listTopics(TeacherClass $class, WebUser $actor, string $actorRole, array $filters = []): array
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));
        $isTeacher = $actorRole === 'teacher';

        $query = TeacherClassDiscussion::where('teacher_class_id', $class->id)
            ->with('author:id,name,role')
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('status', TeacherClassDiscussionReport::STATUS_OPEN)])
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id');

        if (!$isTeacher) {
            $query->whereIn('status', [TeacherClassDiscussion::STATUS_OPEN, TeacherClassDiscussion::STATUS_CLOSED]);
        } elseif (!empty($filters['status']) && (string) $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        } elseif (empty($filters['include_moderated'])) {
            $query->whereIn('status', [TeacherClassDiscussion::STATUS_OPEN, TeacherClassDiscussion::STATUS_CLOSED]);
        }

        if (!$isTeacher && !empty($filters['status']) && in_array($filters['status'], ['open', 'closed'], true)) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($sq) use ($term) {
                $sq->where('title', 'like', $term)
                    ->orWhere('body', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage);

        return [
            'success' => true,
            'class' => $this->resources->presentClassSummary($class),
            'open_reports_count' => $isTeacher
                ? TeacherClassDiscussionReport::where('teacher_class_id', $class->id)
                    ->where('status', TeacherClassDiscussionReport::STATUS_OPEN)
                    ->count()
                : 0,
            'data' => collect($paginator->items())
                ->map(fn (TeacherClassDiscussion $d) => $this->presentTopicSummary($d, $actor, $isTeacher))
                ->values(),
            'pagination' => $this->resources->presentPagination($paginator),
        ];
    }

    public function showTopic(TeacherClass $class, WebUser $actor, string $actorRole, int $discussionId, array $filters = []): array
    {
        $isTeacher = $actorRole === 'teacher';
        $topic = TeacherClassDiscussion::where('teacher_class_id', $class->id)
            ->with('author:id,name,role')
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('status', TeacherClassDiscussionReport::STATUS_OPEN)])
            ->find($discussionId);

        if (!$topic || (!$isTeacher && !in_array($topic->status, [TeacherClassDiscussion::STATUS_OPEN, TeacherClassDiscussion::STATUS_CLOSED], true))) {
            $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 30)));
        $repliesQuery = TeacherClassDiscussionReply::where('discussion_id', $topic->id)
            ->with('author:id,name,role')
            ->orderBy('created_at')
            ->orderBy('id');

        if (!$isTeacher) {
            $repliesQuery->where('status', TeacherClassDiscussionReply::STATUS_VISIBLE);
        }

        $repliesPaginator = $repliesQuery->paginate($perPage);

        return [
            'success' => true,
            'class' => $this->resources->presentClassSummary($class),
            'topic' => $this->presentTopicSummary($topic, $actor, $isTeacher),
            'replies' => collect($repliesPaginator->items())
                ->map(fn (TeacherClassDiscussionReply $r) => $this->presentReply($r, $topic, $actor, $isTeacher))
                ->values(),
            'pagination' => $this->resources->presentPagination($repliesPaginator),
        ];
    }

    public function createTopic(TeacherClass $class, WebUser $actor, string $actorRole, array $payload): array
    {
        $now = now();
        $isTeacher = $actorRole === 'teacher';

        $topic = DB::transaction(function () use ($class, $actor, $actorRole, $isTeacher, $payload, $now) {
            $topic = new TeacherClassDiscussion();
            $topic->teacher_class_id = $class->id;
            $topic->author_web_user_id = $actor->id;
            $topic->author_role = $actorRole;
            $topic->title = trim((string) $payload['title']);
            $topic->body = trim((string) $payload['body']);
            $topic->status = TeacherClassDiscussion::STATUS_OPEN;
            $topic->is_pinned = $isTeacher && (bool) ($payload['is_pinned'] ?? false);
            $topic->pinned_at = $topic->is_pinned ? $now : null;
            $topic->last_activity_at = $now;
            $topic->save();

            return $topic;
        });

        $this->notifyOnNewTopic($class, $topic, $actor, $isTeacher);

        $topic = $topic->fresh('author:id,name,role');

        return [
            'success' => true,
            'message' => 'Discussion topic created.',
            'data' => $this->presentTopicSummary($topic, $actor, $isTeacher),
        ];
    }

    public function updateTopic(TeacherClass $class, WebUser $actor, string $actorRole, int $discussionId, array $payload): array
    {
        $isTeacher = $actorRole === 'teacher';

        $topic = DB::transaction(function () use ($class, $actor, $isTeacher, $discussionId, $payload) {
            /** @var TeacherClassDiscussion|null $locked */
            $locked = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$locked || in_array($locked->status, [TeacherClassDiscussion::STATUS_HIDDEN, TeacherClassDiscussion::STATUS_DELETED], true)) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            if ((int) $locked->author_web_user_id !== (int) $actor->id) {
                $this->resources->abortJson(403, 'forbidden_discussion_edit', 'Only the creator of this discussion topic can edit it.');
            }

            if (!$isTeacher && $locked->status !== TeacherClassDiscussion::STATUS_OPEN) {
                $this->resources->abortJson(409, 'discussion_closed', 'Closed discussions cannot be edited.');
            }

            if (array_key_exists('title', $payload)) {
                $locked->title = trim((string) $payload['title']);
            }
            if (array_key_exists('body', $payload)) {
                $locked->body = trim((string) $payload['body']);
            }
            $locked->save();

            return $locked;
        });

        $topic = $topic->fresh('author:id,name,role');

        return [
            'success' => true,
            'message' => 'Discussion topic updated.',
            'data' => $this->presentTopicSummary($topic, $actor, $isTeacher),
        ];
    }

    public function addReply(TeacherClass $class, WebUser $actor, string $actorRole, int $discussionId, array $payload): array
    {
        $isTeacher = $actorRole === 'teacher';

        [$topic, $reply] = DB::transaction(function () use ($class, $actor, $actorRole, $discussionId, $payload) {
            /** @var TeacherClassDiscussion|null $topic */
            $topic = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$topic || in_array($topic->status, [TeacherClassDiscussion::STATUS_HIDDEN, TeacherClassDiscussion::STATUS_DELETED], true)) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            if ($topic->status === TeacherClassDiscussion::STATUS_CLOSED) {
                $this->resources->abortJson(409, 'discussion_closed', 'This discussion has been closed and no longer accepts replies.');
            }

            $now = now();
            $reply = new TeacherClassDiscussionReply();
            $reply->discussion_id = $topic->id;
            $reply->author_web_user_id = $actor->id;
            $reply->author_role = $actorRole;
            $reply->body = trim((string) $payload['body']);
            $reply->status = TeacherClassDiscussionReply::STATUS_VISIBLE;
            $reply->save();

            $topic->replies_count = TeacherClassDiscussionReply::where('discussion_id', $topic->id)
                ->where('status', TeacherClassDiscussionReply::STATUS_VISIBLE)
                ->count();
            $topic->last_activity_at = $now;
            $topic->save();

            return [$topic, $reply];
        });

        $this->notifyOnNewReply($class, $topic, $reply, $actor);

        $reply = $reply->fresh('author:id,name,role');

        return [
            'success' => true,
            'message' => 'Reply posted.',
            'data' => $this->presentReply($reply, $topic, $actor, $isTeacher),
        ];
    }

    public function updateReply(TeacherClass $class, WebUser $actor, string $actorRole, int $discussionId, int $replyId, array $payload): array
    {
        $isTeacher = $actorRole === 'teacher';

        [$topic, $reply] = DB::transaction(function () use ($class, $actor, $isTeacher, $discussionId, $replyId, $payload) {
            /** @var TeacherClassDiscussion|null $topic */
            $topic = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$topic || in_array($topic->status, [TeacherClassDiscussion::STATUS_HIDDEN, TeacherClassDiscussion::STATUS_DELETED], true)) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            if (!$isTeacher && $topic->status === TeacherClassDiscussion::STATUS_CLOSED) {
                $this->resources->abortJson(409, 'discussion_closed', 'Replies in a closed discussion cannot be edited.');
            }

            /** @var TeacherClassDiscussionReply|null $reply */
            $reply = TeacherClassDiscussionReply::where('discussion_id', $topic->id)->lockForUpdate()->find($replyId);
            if (!$reply || $reply->status !== TeacherClassDiscussionReply::STATUS_VISIBLE) {
                $this->resources->abortJson(404, 'reply_not_found', 'Reply not found.');
            }

            if ((int) $reply->author_web_user_id !== (int) $actor->id) {
                $this->resources->abortJson(403, 'forbidden_reply_edit', 'You can only edit your own replies.');
            }

            $reply->body = trim((string) $payload['body']);
            $reply->save();

            return [$topic, $reply];
        });

        $reply = $reply->fresh('author:id,name,role');

        return [
            'success' => true,
            'message' => 'Reply updated.',
            'data' => $this->presentReply($reply, $topic, $actor, $isTeacher),
        ];
    }

    public function deleteOrModerateTopic(
        TeacherClass $class,
        WebUser $actor,
        string $actorRole,
        int $discussionId,
        string $mode = 'deleted',
        ?string $reason = null
    ): array {
        $isTeacher = $actorRole === 'teacher';
        $targetStatus = ($isTeacher && $mode === TeacherClassDiscussion::STATUS_HIDDEN)
            ? TeacherClassDiscussion::STATUS_HIDDEN
            : TeacherClassDiscussion::STATUS_DELETED;

        $topic = DB::transaction(function () use ($class, $actor, $isTeacher, $discussionId, $targetStatus, $reason) {
            /** @var TeacherClassDiscussion|null $locked */
            $locked = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$locked || $locked->status === TeacherClassDiscussion::STATUS_DELETED) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            if (!$isTeacher) {
                if ((int) $locked->author_web_user_id !== (int) $actor->id) {
                    $this->resources->abortJson(403, 'forbidden_moderation', 'You can only delete your own discussion topic.');
                }
                if ($locked->status !== TeacherClassDiscussion::STATUS_OPEN) {
                    $this->resources->abortJson(409, 'discussion_closed', 'Closed or moderated topics cannot be deleted by students.');
                }
            }

            $now = now();
            $locked->status = $targetStatus;
            $locked->is_pinned = false;
            $locked->pinned_at = null;
            if ($isTeacher) {
                $locked->moderated_at = $now;
                $locked->moderated_by_web_user_id = $actor->id;
                $locked->moderation_reason = $reason ? trim($reason) : null;
            }
            if ($targetStatus === TeacherClassDiscussion::STATUS_DELETED) {
                $locked->deleted_at = $now;
            }
            $locked->save();

            // Resolve open reports against this topic when teacher moderates
            if ($isTeacher) {
                TeacherClassDiscussionReport::where('discussion_id', $locked->id)
                    ->where('status', TeacherClassDiscussionReport::STATUS_OPEN)
                    ->update([
                        'status' => TeacherClassDiscussionReport::STATUS_REVIEWED,
                        'reviewed_by_web_user_id' => $actor->id,
                        'reviewed_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            return $locked;
        });

        return [
            'success' => true,
            'message' => $targetStatus === TeacherClassDiscussion::STATUS_HIDDEN
                ? 'Discussion hidden by teacher.'
                : 'Discussion deleted (audit record preserved).',
            'data' => $this->presentTopicSummary($topic->fresh('author:id,name,role'), $actor, $isTeacher),
        ];
    }

    public function deleteOrModerateReply(
        TeacherClass $class,
        WebUser $actor,
        string $actorRole,
        int $discussionId,
        int $replyId,
        string $mode = 'deleted',
        ?string $reason = null
    ): array {
        $isTeacher = $actorRole === 'teacher';
        $targetStatus = ($isTeacher && $mode === TeacherClassDiscussionReply::STATUS_HIDDEN)
            ? TeacherClassDiscussionReply::STATUS_HIDDEN
            : TeacherClassDiscussionReply::STATUS_DELETED;

        [$topic, $reply] = DB::transaction(function () use ($class, $actor, $isTeacher, $discussionId, $replyId, $targetStatus, $reason) {
            /** @var TeacherClassDiscussion|null $topic */
            $topic = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$topic) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            /** @var TeacherClassDiscussionReply|null $reply */
            $reply = TeacherClassDiscussionReply::where('discussion_id', $topic->id)->lockForUpdate()->find($replyId);
            if (!$reply || $reply->status === TeacherClassDiscussionReply::STATUS_DELETED) {
                $this->resources->abortJson(404, 'reply_not_found', 'Reply not found.');
            }

            if (!$isTeacher) {
                if ((int) $reply->author_web_user_id !== (int) $actor->id) {
                    $this->resources->abortJson(403, 'forbidden_moderation', 'You can only delete your own replies.');
                }
                if ($topic->status !== TeacherClassDiscussion::STATUS_OPEN || $reply->status !== TeacherClassDiscussionReply::STATUS_VISIBLE) {
                    $this->resources->abortJson(409, 'discussion_closed', 'This reply cannot be deleted.');
                }
            }

            $now = now();
            $reply->status = $targetStatus;
            if ($isTeacher) {
                $reply->moderated_at = $now;
                $reply->moderated_by_web_user_id = $actor->id;
                $reply->moderation_reason = $reason ? trim($reason) : null;
            }
            if ($targetStatus === TeacherClassDiscussionReply::STATUS_DELETED) {
                $reply->deleted_at = $now;
            }
            $reply->save();

            $topic->replies_count = TeacherClassDiscussionReply::where('discussion_id', $topic->id)
                ->where('status', TeacherClassDiscussionReply::STATUS_VISIBLE)
                ->count();
            $topic->save();

            if ($isTeacher) {
                TeacherClassDiscussionReport::where('reply_id', $reply->id)
                    ->where('status', TeacherClassDiscussionReport::STATUS_OPEN)
                    ->update([
                        'status' => TeacherClassDiscussionReport::STATUS_REVIEWED,
                        'reviewed_by_web_user_id' => $actor->id,
                        'reviewed_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            return [$topic, $reply];
        });

        return [
            'success' => true,
            'message' => $targetStatus === TeacherClassDiscussionReply::STATUS_HIDDEN
                ? 'Reply hidden by teacher.'
                : 'Reply deleted (audit record preserved).',
            'data' => $this->presentReply($reply->fresh('author:id,name,role'), $topic, $actor, $isTeacher),
        ];
    }

    public function togglePin(TeacherClass $class, WebUser $teacherUser, int $discussionId, ?bool $pin = null): array
    {
        $topic = DB::transaction(function () use ($class, $discussionId, $pin) {
            /** @var TeacherClassDiscussion|null $locked */
            $locked = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$locked || in_array($locked->status, [TeacherClassDiscussion::STATUS_HIDDEN, TeacherClassDiscussion::STATUS_DELETED], true)) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            $nextPin = $pin ?? !$locked->is_pinned;
            $locked->is_pinned = $nextPin;
            $locked->pinned_at = $nextPin ? ($locked->pinned_at ?? now()) : null;
            $locked->save();

            return $locked;
        });

        return [
            'success' => true,
            'message' => $topic->is_pinned ? 'Discussion pinned.' : 'Discussion unpinned.',
            'data' => $this->presentTopicSummary($topic->fresh('author:id,name,role'), $teacherUser, true),
        ];
    }

    public function setClosedState(TeacherClass $class, WebUser $teacherUser, int $discussionId, bool $close): array
    {
        $topic = DB::transaction(function () use ($class, $teacherUser, $discussionId, $close) {
            /** @var TeacherClassDiscussion|null $locked */
            $locked = TeacherClassDiscussion::where('teacher_class_id', $class->id)->lockForUpdate()->find($discussionId);
            if (!$locked || in_array($locked->status, [TeacherClassDiscussion::STATUS_HIDDEN, TeacherClassDiscussion::STATUS_DELETED], true)) {
                $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
            }

            $locked->status = $close ? TeacherClassDiscussion::STATUS_CLOSED : TeacherClassDiscussion::STATUS_OPEN;
            $locked->closed_at = $close ? now() : null;
            $locked->closed_by_web_user_id = $close ? $teacherUser->id : null;
            $locked->save();

            return $locked;
        });

        return [
            'success' => true,
            'message' => $close ? 'Discussion closed to new replies.' : 'Discussion reopened.',
            'data' => $this->presentTopicSummary($topic->fresh('author:id,name,role'), $teacherUser, true),
        ];
    }

    public function reportContent(
        TeacherClass $class,
        WebUser $reporter,
        int $discussionId,
        ?int $replyId,
        string $reason
    ): array {
        $topic = TeacherClassDiscussion::where('teacher_class_id', $class->id)->find($discussionId);
        if (!$topic || in_array($topic->status, [TeacherClassDiscussion::STATUS_HIDDEN, TeacherClassDiscussion::STATUS_DELETED], true)) {
            $this->resources->abortJson(404, 'discussion_not_found', 'Discussion topic not found.');
        }

        if ($replyId !== null) {
            $reply = TeacherClassDiscussionReply::where('discussion_id', $topic->id)->find($replyId);
            if (!$reply || $reply->status !== TeacherClassDiscussionReply::STATUS_VISIBLE) {
                $this->resources->abortJson(404, 'reply_not_found', 'Reply not found.');
            }
        }

        $report = DB::transaction(function () use ($class, $topic, $replyId, $reporter, $reason) {
            $existing = TeacherClassDiscussionReport::where('reporter_web_user_id', $reporter->id)
                ->where('discussion_id', $topic->id)
                ->where('status', TeacherClassDiscussionReport::STATUS_OPEN)
                ->when($replyId === null, fn ($q) => $q->whereNull('reply_id'), fn ($q) => $q->where('reply_id', $replyId))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->resources->abortJson(409, 'already_reported', 'You already have an open report for this content.');
            }

            $report = new TeacherClassDiscussionReport();
            $report->teacher_class_id = $class->id;
            $report->discussion_id = $topic->id;
            $report->reply_id = $replyId;
            $report->reporter_web_user_id = $reporter->id;
            $report->reason = trim($reason);
            $report->status = TeacherClassDiscussionReport::STATUS_OPEN;
            $report->save();

            return $report;
        });

        $teacherUserId = $class->teacherProfile?->web_user_id;
        if ($teacherUserId && (int) $teacherUserId !== (int) $reporter->id) {
            $this->notifications->notify(
                (int) $teacherUserId,
                WebUserNotification::TYPE_DISCUSSION_REPORTED,
                'Discussion Content Reported',
                "{$reporter->name} reported content in {$class->name}: \"{$topic->title}\"",
                [
                    'actor_web_user_id' => $reporter->id,
                    'teacher_class_id' => $class->id,
                    'action_url' => "/lms/teacher/classes/{$class->id}/discussions?discussion_id={$topic->id}",
                    'metadata' => [
                        'discussion_id' => (int) $topic->id,
                        'reply_id' => $replyId,
                        'report_id' => (int) $report->id,
                    ],
                ]
            );
        }

        return [
            'success' => true,
            'message' => 'Content reported to your class teacher for review.',
            'data' => [
                'id' => (int) $report->id,
                'status' => $report->status,
                'discussion_id' => (int) $topic->id,
                'reply_id' => $replyId,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
        ];
    }

    public function listReports(TeacherClass $class, array $filters = []): array
    {
        $status = !empty($filters['status']) ? (string) $filters['status'] : TeacherClassDiscussionReport::STATUS_OPEN;

        $reports = TeacherClassDiscussionReport::where('teacher_class_id', $class->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with([
                'discussion:id,title,status',
                'reply:id,body,status',
                'reporter:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return [
            'success' => true,
            'data' => $reports->map(fn (TeacherClassDiscussionReport $rep) => [
                'id' => (int) $rep->id,
                'discussion_id' => (int) $rep->discussion_id,
                'reply_id' => $rep->reply_id ? (int) $rep->reply_id : null,
                'reason' => $rep->reason,
                'status' => $rep->status,
                'discussion_title' => $rep->discussion?->title,
                'reply_excerpt' => $rep->reply ? mb_strimwidth($rep->reply->body, 0, 140, '…') : null,
                'reporter' => $rep->reporter ? [
                    'id' => (int) $rep->reporter->id,
                    'name' => $rep->reporter->name,
                ] : null,
                'created_at' => $rep->created_at?->toIso8601String(),
                'reviewed_at' => $rep->reviewed_at?->toIso8601String(),
            ])->values(),
        ];
    }

    public function resolveReport(TeacherClass $class, WebUser $teacherUser, int $reportId, string $decision): array
    {
        if (!in_array($decision, [TeacherClassDiscussionReport::STATUS_REVIEWED, TeacherClassDiscussionReport::STATUS_DISMISSED], true)) {
            $this->resources->abortJson(422, 'invalid_decision', 'Report decision must be reviewed or dismissed.');
        }

        $report = DB::transaction(function () use ($class, $teacherUser, $reportId, $decision) {
            $locked = TeacherClassDiscussionReport::where('teacher_class_id', $class->id)->lockForUpdate()->find($reportId);
            if (!$locked) {
                $this->resources->abortJson(404, 'report_not_found', 'Report not found.');
            }
            $locked->status = $decision;
            $locked->reviewed_by_web_user_id = $teacherUser->id;
            $locked->reviewed_at = now();
            $locked->save();
            return $locked;
        });

        return [
            'success' => true,
            'message' => 'Report updated.',
            'data' => [
                'id' => (int) $report->id,
                'status' => $report->status,
                'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            ],
        ];
    }

    private function notifyOnNewTopic(TeacherClass $class, TeacherClassDiscussion $topic, WebUser $actor, bool $byTeacher): void
    {
        if ($byTeacher) {
            $studentIds = TeacherClassEnrollment::where('teacher_class_id', $class->id)
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->whereHas('student', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
                ->pluck('student_web_user_id');

            foreach ($studentIds as $studentId) {
                $this->notifications->notify(
                    (int) $studentId,
                    WebUserNotification::TYPE_DISCUSSION_TOPIC,
                    'New Class Discussion Topic',
                    "{$class->name}: {$topic->title}",
                    [
                        'actor_web_user_id' => $actor->id,
                        'teacher_class_id' => $class->id,
                        'action_url' => "/lms/student/discussions?class_id={$class->id}&discussion_id={$topic->id}",
                        'metadata' => [
                            'discussion_id' => (int) $topic->id,
                            'teacher_class_id' => (int) $class->id,
                        ],
                    ]
                );
            }
            return;
        }

        $teacherUserId = $class->teacherProfile?->web_user_id;
        if ($teacherUserId && (int) $teacherUserId !== (int) $actor->id) {
            $this->notifications->notify(
                (int) $teacherUserId,
                WebUserNotification::TYPE_DISCUSSION_TOPIC,
                'New Student Discussion Topic',
                "{$actor->name} started \"{$topic->title}\" in {$class->name}.",
                [
                    'actor_web_user_id' => $actor->id,
                    'teacher_class_id' => $class->id,
                    'action_url' => "/lms/teacher/classes/{$class->id}/discussions?discussion_id={$topic->id}",
                    'metadata' => [
                        'discussion_id' => (int) $topic->id,
                        'teacher_class_id' => (int) $class->id,
                    ],
                ]
            );
        }
    }

    private function notifyOnNewReply(
        TeacherClass $class,
        TeacherClassDiscussion $topic,
        TeacherClassDiscussionReply $reply,
        WebUser $actor
    ): void {
        $recipientIds = [];

        // Notify original topic author if different from replier and still eligible
        if ((int) $topic->author_web_user_id !== (int) $actor->id) {
            $authorStillEligible = $topic->author_role === 'teacher'
                ? ((int) ($class->teacherProfile?->web_user_id) === (int) $topic->author_web_user_id)
                : TeacherClassEnrollment::where('teacher_class_id', $class->id)
                    ->where('student_web_user_id', $topic->author_web_user_id)
                    ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                    ->exists();

            if ($authorStillEligible) {
                $recipientIds[(int) $topic->author_web_user_id] = $topic->author_role;
            }
        }

        // Notify class teacher on student replies if teacher wasn't the replier or already queued
        $teacherUserId = (int) ($class->teacherProfile?->web_user_id ?? 0);
        if ($teacherUserId > 0 && $teacherUserId !== (int) $actor->id && !isset($recipientIds[$teacherUserId])) {
            $recipientIds[$teacherUserId] = 'teacher';
        }

        foreach ($recipientIds as $recipientId => $role) {
            $url = $role === 'teacher'
                ? "/lms/teacher/classes/{$class->id}/discussions?discussion_id={$topic->id}"
                : "/lms/student/discussions?class_id={$class->id}&discussion_id={$topic->id}";

            $this->notifications->notify(
                (int) $recipientId,
                WebUserNotification::TYPE_DISCUSSION_REPLY,
                'New Discussion Reply',
                "{$actor->name} replied to \"{$topic->title}\" in {$class->name}.",
                [
                    'actor_web_user_id' => $actor->id,
                    'teacher_class_id' => $class->id,
                    'action_url' => $url,
                    'metadata' => [
                        'discussion_id' => (int) $topic->id,
                        'reply_id' => (int) $reply->id,
                        'teacher_class_id' => (int) $class->id,
                    ],
                ]
            );
        }
    }

    private function presentTopicSummary(TeacherClassDiscussion $d, WebUser $actor, bool $isTeacher): array
    {
        $isOwner = (int) $d->author_web_user_id === (int) $actor->id;
        $isOpen = $d->status === TeacherClassDiscussion::STATUS_OPEN;

        return [
            'id' => (int) $d->id,
            'teacher_class_id' => (int) $d->teacher_class_id,
            'title' => $d->title,
            'body' => $d->body,
            'status' => $d->status,
            'is_pinned' => (bool) $d->is_pinned,
            'pinned_at' => $d->pinned_at?->toIso8601String(),
            'replies_count' => (int) $d->replies_count,
            'open_reports_count' => $isTeacher ? (int) ($d->open_reports_count ?? 0) : 0,
            'author' => [
                'id' => (int) $d->author_web_user_id,
                'name' => $d->author?->name ?? 'Member',
                'role' => $d->author_role,
            ],
            'permissions' => [
                'can_reply' => $isOpen,
                'can_edit' => $isOwner && ($isTeacher || $isOpen) && !in_array($d->status, ['hidden', 'deleted'], true),
                'can_delete' => ($isTeacher || ($isOwner && $isOpen)) && $d->status !== 'deleted',
                'can_moderate' => $isTeacher && $d->status !== 'deleted',
                'can_pin' => $isTeacher && in_array($d->status, ['open', 'closed'], true),
                'can_toggle_close' => $isTeacher && in_array($d->status, ['open', 'closed'], true),
                'can_report' => !$isOwner && in_array($d->status, ['open', 'closed'], true),
            ],
            'moderation_reason' => $isTeacher ? $d->moderation_reason : null,
            'last_activity_at' => ($d->last_activity_at ?? $d->created_at)?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    private function presentReply(TeacherClassDiscussionReply $r, TeacherClassDiscussion $topic, WebUser $actor, bool $isTeacher): array
    {
        $isOwner = (int) $r->author_web_user_id === (int) $actor->id;
        $topicOpen = $topic->status === TeacherClassDiscussion::STATUS_OPEN;
        $visible = $r->status === TeacherClassDiscussionReply::STATUS_VISIBLE;

        return [
            'id' => (int) $r->id,
            'discussion_id' => (int) $r->discussion_id,
            'body' => $r->body,
            'status' => $r->status,
            'author' => [
                'id' => (int) $r->author_web_user_id,
                'name' => $r->author?->name ?? 'Member',
                'role' => $r->author_role,
            ],
            'permissions' => [
                'can_edit' => $isOwner && $visible && ($isTeacher || $topicOpen),
                'can_delete' => ($isTeacher || ($isOwner && $visible && $topicOpen)) && $r->status !== 'deleted',
                'can_moderate' => $isTeacher && $r->status !== 'deleted',
                'can_report' => !$isOwner && $visible,
            ],
            'moderation_reason' => $isTeacher ? $r->moderation_reason : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
