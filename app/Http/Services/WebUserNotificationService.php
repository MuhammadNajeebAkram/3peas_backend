<?php

namespace App\Http\Services;

use App\Models\WebUser;
use App\Models\WebUserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WebUserNotificationService
{
    public function notify(
        int $recipientWebUserId,
        string $type,
        string $title,
        string $message,
        array $context = []
    ): WebUserNotification {
        return WebUserNotification::create([
            'recipient_web_user_id' => $recipientWebUserId,
            'actor_web_user_id' => $context['actor_web_user_id'] ?? null,
            'teacher_class_id' => $context['teacher_class_id'] ?? null,
            'teacher_class_enrollment_id' => $context['teacher_class_enrollment_id'] ?? null,
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'message' => $message,
            'action_url' => $context['action_url'] ?? null,
            'metadata' => $context['metadata'] ?? null,
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function listForUser(WebUser $user, array $filters = []): array
    {
        $status = $filters['status'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = WebUserNotification::query()
            ->where('recipient_web_user_id', $user->id)
            ->with([
                'actor:id,name',
                'teacherClass:id,name,section',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status === 'unread') {
            $query->where('is_read', false);
        } elseif ($status === 'read') {
            $query->where('is_read', true);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);
        $unreadCount = WebUserNotification::where('recipient_web_user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return [
            'paginator' => $paginator,
            'unread_count' => $unreadCount,
        ];
    }

    public function markAsRead(WebUser $user, int $notificationId): WebUserNotification
    {
        return DB::transaction(function () use ($user, $notificationId) {
            $notification = WebUserNotification::where('recipient_web_user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($notificationId);

            if (!$notification->is_read) {
                $notification->is_read = true;
                $notification->read_at = now();
                $notification->save();
            }

            return $notification->fresh(['actor:id,name', 'teacherClass:id,name,section']);
        });
    }

    public function markAllAsRead(WebUser $user): int
    {
        return DB::transaction(function () use ($user) {
            return WebUserNotification::where('recipient_web_user_id', $user->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    public function unreadCount(WebUser $user): int
    {
        return WebUserNotification::where('recipient_web_user_id', $user->id)
            ->where('is_read', false)
            ->count();
    }

    public function present(WebUserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'teacher_class_id' => $notification->teacher_class_id,
            'teacher_class_enrollment_id' => $notification->teacher_class_enrollment_id,
            'actor' => $notification->actor ? [
                'id' => $notification->actor->id,
                'name' => $notification->actor->name,
            ] : null,
            'class' => $notification->teacherClass ? [
                'id' => $notification->teacherClass->id,
                'name' => $notification->teacherClass->name,
                'section' => $notification->teacherClass->section !== '' ? $notification->teacherClass->section : null,
            ] : null,
            'metadata' => $notification->metadata,
            'is_read' => (bool) $notification->is_read,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
