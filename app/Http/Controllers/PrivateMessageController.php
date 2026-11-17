<?php

namespace App\Http\Controllers;

use App\Http\Services\PrivateMessageService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivateMessageController extends Controller
{
    public function __construct(private PrivateMessageService $service) {}

    public function recipients(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $search = $request->query('search');
        $classId = $request->filled('class_id') ? (int) $request->query('class_id') : null;

        return response()->json($this->service->listEligibleRecipients($user, $search ? (string) $search : null, $classId));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->listConversations($this->actor($request), $request->query()));
    }

    public function storeConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_web_user_id' => ['required', 'integer', 'min:1'],
            'class_id' => ['nullable', 'integer', 'min:1'],
            'initial_message' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json($this->service->startOrGetConversation(
            $this->actor($request),
            (int) $validated['recipient_web_user_id'],
            isset($validated['class_id']) ? (int) $validated['class_id'] : null,
            $validated['initial_message'] ?? null
        ));
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        return response()->json(
            $this->service->showConversationMessages($this->actor($request), $conversationId, $request->query())
        );
    }

    public function sendMessage(Request $request, int $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        return response()->json(
            $this->service->sendMessage($this->actor($request), $conversationId, $validated['body']),
            201
        );
    }

    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        return response()->json(
            $this->service->markConversationRead($this->actor($request), $conversationId)
        );
    }

    private function actor(Request $request): WebUser
    {
        /** @var WebUser|null $user */
        $user = $request->user();
        if (!$user || !in_array($user->role, ['teacher', 'student'], true)) {
            abort(403, 'Unauthorized for private messaging.');
        }

        return $user;
    }
}
