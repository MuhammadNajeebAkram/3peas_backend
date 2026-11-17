<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassCalendarService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentClassCalendarController extends Controller
{
    public function __construct(private readonly TeacherClassCalendarService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'string', 'max:40'],
            'to' => ['sometimes', 'nullable', 'string', 'max:40'],
            'event_type' => ['sometimes', 'nullable', 'in:all,test,homework_deadline,assignment_deadline,revision_session,lesson,academic_event,other'],
        ]);

        return response()->json($this->service->feedForStudent($this->student($request), $filters));
    }

    public function downloadAttachment(Request $request, int $eventId): Response|StreamedResponse
    {
        return $this->service->streamEventAttachmentForStudent($this->student($request), $eventId);
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
