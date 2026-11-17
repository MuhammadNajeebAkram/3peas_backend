<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassDiscussionService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassDiscussionController extends Controller
{
    public function __construct(private TeacherClassDiscussionService $service) {}

    public function index(Request $request, int $classId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);

        return response()->json($this->service->listTopics($class, $student, 'student', $request->query()));
    }

    public function show(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);

        return response()->json($this->service->showTopic($class, $student, 'student', $discussionId, $request->query()));
    }

    public function store(Request $request, int $classId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:2', 'max:10000'],
        ]);

        return response()->json($this->service->createTopic($class, $student, 'student', $validated), 201);
    }

    public function update(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
            'body' => ['sometimes', 'required', 'string', 'min:2', 'max:10000'],
        ]);

        return response()->json($this->service->updateTopic($class, $student, 'student', $discussionId, $validated));
    }

    public function storeReply(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:6000'],
        ]);

        return response()->json($this->service->addReply($class, $student, 'student', $discussionId, $validated), 201);
    }

    public function updateReply(Request $request, int $classId, int $discussionId, int $replyId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:6000'],
        ]);

        return response()->json($this->service->updateReply($class, $student, 'student', $discussionId, $replyId, $validated));
    }

    public function destroy(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);

        return response()->json($this->service->deleteOrModerateTopic($class, $student, 'student', $discussionId));
    }

    public function destroyReply(Request $request, int $classId, int $discussionId, int $replyId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);

        return response()->json($this->service->deleteOrModerateReply($class, $student, 'student', $discussionId, $replyId));
    }

    public function report(Request $request, int $classId, int $discussionId): JsonResponse
    {
        $student = $this->student($request);
        $class = $this->service->resolveStudentActiveClass($student, $classId);
        $validated = $request->validate([
            'reply_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($this->service->reportContent(
            $class,
            $student,
            $discussionId,
            isset($validated['reply_id']) ? (int) $validated['reply_id'] : null,
            $validated['reason']
        ), 201);
    }

    private function student(Request $request): WebUser
    {
        /** @var WebUser|null $user */
        $user = $request->user();
        if (!$user || $user->role !== 'student' || $user->status !== 'active') {
            abort(403, 'Student access only.');
        }

        return $user;
    }
}
