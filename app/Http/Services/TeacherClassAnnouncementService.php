<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAnnouncement;
use App\Models\TeacherClassAnnouncementRead;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7E — Class Announcements Service (Teacher management + Student read/unread access).
 */
class TeacherClassAnnouncementService
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

    public function listForTeacher(TeacherClass $class, array $filters = []): array
    {
        $this->syncScheduledForClasses([$class->id]);

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));
        $activeStudentsCount = $class->activeEnrollments()->count();

        $summaryBase = TeacherClassAnnouncement::forClass($class);
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'draft' => (clone $summaryBase)->where('status', TeacherClassAnnouncement::STATUS_DRAFT)->count(),
            'scheduled' => (clone $summaryBase)->where('status', TeacherClassAnnouncement::STATUS_SCHEDULED)->count(),
            'published' => (clone $summaryBase)->where('status', TeacherClassAnnouncement::STATUS_PUBLISHED)->count(),
            'archived' => (clone $summaryBase)->where('status', TeacherClassAnnouncement::STATUS_ARCHIVED)->count(),
            'active_students_count' => $activeStudentsCount,
        ];

        $query = TeacherClassAnnouncement::forClass($class)
            ->withCount('reads')
            ->with('creator:id,name')
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(published_at, scheduled_at, created_at) DESC')
            ->orderByDesc('id');

        if (!empty($filters['status']) && (string) $filters['status'] !== 'all') {
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
            'summary' => $summary,
            'data' => collect($paginator->items())
                ->map(fn (TeacherClassAnnouncement $a) => $this->presentForTeacher($a, $activeStudentsCount))
                ->values(),
            'pagination' => $this->resources->presentPagination($paginator),
        ];
    }

    public function showForTeacher(TeacherClass $class, int $announcementId): array
    {
        $this->syncScheduledForClasses([$class->id]);

        $announcement = TeacherClassAnnouncement::forClass($class)
            ->withCount('reads')
            ->with('creator:id,name')
            ->find($announcementId);

        if (!$announcement) {
            $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
        }

        $activeStudentsCount = $class->activeEnrollments()->count();

        return [
            'success' => true,
            'data' => $this->presentForTeacher($announcement, $activeStudentsCount),
        ];
    }

    public function create(TeacherClass $class, TeacherProfile $profile, array $payload): array
    {
        $announcement = DB::transaction(function () use ($class, $profile, $payload) {
            $mode = (string) ($payload['action'] ?? ($payload['status'] ?? 'draft'));
            if (!in_array($mode, ['draft', 'publish', 'published', 'schedule', 'scheduled'], true)) {
                $this->resources->abortJson(422, 'invalid_announcement_action', 'Invalid announcement action.');
            }

            $now = now();
            $announcement = new TeacherClassAnnouncement();
            $announcement->teacher_class_id = $class->id;
            $announcement->teacher_profile_id = $profile->id;
            $announcement->created_by_web_user_id = $profile->web_user_id;
            $announcement->title = trim((string) $payload['title']);
            $announcement->body = trim((string) $payload['body']);
            $announcement->is_pinned = (bool) ($payload['is_pinned'] ?? false);
            $announcement->pinned_at = $announcement->is_pinned ? $now : null;

            if (in_array($mode, ['publish', 'published'], true)) {
                $announcement->status = TeacherClassAnnouncement::STATUS_PUBLISHED;
                $announcement->published_at = $now;
                $announcement->scheduled_at = null;
            } elseif (in_array($mode, ['schedule', 'scheduled'], true)) {
                $scheduledAt = $this->parseFutureScheduledAt($payload['scheduled_at'] ?? null);
                $announcement->status = TeacherClassAnnouncement::STATUS_SCHEDULED;
                $announcement->scheduled_at = $scheduledAt;
            } else {
                $announcement->status = TeacherClassAnnouncement::STATUS_DRAFT;
                $announcement->scheduled_at = !empty($payload['scheduled_at'])
                    ? Carbon::parse((string) $payload['scheduled_at'])->setTimezone(config('app.timezone'))
                    : null;
            }

            $announcement->save();

            return $announcement;
        });

        if ($announcement->status === TeacherClassAnnouncement::STATUS_PUBLISHED) {
            $this->notifyPublishedOnce($announcement);
        }

        $announcement = $announcement->fresh(['creator:id,name']);
        $announcement->loadCount('reads');
        $activeCount = $class->activeEnrollments()->count();

        return [
            'success' => true,
            'message' => match ($announcement->status) {
                TeacherClassAnnouncement::STATUS_PUBLISHED => 'Announcement published to class.',
                TeacherClassAnnouncement::STATUS_SCHEDULED => 'Announcement scheduled for publication.',
                default => 'Announcement saved as draft.',
            },
            'data' => $this->presentForTeacher($announcement, $activeCount),
        ];
    }

    public function update(TeacherClass $class, int $announcementId, array $payload): array
    {
        $announcement = DB::transaction(function () use ($class, $announcementId, $payload) {
            /** @var TeacherClassAnnouncement|null $locked */
            $locked = TeacherClassAnnouncement::forClass($class)->lockForUpdate()->find($announcementId);
            if (!$locked) {
                $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
            }
            if ($locked->status === TeacherClassAnnouncement::STATUS_ARCHIVED) {
                $this->resources->abortJson(409, 'announcement_archived', 'Archived announcements cannot be edited.');
            }

            if (array_key_exists('title', $payload)) {
                $locked->title = trim((string) $payload['title']);
            }
            if (array_key_exists('body', $payload)) {
                $locked->body = trim((string) $payload['body']);
            }
            if (array_key_exists('is_pinned', $payload)) {
                $pin = (bool) $payload['is_pinned'];
                $locked->is_pinned = $pin;
                $locked->pinned_at = $pin ? ($locked->pinned_at ?? now()) : null;
            }
            if (array_key_exists('scheduled_at', $payload) && in_array($locked->status, [TeacherClassAnnouncement::STATUS_DRAFT, TeacherClassAnnouncement::STATUS_SCHEDULED], true)) {
                if ($payload['scheduled_at']) {
                    $locked->scheduled_at = $this->parseFutureScheduledAt($payload['scheduled_at']);
                    if ($locked->status === TeacherClassAnnouncement::STATUS_DRAFT && !empty($payload['action']) && $payload['action'] === 'schedule') {
                        $locked->status = TeacherClassAnnouncement::STATUS_SCHEDULED;
                    }
                } else {
                    $locked->scheduled_at = null;
                    if ($locked->status === TeacherClassAnnouncement::STATUS_SCHEDULED) {
                        $locked->status = TeacherClassAnnouncement::STATUS_DRAFT;
                    }
                }
            }

            $locked->save();

            return $locked;
        });

        $announcement = $announcement->fresh(['creator:id,name']);
        $announcement->loadCount('reads');

        return [
            'success' => true,
            'message' => 'Announcement updated.',
            'data' => $this->presentForTeacher($announcement, $class->activeEnrollments()->count()),
        ];
    }

    public function publish(TeacherClass $class, int $announcementId, ?string $scheduledAtRaw = null): array
    {
        $announcement = DB::transaction(function () use ($class, $announcementId, $scheduledAtRaw) {
            /** @var TeacherClassAnnouncement|null $locked */
            $locked = TeacherClassAnnouncement::forClass($class)->lockForUpdate()->find($announcementId);
            if (!$locked) {
                $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
            }
            if ($locked->status === TeacherClassAnnouncement::STATUS_ARCHIVED) {
                $this->resources->abortJson(409, 'announcement_archived', 'Archived announcements cannot be published.');
            }
            if ($locked->status === TeacherClassAnnouncement::STATUS_PUBLISHED) {
                return $locked;
            }

            if ($scheduledAtRaw) {
                $locked->scheduled_at = $this->parseFutureScheduledAt($scheduledAtRaw);
                $locked->status = TeacherClassAnnouncement::STATUS_SCHEDULED;
            } else {
                $locked->status = TeacherClassAnnouncement::STATUS_PUBLISHED;
                $locked->published_at ??= now();
                $locked->scheduled_at = null;
            }
            $locked->save();

            return $locked;
        });

        if ($announcement->status === TeacherClassAnnouncement::STATUS_PUBLISHED) {
            $this->notifyPublishedOnce($announcement);
        }

        $announcement = $announcement->fresh(['creator:id,name']);
        $announcement->loadCount('reads');

        return [
            'success' => true,
            'message' => $announcement->status === TeacherClassAnnouncement::STATUS_SCHEDULED
                ? 'Announcement scheduled.'
                : 'Announcement published to active students.',
            'data' => $this->presentForTeacher($announcement, $class->activeEnrollments()->count()),
        ];
    }

    public function togglePin(TeacherClass $class, int $announcementId, ?bool $pin = null): array
    {
        $announcement = DB::transaction(function () use ($class, $announcementId, $pin) {
            /** @var TeacherClassAnnouncement|null $locked */
            $locked = TeacherClassAnnouncement::forClass($class)->lockForUpdate()->find($announcementId);
            if (!$locked) {
                $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
            }
            if ($locked->status === TeacherClassAnnouncement::STATUS_ARCHIVED) {
                $this->resources->abortJson(409, 'announcement_archived', 'Archived announcements cannot be pinned.');
            }

            $nextPin = $pin ?? !$locked->is_pinned;
            $locked->is_pinned = $nextPin;
            $locked->pinned_at = $nextPin ? ($locked->pinned_at ?? now()) : null;
            $locked->save();

            return $locked;
        });

        $announcement = $announcement->fresh(['creator:id,name']);
        $announcement->loadCount('reads');

        return [
            'success' => true,
            'message' => $announcement->is_pinned ? 'Announcement pinned.' : 'Announcement unpinned.',
            'data' => $this->presentForTeacher($announcement, $class->activeEnrollments()->count()),
        ];
    }

    public function archive(TeacherClass $class, int $announcementId): array
    {
        $announcement = DB::transaction(function () use ($class, $announcementId) {
            /** @var TeacherClassAnnouncement|null $locked */
            $locked = TeacherClassAnnouncement::forClass($class)->lockForUpdate()->find($announcementId);
            if (!$locked) {
                $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
            }

            $locked->status = TeacherClassAnnouncement::STATUS_ARCHIVED;
            $locked->is_pinned = false;
            $locked->pinned_at = null;
            $locked->archived_at ??= now();
            $locked->save();

            return $locked;
        });

        $announcement = $announcement->fresh(['creator:id,name']);
        $announcement->loadCount('reads');

        return [
            'success' => true,
            'message' => 'Announcement archived.',
            'data' => $this->presentForTeacher($announcement, $class->activeEnrollments()->count()),
        ];
    }

    public function deleteDraft(TeacherClass $class, int $announcementId): array
    {
        DB::transaction(function () use ($class, $announcementId) {
            /** @var TeacherClassAnnouncement|null $locked */
            $locked = TeacherClassAnnouncement::forClass($class)->lockForUpdate()->find($announcementId);
            if (!$locked) {
                $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
            }
            if ($locked->status !== TeacherClassAnnouncement::STATUS_DRAFT) {
                $this->resources->abortJson(409, 'only_draft_deletable', 'Only unpublished drafts can be deleted; archive published announcements instead.');
            }

            $locked->delete();
        });

        return [
            'success' => true,
            'message' => 'Announcement draft deleted.',
        ];
    }

    public function syncScheduledForClasses(array $classIds = []): int
    {
        $now = now();
        $query = TeacherClassAnnouncement::where('status', TeacherClassAnnouncement::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now);

        if ($classIds !== []) {
            $query->whereIn('teacher_class_id', $classIds);
        }

        $dueIds = $query->pluck('id');
        $publishedCount = 0;

        foreach ($dueIds as $id) {
            $published = DB::transaction(function () use ($id, $now) {
                $locked = TeacherClassAnnouncement::lockForUpdate()->find($id);
                if (!$locked || $locked->status !== TeacherClassAnnouncement::STATUS_SCHEDULED || !$locked->scheduled_at || $locked->scheduled_at->gt($now)) {
                    return null;
                }

                $locked->status = TeacherClassAnnouncement::STATUS_PUBLISHED;
                $locked->published_at = $locked->scheduled_at;
                $locked->save();

                return $locked;
            });

            if ($published) {
                $this->notifyPublishedOnce($published);
                $publishedCount++;
            }
        }

        return $publishedCount;
    }

    public function notifyPublishedOnce(TeacherClassAnnouncement $announcement): void
    {
        $shouldNotify = DB::transaction(function () use ($announcement) {
            $locked = TeacherClassAnnouncement::lockForUpdate()->find($announcement->id);
            if (!$locked || $locked->status !== TeacherClassAnnouncement::STATUS_PUBLISHED || $locked->notified_at !== null) {
                return false;
            }
            $locked->notified_at = now();
            $locked->save();
            return true;
        });

        if (!$shouldNotify) {
            return;
        }

        $class = TeacherClass::find($announcement->teacher_class_id);
        if (!$class || !$class->is_active) {
            return;
        }

        $studentIds = TeacherClassEnrollment::where('teacher_class_id', $class->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->whereHas('student', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
            ->pluck('student_web_user_id');

        foreach ($studentIds as $studentId) {
            $this->notifications->notify(
                (int) $studentId,
                WebUserNotification::TYPE_CLASS_ANNOUNCEMENT,
                'New Class Announcement',
                "{$class->name}: {$announcement->title}",
                [
                    'actor_web_user_id' => $announcement->created_by_web_user_id,
                    'teacher_class_id' => $class->id,
                    'action_url' => "/lms/student/announcements?class_id={$class->id}&announcement_id={$announcement->id}",
                    'metadata' => [
                        'announcement_id' => (int) $announcement->id,
                        'teacher_class_id' => (int) $class->id,
                    ],
                ]
            );
        }
    }

    // ------------------------------------------------------------------
    // Student Announcement Methods
    // ------------------------------------------------------------------

    public function listForStudent(WebUser $student, array $filters = []): array
    {
        $this->assertActiveStudent($student);

        $allActiveEnrollments = TeacherClassEnrollment::query()
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->with(['teacherClass.academicClass:id,class_name', 'teacherClass.subject:id,subject_name', 'teacherClass.teacherProfile.webUser:id,name'])
            ->get();

        $activeEnrollments = $allActiveEnrollments->filter(fn (TeacherClassEnrollment $e) => $this->classAccess->hasActiveSubscription((int) $student->id, $e->teacherClass))->values();

        $activeClassIds = $activeEnrollments->pluck('teacher_class_id')->map(fn ($id) => (int) $id)->values()->all();

        if ($activeClassIds !== []) {
            $this->syncScheduledForClasses($activeClassIds);
        }

        $classFilter = !empty($filters['class_id']) ? (int) $filters['class_id'] : null;
        if ($classFilter && !in_array($classFilter, $activeClassIds, true)) {
            $targetClass = TeacherClass::find($classFilter);
            if ($targetClass) {
                $this->classAccess->assertStudentAccess($student, $targetClass, 'announcements');
            }
            $this->resources->abortJson(403, 'class_not_enrolled', 'You do not have active enrollment in the selected class.');
        }

        $targetClassIds = $classFilter ? [$classFilter] : $activeClassIds;

        $enrolledClasses = $activeEnrollments->map(function (TeacherClassEnrollment $e) {
            $cls = $e->teacherClass;
            return [
                'id' => (int) $cls->id,
                'name' => $cls->name,
                'section' => $cls->section !== '' ? $cls->section : null,
                'subject_name' => $cls->subject?->subject_name,
                'grade_name' => $cls->academicClass?->class_name,
                'teacher_name' => $cls->teacherProfile?->webUser?->name,
            ];
        })->values();

        if ($targetClassIds === []) {
            return [
                'success' => true,
                'unread_count' => 0,
                'enrolled_classes' => [],
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                ],
            ];
        }

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));
        $unreadOnly = filter_var($filters['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $unreadCount = TeacherClassAnnouncement::whereIn('teacher_class_id', $activeClassIds)
            ->where('status', TeacherClassAnnouncement::STATUS_PUBLISHED)
            ->whereDoesntHave('reads', fn ($q) => $q->where('student_web_user_id', $student->id))
            ->count();

        $query = TeacherClassAnnouncement::whereIn('teacher_class_id', $targetClassIds)
            ->where('status', TeacherClassAnnouncement::STATUS_PUBLISHED)
            ->with([
                'teacherClass.academicClass:id,class_name',
                'teacherClass.subject:id,subject_name',
                'teacherClass.teacherProfile.webUser:id,name',
                'reads' => fn ($q) => $q->where('student_web_user_id', $student->id),
            ])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereDoesntHave('reads', fn ($q) => $q->where('student_web_user_id', $student->id));
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
            'unread_count' => $unreadCount,
            'enrolled_classes' => $enrolledClasses,
            'data' => collect($paginator->items())
                ->map(fn (TeacherClassAnnouncement $a) => $this->presentForStudent($a))
                ->values(),
            'pagination' => $this->resources->presentPagination($paginator),
        ];
    }

    public function showForStudent(WebUser $student, int $announcementId): array
    {
        $announcement = $this->resolvePublishedForActiveStudent($student, $announcementId);

        return [
            'success' => true,
            'data' => $this->presentForStudent($announcement),
        ];
    }

    public function markReadForStudent(WebUser $student, int $announcementId): array
    {
        $announcement = $this->resolvePublishedForActiveStudent($student, $announcementId);

        $read = DB::transaction(function () use ($announcement, $student) {
            $existing = TeacherClassAnnouncementRead::where('announcement_id', $announcement->id)
                ->where('student_web_user_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            try {
                return TeacherClassAnnouncementRead::create([
                    'announcement_id' => $announcement->id,
                    'student_web_user_id' => $student->id,
                    'read_at' => now(),
                ]);
            } catch (QueryException) {
                return TeacherClassAnnouncementRead::where('announcement_id', $announcement->id)
                    ->where('student_web_user_id', $student->id)
                    ->firstOrFail();
            }
        });

        $announcement->setRelation('reads', collect([$read]));

        return [
            'success' => true,
            'message' => 'Announcement marked as read.',
            'data' => $this->presentForStudent($announcement),
        ];
    }

    private function resolvePublishedForActiveStudent(WebUser $student, int $announcementId): TeacherClassAnnouncement
    {
        $this->assertActiveStudent($student);

        $announcement = TeacherClassAnnouncement::with([
            'teacherClass.academicClass:id,class_name',
            'teacherClass.subject:id,subject_name',
            'teacherClass.teacherProfile.webUser:id,name',
            'reads' => fn ($q) => $q->where('student_web_user_id', $student->id),
        ])->find($announcementId);

        if (!$announcement || !$announcement->teacherClass || !$announcement->teacherClass->is_active) {
            $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
        }

        // Sync scheduled state if due
        if ($announcement->status === TeacherClassAnnouncement::STATUS_SCHEDULED) {
            $this->syncScheduledForClasses([$announcement->teacher_class_id]);
            $announcement->refresh();
        }

        if ($announcement->status !== TeacherClassAnnouncement::STATUS_PUBLISHED) {
            $this->resources->abortJson(404, 'announcement_not_found', 'Announcement not found.');
        }

        $enrolledActive = TeacherClassEnrollment::where('teacher_class_id', $announcement->teacher_class_id)
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->exists();

        if (!$enrolledActive) {
            $this->resources->abortJson(403, 'enrollment_not_active', 'Only actively enrolled students can access announcements for this class.');
        }

        $this->classAccess->assertStudentAccess($student, $announcement->teacherClass, 'announcements');

        return $announcement;
    }

    private function assertActiveStudent(WebUser $user): void
    {
        if ($user->role !== 'student') {
            $this->resources->abortJson(403, 'student_required', 'Student access required.');
        }
        if ($user->status !== 'active') {
            $this->resources->abortJson(403, 'student_inactive', 'Student account is not active.');
        }
    }

    private function parseFutureScheduledAt(?string $raw): Carbon
    {
        if (!$raw) {
            $this->resources->abortJson(422, 'invalid_scheduled_at', 'Scheduled publication date/time is required.');
        }

        try {
            $dt = Carbon::parse((string) $raw)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            $this->resources->abortJson(422, 'invalid_scheduled_at', 'Invalid scheduled date/time.');
        }

        if ($dt->lte(now())) {
            $this->resources->abortJson(422, 'invalid_scheduled_at', 'Scheduled publication time must be in the future.');
        }

        return $dt;
    }

    private function presentForTeacher(TeacherClassAnnouncement $a, int $activeStudentsCount): array
    {
        return [
            'id' => (int) $a->id,
            'teacher_class_id' => (int) $a->teacher_class_id,
            'title' => $a->title,
            'body' => $a->body,
            'status' => $a->status,
            'is_pinned' => (bool) $a->is_pinned,
            'pinned_at' => $a->pinned_at?->toIso8601String(),
            'scheduled_at' => $a->scheduled_at?->toIso8601String(),
            'published_at' => $a->published_at?->toIso8601String(),
            'archived_at' => $a->archived_at?->toIso8601String(),
            'reads_count' => (int) ($a->reads_count ?? 0),
            'active_students_count' => $activeStudentsCount,
            'creator' => $a->creator ? [
                'id' => (int) $a->creator->id,
                'name' => $a->creator->name,
            ] : null,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    private function presentForStudent(TeacherClassAnnouncement $a): array
    {
        $readRow = $a->relationLoaded('reads') ? $a->reads->first() : null;
        $cls = $a->teacherClass;

        return [
            'id' => (int) $a->id,
            'teacher_class_id' => (int) $a->teacher_class_id,
            'title' => $a->title,
            'body' => $a->body,
            'status' => $a->status,
            'is_pinned' => (bool) $a->is_pinned,
            'published_at' => $a->published_at?->toIso8601String(),
            'is_read' => (bool) $readRow,
            'read_at' => $readRow?->read_at?->toIso8601String(),
            'class' => $cls ? [
                'id' => (int) $cls->id,
                'name' => $cls->name,
                'section' => $cls->section !== '' ? $cls->section : null,
                'subject_name' => $cls->subject?->subject_name,
                'grade_name' => $cls->academicClass?->class_name,
                'teacher_name' => $cls->teacherProfile?->webUser?->name,
            ] : null,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
