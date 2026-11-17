<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use App\Models\TeacherStudentConversation;
use App\Models\TeacherStudentMessage;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7E — Private One-to-One Teacher-Student Messaging Service.
 * Enforces canonical (teacher_profile_id, student_web_user_id) conversation identity,
 * blocks student-to-student chat, and requires active shared class enrollment to message.
 */
class PrivateMessageService
{
    public function __construct(
        private TeacherSubjectResourceService $resources,
        private TeacherSubscriptionService $subscriptions,
        private WebUserNotificationService $notifications
    ) {}

    public function listEligibleRecipients(WebUser $actor, ?string $search = null, ?int $classId = null): array
    {
        if ($actor->role === 'teacher') {
            $profile = $this->assertMessagingTeacher($actor);

            $enrollmentsQuery = TeacherClassEnrollment::query()
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->whereHas('teacherClass', function ($q) use ($profile, $classId) {
                    $q->where('teacher_profile_id', $profile->id)
                        ->where('is_active', true);
                    if ($classId) {
                        $q->where('id', $classId);
                    }
                })
                ->whereHas('student', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
                ->with(['student:id,name,email', 'teacherClass:id,name,section']);

            if ($search !== null && trim($search) !== '') {
                $term = '%' . trim($search) . '%';
                $enrollmentsQuery->whereHas('student', fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            }

            $grouped = $enrollmentsQuery->get()->groupBy('student_web_user_id');
            $existingMap = TeacherStudentConversation::where('teacher_profile_id', $profile->id)
                ->pluck('id', 'student_web_user_id');

            $items = $grouped->map(function (Collection $rows, $studentId) use ($existingMap) {
                $first = $rows->first();
                return [
                    'user_id' => (int) $studentId,
                    'role' => 'student',
                    'name' => $first->student?->name ?? 'Student',
                    'email' => $first->student?->email,
                    'conversation_id' => isset($existingMap[(int) $studentId]) ? (int) $existingMap[(int) $studentId] : null,
                    'classes' => $rows->map(fn (TeacherClassEnrollment $e) => [
                        'id' => (int) $e->teacher_class_id,
                        'name' => $e->teacherClass?->name,
                    ])->values(),
                ];
            })->sortBy('name')->values();

            return ['success' => true, 'data' => $items];
        }

        if ($actor->role === 'student') {
            $this->assertActiveStudent($actor);

            $enrollments = TeacherClassEnrollment::query()
                ->where('student_web_user_id', $actor->id)
                ->where('status', TeacherClassEnrollment::STATUS_ACTIVE)
                ->whereHas('teacherClass', function ($q) use ($classId) {
                    $q->where('is_active', true)
                        ->whereHas('teacherProfile', function ($pq) {
                            $pq->where('status', 'active')
                                ->whereHas('webUser', fn ($uq) => $uq->where('role', 'teacher')->where('status', 'active'));
                        });
                    if ($classId) {
                        $q->where('id', $classId);
                    }
                })
                ->with(['teacherClass:id,name,teacher_profile_id', 'teacherClass.teacherProfile.webUser:id,name,email'])
                ->get();

            $grouped = $enrollments->groupBy(fn (TeacherClassEnrollment $e) => $e->teacherClass?->teacher_profile_id);
            $existingMap = TeacherStudentConversation::where('student_web_user_id', $actor->id)
                ->pluck('id', 'teacher_profile_id');

            $items = $grouped->map(function (Collection $rows, $profileId) use ($existingMap) {
                $first = $rows->first();
                $teacherUser = $first->teacherClass?->teacherProfile?->webUser;
                return [
                    'user_id' => (int) $teacherUser->id,
                    'teacher_profile_id' => (int) $profileId,
                    'role' => 'teacher',
                    'name' => $teacherUser->name ?? 'Teacher',
                    'conversation_id' => isset($existingMap[(int) $profileId]) ? (int) $existingMap[(int) $profileId] : null,
                    'classes' => $rows->map(fn (TeacherClassEnrollment $e) => [
                        'id' => (int) $e->teacher_class_id,
                        'name' => $e->teacherClass?->name,
                    ])->values(),
                ];
            })->sortBy('name')->values();

            return ['success' => true, 'data' => $items];
        }

        $this->resources->abortJson(403, 'messaging_forbidden', 'Messaging access is restricted to teachers and students.');
    }

    public function listConversations(WebUser $actor, array $filters = []): array
    {
        if ($actor->role === 'teacher') {
            $profile = $this->assertMessagingTeacher($actor);
            $query = TeacherStudentConversation::where('teacher_profile_id', $profile->id)
                ->where('teacher_web_user_id', $actor->id);
        } elseif ($actor->role === 'student') {
            $this->assertActiveStudent($actor);
            $query = TeacherStudentConversation::where('student_web_user_id', $actor->id);
        } else {
            $this->resources->abortJson(403, 'messaging_forbidden', 'Messaging access is restricted to teachers and students.');
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            if ($actor->role === 'teacher') {
                $query->whereHas('student', fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            } else {
                $query->whereHas('teacher', fn ($q) => $q->where('name', 'like', $term));
            }
        }

        $conversations = $query
            ->with(['teacher:id,name', 'student:id,name,email', 'contextClass:id,name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $unreadByConv = $conversations->isEmpty()
            ? collect()
            : TeacherStudentMessage::query()
                ->selectRaw('conversation_id, COUNT(*) as cnt')
                ->whereIn('conversation_id', $conversations->pluck('id'))
                ->where('sender_web_user_id', '!=', $actor->id)
                ->whereNull('read_at')
                ->groupBy('conversation_id')
                ->pluck('cnt', 'conversation_id');

        $totalUnread = (int) $unreadByConv->sum();

        return [
            'success' => true,
            'total_unread_count' => $totalUnread,
            'data' => $conversations->map(fn (TeacherStudentConversation $c) => $this->presentConversation($c, $actor, (int) ($unreadByConv[$c->id] ?? 0)))->values(),
        ];
    }

    public function startOrGetConversation(WebUser $actor, int $recipientUserId, ?int $classId = null, ?string $initialMessage = null): array
    {
        if ($recipientUserId === (int) $actor->id) {
            $this->resources->abortJson(422, 'invalid_recipient', 'You cannot start a private conversation with yourself.');
        }

        $targetUser = WebUser::find($recipientUserId);
        if (!$targetUser) {
            $this->resources->abortJson(404, 'recipient_not_found', 'Recipient not found.');
        }

        if ($actor->role === 'teacher') {
            $profile = $this->assertMessagingTeacher($actor);
            if ($targetUser->role !== 'student' || $targetUser->status !== 'active') {
                $this->resources->abortJson(422, 'invalid_recipient', 'Teachers can only start private conversations with active students.');
            }
            $teacherUserId = $actor->id;
            $studentUserId = $targetUser->id;
        } elseif ($actor->role === 'student') {
            $this->assertActiveStudent($actor);
            if ($targetUser->role !== 'teacher') {
                $this->resources->abortJson(403, 'student_to_student_forbidden', 'Private messaging between students is not permitted.');
            }
            $profile = TeacherProfile::where('web_user_id', $targetUser->id)->where('status', 'active')->first();
            if (!$profile || $targetUser->status !== 'active') {
                $this->resources->abortJson(403, 'teacher_inactive', 'Selected teacher account is not active.');
            }
            $this->subscriptions->requireFeature($profile->id, 'messaging.use');
            $teacherUserId = $targetUser->id;
            $studentUserId = $actor->id;
        } else {
            $this->resources->abortJson(403, 'messaging_forbidden', 'Unauthorized role for messaging.');
        }

        $sharedClass = $this->resolveSharedActiveClass($profile->id, $studentUserId, $classId);
        if (!$sharedClass) {
            $this->resources->abortJson(
                403,
                'enrollment_not_active',
                'Private messaging is only allowed when the student has an active enrollment in one of the teacher’s active classes.'
            );
        }

        $conversation = null;
        $attempts = 0;
        while ($conversation === null) {
            $attempts++;
            try {
                $conversation = DB::transaction(function () use ($profile, $teacherUserId, $studentUserId, $sharedClass) {
                    $existing = TeacherStudentConversation::where('teacher_profile_id', $profile->id)
                        ->where('student_web_user_id', $studentUserId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return $existing;
                    }

                    $conv = new TeacherStudentConversation();
                    $conv->teacher_profile_id = $profile->id;
                    $conv->teacher_web_user_id = $teacherUserId;
                    $conv->student_web_user_id = $studentUserId;
                    $conv->context_teacher_class_id = $sharedClass->id;
                    $conv->save();
                    return $conv;
                });
            } catch (QueryException $e) {
                if ($attempts >= 4) {
                    $existing = TeacherStudentConversation::where('teacher_profile_id', $profile->id)
                        ->where('student_web_user_id', $studentUserId)
                        ->first();
                    if ($existing) {
                        $conversation = $existing;
                        break;
                    }
                    throw $e;
                }
                usleep(15000 * $attempts);
            }
        }

        if ($initialMessage !== null && trim($initialMessage) !== '') {
            $sendOutcome = $this->sendMessage($actor, $conversation->id, $initialMessage);
            return [
                'success' => true,
                'message' => 'Conversation ready and message sent.',
                'data' => $sendOutcome['conversation'],
                'sent_message' => $sendOutcome['data'],
            ];
        }

        $conversation = $conversation->fresh(['teacher:id,name', 'student:id,name,email', 'contextClass:id,name']);
        $unread = TeacherStudentMessage::where('conversation_id', $conversation->id)
            ->where('sender_web_user_id', '!=', $actor->id)
            ->whereNull('read_at')
            ->count();

        return [
            'success' => true,
            'data' => $this->presentConversation($conversation, $actor, $unread),
        ];
    }

    public function showConversationMessages(WebUser $actor, int $conversationId, array $filters = []): array
    {
        $conversation = $this->resolveOwnedConversation($actor, $conversationId, false);
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 30)));

        $paginator = TeacherStudentMessage::where('conversation_id', $conversation->id)
            ->with('sender:id,name,role')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Return chronological slice (oldest -> newest on the fetched page)
        $orderedMessages = collect($paginator->items())->reverse()->values();
        $unreadCount = TeacherStudentMessage::where('conversation_id', $conversation->id)
            ->where('sender_web_user_id', '!=', $actor->id)
            ->whereNull('read_at')
            ->count();

        return [
            'success' => true,
            'conversation' => $this->presentConversation($conversation, $actor, $unreadCount),
            'data' => $orderedMessages->map(fn (TeacherStudentMessage $m) => $this->presentMessage($m, $actor))->values(),
            'pagination' => $this->resources->presentPagination($paginator),
        ];
    }

    public function sendMessage(WebUser $actor, int $conversationId, string $rawBody): array
    {
        $body = trim($rawBody);
        if ($body === '') {
            $this->resources->abortJson(422, 'empty_message', 'Message body cannot be empty.');
        }

        if ($actor->role === 'teacher') {
            $this->assertMessagingTeacher($actor);
        } elseif ($actor->role === 'student') {
            $this->assertActiveStudent($actor);
        } else {
            $this->resources->abortJson(403, 'messaging_forbidden', 'Unauthorized role.');
        }

        [$conversation, $message] = DB::transaction(function () use ($actor, $conversationId, $body) {
            /** @var TeacherStudentConversation|null $locked */
            $locked = TeacherStudentConversation::lockForUpdate()->find($conversationId);
            if (!$locked || !$this->isConversationParticipant($locked, $actor)) {
                $this->resources->abortJson(404, 'conversation_not_found', 'Conversation not found.');
            }

            $sharedClass = $this->resolveSharedActiveClass($locked->teacher_profile_id, $locked->student_web_user_id);
            if (!$sharedClass) {
                $this->resources->abortJson(
                    403,
                    'enrollment_not_active',
                    'Cannot send new messages because the student no longer has an active class enrollment with this teacher.'
                );
            }

            $teacherProfile = TeacherProfile::with('webUser:id,role,status')->find($locked->teacher_profile_id);
            if (!$teacherProfile || $teacherProfile->status !== 'active' || $teacherProfile->webUser?->status !== 'active') {
                $this->resources->abortJson(403, 'teacher_inactive', 'Teacher account is not active.');
            }
            $this->subscriptions->requireFeature($teacherProfile->id, 'messaging.use');

            $now = now();
            $msg = new TeacherStudentMessage();
            $msg->conversation_id = $locked->id;
            $msg->sender_web_user_id = $actor->id;
            $msg->sender_role = $actor->role;
            $msg->body = $body;
            $msg->save();

            $previewSource = preg_replace('/\s+/u', ' ', $body) ?? $body;
            $locked->last_message_id = $msg->id;
            $locked->last_message_preview = mb_strimwidth($previewSource, 0, 140, '…');
            $locked->last_message_sender_id = $actor->id;
            $locked->last_message_at = $now;
            if (!$locked->context_teacher_class_id) {
                $locked->context_teacher_class_id = $sharedClass->id;
            }
            if ($actor->role === 'teacher') {
                $locked->teacher_last_read_at = $now;
            } else {
                $locked->student_last_read_at = $now;
            }
            $locked->save();

            return [$locked, $msg];
        });

        // Notify counterpart without exposing private message content
        $recipientUserId = $actor->role === 'teacher'
            ? (int) $conversation->student_web_user_id
            : (int) $conversation->teacher_web_user_id;

        $actionUrl = $actor->role === 'teacher'
            ? "/lms/student/messages?conversation_id={$conversation->id}"
            : "/lms/teacher/messages?conversation_id={$conversation->id}";

        $this->notifications->notify(
            $recipientUserId,
            WebUserNotification::TYPE_PRIVATE_MESSAGE,
            'New Private Message',
            "{$actor->name} sent you a private message.",
            [
                'actor_web_user_id' => $actor->id,
                'teacher_class_id' => $conversation->context_teacher_class_id ? (int) $conversation->context_teacher_class_id : null,
                'action_url' => $actionUrl,
                'metadata' => [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                ],
            ]
        );

        $conversation = $conversation->fresh(['teacher:id,name', 'student:id,name,email', 'contextClass:id,name']);
        $message = $message->fresh('sender:id,name,role');
        $unreadCount = TeacherStudentMessage::where('conversation_id', $conversation->id)
            ->where('sender_web_user_id', '!=', $actor->id)
            ->whereNull('read_at')
            ->count();

        return [
            'success' => true,
            'message' => 'Message sent.',
            'conversation' => $this->presentConversation($conversation, $actor, $unreadCount),
            'data' => $this->presentMessage($message, $actor),
        ];
    }

    public function markConversationRead(WebUser $actor, int $conversationId): array
    {
        $conversation = $this->resolveOwnedConversation($actor, $conversationId, true);

        $marked = DB::transaction(function () use ($actor, $conversation) {
            /** @var TeacherStudentConversation $locked */
            $locked = TeacherStudentConversation::lockForUpdate()->findOrFail($conversation->id);
            $now = now();

            $updated = TeacherStudentMessage::where('conversation_id', $locked->id)
                ->where('sender_web_user_id', '!=', $actor->id)
                ->whereNull('read_at')
                ->update([
                    'read_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($actor->role === 'teacher') {
                $locked->teacher_last_read_at = $now;
            } else {
                $locked->student_last_read_at = $now;
            }
            $locked->save();

            return $updated;
        });

        return [
            'success' => true,
            'marked_count' => $marked,
        ];
    }

    private function resolveOwnedConversation(WebUser $actor, int $conversationId, bool $forMutation): TeacherStudentConversation
    {
        if ($actor->role === 'teacher') {
            $this->assertMessagingTeacher($actor);
        } elseif ($actor->role === 'student') {
            $this->assertActiveStudent($actor);
        } else {
            $this->resources->abortJson(403, 'messaging_forbidden', 'Unauthorized role.');
        }

        $conversation = TeacherStudentConversation::with(['teacher:id,name', 'student:id,name,email', 'contextClass:id,name'])
            ->find($conversationId);

        if (!$conversation || !$this->isConversationParticipant($conversation, $actor)) {
            $this->resources->abortJson(404, 'conversation_not_found', 'Conversation not found.');
        }

        return $conversation;
    }

    private function isConversationParticipant(TeacherStudentConversation $c, WebUser $actor): bool
    {
        if ($actor->role === 'teacher') {
            return (int) $c->teacher_web_user_id === (int) $actor->id;
        }
        if ($actor->role === 'student') {
            return (int) $c->student_web_user_id === (int) $actor->id;
        }
        return false;
    }

    private function resolveSharedActiveClass(int $teacherProfileId, int $studentWebUserId, ?int $preferredClassId = null): ?TeacherClass
    {
        $query = TeacherClass::query()
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('is_active', true)
            ->whereHas('enrollments', function ($q) use ($studentWebUserId) {
                $q->where('student_web_user_id', $studentWebUserId)
                    ->where('status', TeacherClassEnrollment::STATUS_ACTIVE);
            });

        if ($preferredClassId !== null) {
            return (clone $query)->find($preferredClassId);
        }

        return $query->orderBy('id')->first();
    }

    private function assertMessagingTeacher(WebUser $user): TeacherProfile
    {
        if ($user->role !== 'teacher' || $user->status !== 'active') {
            $this->resources->abortJson(403, 'teacher_inactive', 'Active teacher account required.');
        }

        $profile = TeacherProfile::where('web_user_id', $user->id)->first();
        if (!$profile || $profile->status !== 'active') {
            $this->resources->abortJson(403, 'teacher_inactive', 'Approved teacher profile required.');
        }

        $this->subscriptions->requireFeature($profile->id, 'messaging.use');

        return $profile;
    }

    private function assertActiveStudent(WebUser $user): void
    {
        if ($user->role !== 'student' || $user->status !== 'active') {
            $this->resources->abortJson(403, 'student_required', 'Active student account required.');
        }
    }

    private function presentConversation(TeacherStudentConversation $c, WebUser $actor, int $unreadCount): array
    {
        $isTeacher = $actor->role === 'teacher';
        $canSend = $this->resolveSharedActiveClass($c->teacher_profile_id, $c->student_web_user_id) !== null;

        return [
            'id' => (int) $c->id,
            'teacher_profile_id' => (int) $c->teacher_profile_id,
            'context_teacher_class_id' => $c->context_teacher_class_id ? (int) $c->context_teacher_class_id : null,
            'context_class_name' => $c->contextClass?->name,
            'counterpart' => $isTeacher ? [
                'id' => (int) $c->student_web_user_id,
                'role' => 'student',
                'name' => $c->student?->name ?? 'Student',
                'email' => $c->student?->email,
            ] : [
                'id' => (int) $c->teacher_web_user_id,
                'role' => 'teacher',
                'name' => $c->teacher?->name ?? 'Teacher',
            ],
            'last_message_preview' => $c->last_message_preview,
            'last_message_sender_id' => $c->last_message_sender_id ? (int) $c->last_message_sender_id : null,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'can_send' => $canSend,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function presentMessage(TeacherStudentMessage $m, WebUser $actor): array
    {
        return [
            'id' => (int) $m->id,
            'conversation_id' => (int) $m->conversation_id,
            'sender_web_user_id' => (int) $m->sender_web_user_id,
            'sender_role' => $m->sender_role,
            'sender_name' => $m->sender?->name ?? ($m->sender_role === 'teacher' ? 'Teacher' : 'Student'),
            'is_mine' => (int) $m->sender_web_user_id === (int) $actor->id,
            'body' => $m->body,
            'read_at' => $m->read_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
