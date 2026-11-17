<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassCalendarService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherClassCalendarController extends Controller
{
    public function __construct(private readonly TeacherClassCalendarService $service)
    {
    }

    public function index(Request $request, int $classId): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'string', 'max:40'],
            'to' => ['sometimes', 'nullable', 'string', 'max:40'],
            'event_type' => ['sometimes', 'nullable', 'in:all,test,homework_deadline,assignment_deadline,revision_session,lesson,academic_event,other'],
            'include_archived' => ['sometimes', 'nullable'],
        ]);

        return response()->json($this->service->feedForTeacher($this->profile($request), $classId, $filters));
    }

    public function show(Request $request, int $classId, int $eventId): JsonResponse
    {
        return response()->json($this->service->showEventForTeacher($this->profile($request), $classId, $eventId));
    }

    public function store(Request $request, int $classId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'event_type' => ['required', 'in:test,homework_deadline,assignment_deadline,revision_session,lesson,academic_event,other'],
            'starts_at' => ['required', 'string', 'max:40'],
            'ends_at' => ['nullable', 'string', 'max:40'],
            'location_or_link' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,published'],
            'reminder_minutes_before' => ['nullable', 'integer', 'min:5', 'max:10080'],
        ]);

        $attachment = $request->file('attachment');

        return response()->json(
            $this->service->createEvent($this->profile($request), $classId, $validated, $attachment),
            201
        );
    }

    public function update(Request $request, int $classId, int $eventId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'event_type' => ['sometimes', 'in:test,homework_deadline,assignment_deadline,revision_session,lesson,academic_event,other'],
            'starts_at' => ['sometimes', 'string', 'max:40'],
            'ends_at' => ['nullable', 'string', 'max:40'],
            'location_or_link' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,published,cancelled,archived'],
            'reminder_minutes_before' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'remove_attachment' => ['sometimes'],
        ]);

        $attachment = $request->file('attachment');

        return response()->json(
            $this->service->updateEvent($this->profile($request), $classId, $eventId, $validated, $attachment)
        );
    }

    public function cancel(Request $request, int $classId, int $eventId): JsonResponse
    {
        return response()->json($this->service->cancelEvent($this->profile($request), $classId, $eventId));
    }

    public function archive(Request $request, int $classId, int $eventId): JsonResponse
    {
        return response()->json($this->service->archiveEvent($this->profile($request), $classId, $eventId));
    }

    public function downloadAttachment(Request $request, int $classId, int $eventId): Response|StreamedResponse
    {
        return $this->service->streamEventAttachmentForTeacher($this->profile($request), $classId, $eventId);
    }

    private function profile(Request $request): TeacherProfile
    {
        return $request->user()->teacherProfile;
    }
}
