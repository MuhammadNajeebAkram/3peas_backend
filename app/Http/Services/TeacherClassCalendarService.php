<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassCalendarEvent;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestRecipient;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherClassCalendarService
{
    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService,
        private readonly PrivateAttachmentStorageService $storageService,
        private readonly WebUserNotificationService $notificationService,
        private readonly StudentClassAccessService $classAccess
    ) {
    }

    public function feedForTeacher(TeacherProfile $profile, int $classId, array $filters = []): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, false);
        [$rangeStart, $rangeEnd] = $this->resolveRange($filters);

        $includeArchived = filter_var($filters['include_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $eventType = trim((string) ($filters['event_type'] ?? ''));

        $items = $this->buildClassUnifiedItems(
            collect([$class]),
            $rangeStart,
            $rangeEnd,
            null,
            true,
            $includeArchived
        );

        if ($eventType !== '' && $eventType !== 'all') {
            $items = array_values(array_filter($items, fn (array $item) => $item['event_type'] === $eventType));
        }

        $now = CarbonImmutable::now('Asia/Karachi');
        $upcoming = array_values(array_slice(
            array_filter($items, fn (array $item) => CarbonImmutable::parse($item['starts_at'], 'Asia/Karachi')->greaterThanOrEqualTo($now->startOfDay())),
            0,
            10
        ));

        return [
            'success' => true,
            'timezone' => 'Asia/Karachi',
            'class' => $this->resourceService->presentClassSummary($class),
            'range' => [
                'from' => $rangeStart->toIso8601String(),
                'to' => $rangeEnd->toIso8601String(),
            ],
            'items' => $items,
            'upcoming' => $upcoming,
        ];
    }

    public function showEventForTeacher(TeacherProfile $profile, int $classId, int $eventId): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, false);
        $event = $this->resolveOwnedEvent($profile, $class, $eventId);

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'event' => $this->presentCustomEvent($event, $class, true),
        ];
    }

    public function createEvent(TeacherProfile $profile, int $classId, array $payload, ?UploadedFile $attachment = null): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);

        if ($attachment !== null) {
            $this->storageService->validateBatch(
                [$attachment],
                0,
                (int) config('teacher_portal.attachments.max_per_calendar_event', 1)
            );
        }

        $startsAt = $this->parseRequiredKarachiDateTime($payload['starts_at'], 'starts_at');
        $endsAt = $this->parseNullableKarachiDateTime($payload['ends_at'] ?? null, 'ends_at');
        if ($endsAt && $endsAt->lessThan($startsAt)) {
            $this->resourceService->abortJson(422, 'invalid_event_end_time', 'Event end time cannot be earlier than start time.');
        }

        $status = (string) ($payload['status'] ?? TeacherClassCalendarEvent::STATUS_PUBLISHED);
        if (!in_array($status, [TeacherClassCalendarEvent::STATUS_DRAFT, TeacherClassCalendarEvent::STATUS_PUBLISHED], true)) {
            $status = TeacherClassCalendarEvent::STATUS_PUBLISHED;
        }

        $event = DB::transaction(function () use ($class, $profile, $payload, $startsAt, $endsAt, $status, $attachment) {
            $attachmentData = [];
            $event = TeacherClassCalendarEvent::create([
                'teacher_class_id' => $class->id,
                'teacher_profile_id' => $profile->id,
                'created_by_web_user_id' => $profile->web_user_id,
                'title' => trim((string) $payload['title']),
                'description' => isset($payload['description']) && trim((string) $payload['description']) !== ''
                    ? trim((string) $payload['description'])
                    : null,
                'event_type' => (string) ($payload['event_type'] ?? TeacherClassCalendarEvent::TYPE_LESSON),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'location_or_link' => isset($payload['location_or_link']) && trim((string) $payload['location_or_link']) !== ''
                    ? trim((string) $payload['location_or_link'])
                    : null,
                'status' => $status,
                'reminder_minutes_before' => isset($payload['reminder_minutes_before']) && $payload['reminder_minutes_before'] !== ''
                    ? (int) $payload['reminder_minutes_before']
                    : null,
            ]);

            if ($attachment !== null) {
                $stored = $this->storageService->storePrivateFile(
                    $attachment,
                    "private-lms/classes/{$class->id}/calendar/{$event->id}"
                );
                $event->update([
                    'attachment_name' => $stored['original_name'],
                    'attachment_mime' => $stored['mime_type'],
                    'attachment_size' => $stored['size_bytes'],
                    'attachment_disk' => $stored['storage_disk'],
                    'attachment_key' => $stored['storage_key'],
                ]);
            }

            return $event->fresh();
        });

        if ($event->status === TeacherClassCalendarEvent::STATUS_PUBLISHED) {
            $this->dispatchPublishedEventNotifications($event);
        }

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'event' => $this->presentCustomEvent($event, $class, true),
        ];
    }

    public function updateEvent(
        TeacherProfile $profile,
        int $classId,
        int $eventId,
        array $payload,
        ?UploadedFile $attachment = null
    ): array {
        $class = $this->resolveOwnedClass($profile, $classId, true);
        $event = $this->resolveOwnedEvent($profile, $class, $eventId);

        if ($event->status === TeacherClassCalendarEvent::STATUS_ARCHIVED) {
            $this->resourceService->abortJson(422, 'calendar_event_archived', 'Archived calendar events cannot be edited.');
        }

        if ($attachment !== null) {
            $this->storageService->validateBatch(
                [$attachment],
                0,
                (int) config('teacher_portal.attachments.max_per_calendar_event', 1)
            );
        }

        $startsAt = array_key_exists('starts_at', $payload)
            ? $this->parseRequiredKarachiDateTime($payload['starts_at'], 'starts_at')
            : $event->starts_at->toImmutable();
        $endsAt = array_key_exists('ends_at', $payload)
            ? $this->parseNullableKarachiDateTime($payload['ends_at'], 'ends_at')
            : $event->ends_at?->toImmutable();

        if ($endsAt && $endsAt->lessThan($startsAt)) {
            $this->resourceService->abortJson(422, 'invalid_event_end_time', 'Event end time cannot be earlier than start time.');
        }

        $removeAttachment = filter_var($payload['remove_attachment'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $oldFile = null;
        if (($removeAttachment || $attachment !== null) && $event->attachment_disk && $event->attachment_key) {
            $oldFile = [$event->attachment_disk, $event->attachment_key];
        }

        $wasPublished = $event->status === TeacherClassCalendarEvent::STATUS_PUBLISHED;
        $nextStatus = (string) ($payload['status'] ?? $event->status);
        if (!in_array($nextStatus, [
            TeacherClassCalendarEvent::STATUS_DRAFT,
            TeacherClassCalendarEvent::STATUS_PUBLISHED,
            TeacherClassCalendarEvent::STATUS_CANCELLED,
            TeacherClassCalendarEvent::STATUS_ARCHIVED,
        ], true)) {
            $nextStatus = $event->status;
        }

        $now = CarbonImmutable::now('Asia/Karachi');
        $startsChanged = !$startsAt->equalTo($event->starts_at);
        $nextReminderMinutes = array_key_exists('reminder_minutes_before', $payload)
            ? ($payload['reminder_minutes_before'] !== null && $payload['reminder_minutes_before'] !== '' ? (int) $payload['reminder_minutes_before'] : null)
            : $event->reminder_minutes_before;
        $reminderChanged = $nextReminderMinutes !== $event->reminder_minutes_before;

        $updateData = [
            'title' => array_key_exists('title', $payload) ? trim((string) $payload['title']) : $event->title,
            'description' => array_key_exists('description', $payload)
                ? (trim((string) ($payload['description'] ?? '')) !== '' ? trim((string) $payload['description']) : null)
                : $event->description,
            'event_type' => (string) ($payload['event_type'] ?? $event->event_type),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location_or_link' => array_key_exists('location_or_link', $payload)
                ? (trim((string) ($payload['location_or_link'] ?? '')) !== '' ? trim((string) $payload['location_or_link']) : null)
                : $event->location_or_link,
            'status' => $nextStatus,
            'reminder_minutes_before' => $nextReminderMinutes,
        ];

        if (($startsChanged || $reminderChanged) && $startsAt->greaterThan($now)) {
            $updateData['reminded_at'] = null;
        }

        if ($removeAttachment && $attachment === null) {
            $updateData['attachment_name'] = null;
            $updateData['attachment_mime'] = null;
            $updateData['attachment_size'] = null;
            $updateData['attachment_disk'] = null;
            $updateData['attachment_key'] = null;
        }

        if ($attachment !== null) {
            $stored = $this->storageService->storePrivateFile(
                $attachment,
                "private-lms/classes/{$class->id}/calendar/{$event->id}"
            );
            $updateData['attachment_name'] = $stored['original_name'];
            $updateData['attachment_mime'] = $stored['mime_type'];
            $updateData['attachment_size'] = $stored['size_bytes'];
            $updateData['attachment_disk'] = $stored['storage_disk'];
            $updateData['attachment_key'] = $stored['storage_key'];
        }

        $event->update($updateData);

        if ($oldFile !== null) {
            $this->storageService->deleteStoredFile($oldFile[0], $oldFile[1]);
        }

        $fresh = $event->fresh();
        if (!$wasPublished && $fresh->status === TeacherClassCalendarEvent::STATUS_PUBLISHED) {
            $this->dispatchPublishedEventNotifications($fresh);
        }

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'event' => $this->presentCustomEvent($fresh, $class, true),
        ];
    }

    public function cancelEvent(TeacherProfile $profile, int $classId, int $eventId): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);
        $event = $this->resolveOwnedEvent($profile, $class, $eventId);

        if ($event->status === TeacherClassCalendarEvent::STATUS_ARCHIVED) {
            $this->resourceService->abortJson(422, 'calendar_event_archived', 'Archived events cannot be cancelled.');
        }

        $event->update(['status' => TeacherClassCalendarEvent::STATUS_CANCELLED]);

        return [
            'success' => true,
            'event' => $this->presentCustomEvent($event->fresh(), $class, true),
        ];
    }

    public function archiveEvent(TeacherProfile $profile, int $classId, int $eventId): array
    {
        $class = $this->resolveOwnedClass($profile, $classId, true);
        $event = $this->resolveOwnedEvent($profile, $class, $eventId);

        $event->update(['status' => TeacherClassCalendarEvent::STATUS_ARCHIVED]);

        return [
            'success' => true,
            'event' => $this->presentCustomEvent($event->fresh(), $class, true),
        ];
    }

    public function streamEventAttachmentForTeacher(TeacherProfile $profile, int $classId, int $eventId): Response|StreamedResponse
    {
        $class = $this->resolveOwnedClass($profile, $classId, false);
        $event = $this->resolveOwnedEvent($profile, $class, $eventId);

        if (!$event->attachment_disk || !$event->attachment_key) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Calendar event attachment not found.');
        }

        return $this->storageService->streamStoredFile(
            $event->attachment_disk,
            $event->attachment_key,
            $event->attachment_name ?? 'attachment',
            $event->attachment_mime ?? 'application/octet-stream'
        );
    }

    public function feedForStudent(WebUser $student, array $filters = []): array
    {
        $classIdFilter = isset($filters['class_id']) ? (int) $filters['class_id'] : null;
        [$rangeStart, $rangeEnd] = $this->resolveRange($filters);

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
                $this->classAccess->assertStudentAccess($student, $targetClass, 'view_calendar');
            }
            $this->resourceService->abortJson(403, 'forbidden_calendar_access', 'You are not actively enrolled in the requested class.');
        }

        $classes = $activeEnrollments->pluck('teacherClass');
        $items = $classes->isEmpty()
            ? []
            : $this->buildClassUnifiedItems($classes, $rangeStart, $rangeEnd, $student->id, false, false);

        $eventType = trim((string) ($filters['event_type'] ?? ''));
        if ($eventType !== '' && $eventType !== 'all') {
            $items = array_values(array_filter($items, fn (array $item) => $item['event_type'] === $eventType));
        }

        $now = CarbonImmutable::now('Asia/Karachi');
        $upcoming = array_values(array_slice(
            array_filter($items, fn (array $item) => CarbonImmutable::parse($item['starts_at'], 'Asia/Karachi')->greaterThanOrEqualTo($now->startOfDay())),
            0,
            12
        ));

        return [
            'success' => true,
            'timezone' => 'Asia/Karachi',
            'classes' => $classes->map(fn (TeacherClass $c) => $this->resourceService->presentClassSummary($c))->values()->all(),
            'range' => [
                'from' => $rangeStart->toIso8601String(),
                'to' => $rangeEnd->toIso8601String(),
            ],
            'items' => $items,
            'upcoming' => $upcoming,
        ];
    }

    public function streamEventAttachmentForStudent(WebUser $student, int $eventId): Response|StreamedResponse
    {
        $event = TeacherClassCalendarEvent::query()
            ->where('id', $eventId)
            ->where('status', TeacherClassCalendarEvent::STATUS_PUBLISHED)
            ->first();

        if (!$event || !$event->attachment_disk || !$event->attachment_key) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Calendar attachment not found.');
        }

        $enrolled = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $event->teacher_class_id)
            ->where('student_web_user_id', $student->id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->exists();

        if (!$enrolled) {
            $this->resourceService->abortJson(403, 'forbidden_calendar_access', 'You do not have access to this calendar attachment.');
        }

        $class = TeacherClass::find($event->teacher_class_id);
        if ($class) {
            $this->classAccess->assertStudentAccess($student, $class, 'download_calendar_attachment');
        }

        return $this->storageService->streamStoredFile(
            $event->attachment_disk,
            $event->attachment_key,
            $event->attachment_name ?? 'attachment',
            $event->attachment_mime ?? 'application/octet-stream'
        );
    }

    public function processReminders(): int
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        $events = TeacherClassCalendarEvent::query()
            ->where('status', TeacherClassCalendarEvent::STATUS_PUBLISHED)
            ->whereNotNull('reminder_minutes_before')
            ->whereNull('reminded_at')
            ->where('starts_at', '>', $now)
            ->whereHas('teacherClass', fn ($q) => $q->where('is_active', true))
            ->get();

        $sentCount = 0;
        foreach ($events as $event) {
            $triggerAt = $event->starts_at->timezone('Asia/Karachi')->subMinutes((int) $event->reminder_minutes_before);
            if ($now->lessThan($triggerAt)) {
                continue;
            }

            $claimed = TeacherClassCalendarEvent::query()
                ->where('id', $event->id)
                ->whereNull('reminded_at')
                ->update(['reminded_at' => $now]);

            if ($claimed > 0) {
                $studentIds = TeacherClassEnrollment::query()
                    ->where('teacher_class_id', $event->teacher_class_id)
                    ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                    ->pluck('student_web_user_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique();

                $when = $event->starts_at->timezone('Asia/Karachi')->format('M d, Y h:i A');
                foreach ($studentIds as $studentId) {
                    $this->notificationService->notify(
                        $studentId,
                        WebUserNotification::TYPE_CALENDAR_REMINDER,
                        'Upcoming event: ' . $event->title,
                        "Reminder: \"{$event->title}\" starts at {$when} (PKT).",
                        [
                            'actor_web_user_id' => $event->created_by_web_user_id,
                            'teacher_class_id' => $event->teacher_class_id,
                            'action_url' => '/lms/student/calendar?class_id=' . $event->teacher_class_id,
                            'metadata' => ['calendar_event_id' => (int) $event->id],
                        ]
                    );
                }
                $sentCount++;
            }
        }

        return $sentCount;
    }

    private function dispatchPublishedEventNotifications(TeacherClassCalendarEvent $event): void
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        $claimed = TeacherClassCalendarEvent::query()
            ->where('id', $event->id)
            ->whereNull('notified_published_at')
            ->update(['notified_published_at' => $now]);

        if ($claimed === 0) {
            return;
        }

        $studentIds = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $event->teacher_class_id)
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
            ->pluck('student_web_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $when = $event->starts_at->timezone('Asia/Karachi')->format('M d, Y h:i A');
        foreach ($studentIds as $studentId) {
            $this->notificationService->notify(
                $studentId,
                WebUserNotification::TYPE_CALENDAR_EVENT,
                'Class event: ' . $event->title,
                "Scheduled for {$when} (PKT).",
                [
                    'actor_web_user_id' => $event->created_by_web_user_id,
                    'teacher_class_id' => $event->teacher_class_id,
                    'action_url' => '/lms/student/calendar?class_id=' . $event->teacher_class_id,
                    'metadata' => ['calendar_event_id' => (int) $event->id],
                ]
            );
        }
    }

    private function buildClassUnifiedItems(
        \Illuminate\Support\Collection $classes,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        ?int $studentId,
        bool $isTeacher,
        bool $includeArchived
    ): array {
        $classesById = $classes->keyBy('id');
        $classIds = $classesById->keys()->map(fn ($id) => (int) $id)->all();

        $items = [];

        // 1. Custom Calendar Events
        $eventsQuery = TeacherClassCalendarEvent::query()
            ->whereIn('teacher_class_id', $classIds)
            ->where('starts_at', '<=', $rangeEnd)
            ->where(function ($q) use ($rangeStart) {
                $q->where('ends_at', '>=', $rangeStart)
                    ->orWhere(function ($q2) use ($rangeStart) {
                        $q2->whereNull('ends_at')->where('starts_at', '>=', $rangeStart);
                    });
            });

        if ($isTeacher) {
            if (!$includeArchived) {
                $eventsQuery->where('status', '!=', TeacherClassCalendarEvent::STATUS_ARCHIVED);
            }
        } else {
            $eventsQuery->whereIn('status', [TeacherClassCalendarEvent::STATUS_PUBLISHED, TeacherClassCalendarEvent::STATUS_CANCELLED]);
        }

        foreach ($eventsQuery->get() as $event) {
            $class = $classesById->get($event->teacher_class_id);
            $items[] = $this->presentCustomEvent($event, $class, $isTeacher);
        }

        // 2. Dynamic Assignment Deadlines (Module A & B — zero duplicate records)
        $assignmentsQuery = TeacherClassAssignment::query()
            ->whereIn('teacher_class_id', $classIds)
            ->whereIn('status', [TeacherClassAssignment::STATUS_PUBLISHED, TeacherClassAssignment::STATUS_CLOSED])
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$rangeStart, $rangeEnd]);

        if (!$isTeacher && $studentId !== null) {
            $assignmentsQuery->where(function ($q) use ($studentId) {
                $q->where('assignment_mode', TeacherClassAssignment::MODE_ENTIRE_CLASS)
                    ->orWhereHas('recipients', fn ($rq) => $rq->where('student_web_user_id', $studentId));
            });
        }

        foreach ($assignmentsQuery->get() as $assignment) {
            $class = $classesById->get($assignment->teacher_class_id);
            $items[] = [
                'uid' => 'assignment-' . $assignment->id,
                'source' => 'assignment',
                'source_id' => (int) $assignment->id,
                'teacher_class_id' => (int) $assignment->teacher_class_id,
                'class_name' => $class?->name,
                'subject_name' => $class?->subject?->subject_name,
                'title' => $assignment->title,
                'description' => $assignment->instructions,
                'event_type' => TeacherClassCalendarEvent::TYPE_ASSIGNMENT_DEADLINE,
                'status' => $assignment->status,
                'starts_at' => $assignment->due_at->timezone('Asia/Karachi')->toIso8601String(),
                'ends_at' => $assignment->due_at->timezone('Asia/Karachi')->toIso8601String(),
                'location_or_link' => null,
                'reminder_minutes_before' => null,
                'attachment' => null,
                'is_editable' => false,
                'action_url' => $isTeacher
                    ? "/lms/teacher/classes/{$assignment->teacher_class_id}/assignments/{$assignment->id}"
                    : "/lms/student/assignments/{$assignment->id}",
            ];
        }

        // 3. Dynamic Phase 7D Scheduled Tests (read-only integration — zero Phase 7D modification)
        $testsQuery = TeacherClassTestAssignment::query()
            ->whereIn('teacher_class_id', $classIds)
            ->whereIn('status', [
                TeacherClassTestAssignment::STATUS_SCHEDULED,
                TeacherClassTestAssignment::STATUS_ACTIVE,
                TeacherClassTestAssignment::STATUS_CLOSED,
            ])
            ->where('starts_at', '<=', $rangeEnd)
            ->where('ends_at', '>=', $rangeStart);

        if (!$isTeacher && $studentId !== null) {
            $testsQuery->whereHas('recipients', function ($rq) use ($studentId) {
                $rq->where('student_web_user_id', $studentId)
                    ->where('assignment_status', '!=', TeacherClassTestRecipient::STATUS_REVOKED);
            });
        }

        foreach ($testsQuery->get() as $testAssign) {
            $class = $classesById->get($testAssign->teacher_class_id);
            $items[] = [
                'uid' => 'phase7d-test-' . $testAssign->id,
                'source' => 'test',
                'source_id' => (int) $testAssign->id,
                'teacher_class_id' => (int) $testAssign->teacher_class_id,
                'class_name' => $class?->name,
                'subject_name' => $class?->subject?->subject_name,
                'title' => $testAssign->title_snapshot,
                'description' => $testAssign->instructions_snapshot,
                'event_type' => TeacherClassCalendarEvent::TYPE_TEST,
                'status' => $testAssign->status,
                'starts_at' => $testAssign->starts_at->timezone('Asia/Karachi')->toIso8601String(),
                'ends_at' => $testAssign->ends_at->timezone('Asia/Karachi')->toIso8601String(),
                'location_or_link' => null,
                'reminder_minutes_before' => null,
                'attachment' => null,
                'is_editable' => false,
                'action_url' => $isTeacher
                    ? "/lms/teacher/classes/{$testAssign->teacher_class_id}/tests/{$testAssign->teacher_class_test_id}/monitor"
                    : "/lms/student/tests/{$testAssign->id}",
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $items;
    }

    private function presentCustomEvent(TeacherClassCalendarEvent $event, ?TeacherClass $class, bool $isTeacher): array
    {
        $attachment = null;
        $canPresentAttachment = $event->attachment_name
            && ($isTeacher || $event->status === TeacherClassCalendarEvent::STATUS_PUBLISHED);
        if ($canPresentAttachment) {
            $attachment = [
                'original_name' => $event->attachment_name,
                'mime_type' => $event->attachment_mime,
                'size_bytes' => (int) $event->attachment_size,
                'download_url' => $isTeacher
                    ? "/web_api/auth/teacher/classes/{$event->teacher_class_id}/calendar/{$event->id}/attachment/download"
                    : "/web_api/auth/student/calendar/{$event->id}/attachment/download",
            ];
        }

        return [
            'uid' => 'event-' . $event->id,
            'source' => 'calendar_event',
            'source_id' => (int) $event->id,
            'teacher_class_id' => (int) $event->teacher_class_id,
            'class_name' => $class?->name,
            'subject_name' => $class?->subject?->subject_name,
            'title' => $event->title,
            'description' => $event->description,
            'event_type' => $event->event_type,
            'status' => $event->status,
            'starts_at' => $event->starts_at->timezone('Asia/Karachi')->toIso8601String(),
            'ends_at' => $event->ends_at?->timezone('Asia/Karachi')->toIso8601String(),
            'location_or_link' => $event->location_or_link,
            'reminder_minutes_before' => $event->reminder_minutes_before,
            'attachment' => $attachment,
            'is_editable' => $isTeacher,
            'action_url' => $isTeacher
                ? "/lms/teacher/classes/{$event->teacher_class_id}/calendar"
                : "/lms/student/calendar?class_id={$event->teacher_class_id}",
        ];
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
            $this->resourceService->abortJson(403, 'teacher_class_inactive', 'Calendar modifications are unavailable for archived classes.');
        }

        return $class;
    }

    private function resolveOwnedEvent(TeacherProfile $profile, TeacherClass $class, int $eventId): TeacherClassCalendarEvent
    {
        $event = TeacherClassCalendarEvent::query()
            ->where('id', $eventId)
            ->where('teacher_class_id', $class->id)
            ->where('teacher_profile_id', $profile->id)
            ->first();

        if (!$event) {
            $this->resourceService->abortJson(404, 'calendar_event_not_found', 'Calendar event not found.');
        }

        return $event;
    }

    /**
     * @return array{0:CarbonImmutable, 1:CarbonImmutable}
     */
    private function resolveRange(array $filters): array
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $from = !empty($filters['from'])
                ? $this->parseRequiredKarachiDateTime($filters['from'], 'from')->startOfDay()
                : $now->startOfMonth()->subDays(7);
            $to = !empty($filters['to'])
                ? $this->parseRequiredKarachiDateTime($filters['to'], 'to')->endOfDay()
                : $from->addDays(42)->endOfDay();

            return [$from, $to];
        }

        return [$now->startOfMonth()->subDays(7), $now->endOfMonth()->addDays(7)->endOfDay()];
    }

    private function parseRequiredKarachiDateTime(mixed $value, string $field): CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            $this->resourceService->abortJson(422, 'invalid_datetime', "The {$field} date/time is required.");
        }

        try {
            return CarbonImmutable::parse((string) $value, 'Asia/Karachi')->setTimezone('Asia/Karachi');
        } catch (\Throwable) {
            $this->resourceService->abortJson(422, 'invalid_datetime', "Invalid date/time provided for {$field}.");
        }
    }

    private function parseNullableKarachiDateTime(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return $this->parseRequiredKarachiDateTime($value, $field);
    }
}
