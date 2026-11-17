<?php

namespace App\Http\Controllers;

use App\Http\Services\WebUserNotificationService;
use App\Models\WebUser;
use App\Models\WebUserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebUserNotificationController extends Controller
{
    public function __construct(private WebUserNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'in:all,unread,read'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->notifications->listForUser($user, $filters);
        $paginator = $result['paginator'];

        return response()->json([
            'success' => true,
            'unread_count' => $result['unread_count'],
            'data' => $paginator->getCollection()
                ->map(fn (WebUserNotification $item) => $this->notifications->present($item))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $this->activeUser($request);
        $notification = $this->notifications->markAsRead($user, $id);

        return response()->json([
            'success' => true,
            'unread_count' => $this->notifications->unreadCount($user),
            'data' => $this->notifications->present($notification),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->activeUser($request);
        $updated = $this->notifications->markAllAsRead($user);

        return response()->json([
            'success' => true,
            'updated_count' => $updated,
            'unread_count' => 0,
        ]);
    }

    private function activeUser(Request $request): WebUser
    {
        $user = $request->user();
        abort_unless($user instanceof WebUser && $user->status === 'active' && $user->deleted_at === null, 403, 'Active LMS account required.');

        return $user;
    }
}
