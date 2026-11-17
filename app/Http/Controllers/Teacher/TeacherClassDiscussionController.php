<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassDiscussionService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassDiscussionController extends Controller
{
    public function __construct(private TeacherClassDiscussionService $service) {}

    public function index(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->listTopics($class, $request->user(), 'teacher', $request->query()));
    }

    public function show(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->showTopic($class, $request->user(), 'teacher', $discussionId, $request->query()));
    }

    public function store(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->service->createTopic($class, $request->user(), 'teacher', $validated), 201);
    }

    public function update(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
            'body' => ['sometimes', 'required', 'string', 'min:2', 'max:10000'],
        ]);

        return response()->json($this->service->updateTopic($class, $request->user(), 'teacher', $discussionId, $validated));
    }

    public function storeReply(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:6000'],
        ]);

        return response()->json($this->service->addReply($class, $request->user(), 'teacher', $discussionId, $validated), 201);
    }

    public function updateReply(Request $request, int $classId, int $discussionId, int $replyId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:6000'],
        ]);

        return response()->json($this->service->updateReply($class, $request->user(), 'teacher', $discussionId, $replyId, $validated));
    }

    public function destroy(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:deleted,hidden'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->service->deleteOrModerateTopic(
            $class,
            $request->user(),
            'teacher',
            $discussionId,
            (string) ($validated['mode'] ?? 'deleted'),
            $validated['reason'] ?? null
        ));
    }

    public function destroyReply(Request $request, int $classId, int $discussionId, int $replyId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:deleted,hidden'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->service->deleteOrModerateReply(
            $class,
            $request->user(),
            'teacher',
            $discussionId,
            $replyId,
            (string) ($validated['mode'] ?? 'deleted'),
            $validated['reason'] ?? null
        ));
    }

    public function pin(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->service->togglePin(
            $class,
            $request->user(),
            $discussionId,
            array_key_exists('is_pinned', $validated) ? (bool) $validated['is_pinned'] : null
        ));
    }

    public function setClosed(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'closed' => ['required', 'boolean'],
        ]);

        return response()->json($this->service->setClosedState($class, $request->user(), $discussionId, (bool) $validated['closed']));
    }

    public function reports(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->listReports($class, $request->query()));
    }

    public function resolveReport(Request $request, int $classId, int $reportId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:reviewed,dismissed'],
        ]);

        return response()->json($this->service->resolveReport($class, $request->user(), $reportId, $validated['decision']));
    }

    private function profile(Request $request): TeacherProfile
    {
        $profile = $request->attributes->get('teacher_profile') ?? $request->user()?->teacherProfile;
        if (!$profile instanceof TeacherProfile) {
            abort(403, 'Teacher profile not found.');
        }

        return $profile;
    }
}
