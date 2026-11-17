<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassAnnouncementService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassAnnouncementController extends Controller
{
    public function __construct(private TeacherClassAnnouncementService $service) {}

    public function index(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->listForTeacher($class, $request->query()));
    }

    public function show(Request $request, int $classId, int $announcementId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->showForTeacher($class, $announcementId));
    }

    public function store(Request $request, int $classId): JsonResponse
    {
        $profile = $this->profile($request);
        $class = $this->service->resolveOwnedActiveClass($profile, $classId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'action' => ['nullable', 'string', 'in:draft,publish,published,schedule,scheduled'],
            'status' => ['nullable', 'string', 'in:draft,publish,published,schedule,scheduled'],
            'scheduled_at' => ['nullable', 'date'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->service->create($class, $profile, $validated), 201);
    }

    public function update(Request $request, int $classId, int $announcementId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'body' => ['sometimes', 'required', 'string', 'min:2', 'max:10000'],
            'action' => ['nullable', 'string', 'in:draft,publish,published,schedule,scheduled'],
            'scheduled_at' => ['nullable', 'date'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->service->update($class, $announcementId, $validated));
    }

    public function publish(Request $request, int $classId, int $announcementId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
        ]);

        return response()->json($this->service->publish(
            $class,
            $announcementId,
            $validated['scheduled_at'] ?? null
        ));
    }

    public function pin(Request $request, int $classId, int $announcementId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        $validated = $request->validate([
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->service->togglePin(
            $class,
            $announcementId,
            array_key_exists('is_pinned', $validated) ? (bool) $validated['is_pinned'] : null
        ));
    }

    public function archive(Request $request, int $classId, int $announcementId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->archive($class, $announcementId));
    }

    public function destroy(Request $request, int $classId, int $announcementId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->deleteDraft($class, $announcementId));
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
