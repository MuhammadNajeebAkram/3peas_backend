<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassAttendanceService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassAttendanceController extends Controller
{
    public function __construct(private readonly TeacherClassAttendanceService $service)
    {
    }

    public function index(Request $request, int $classId): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'nullable', 'in:all,open,finalized'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($this->service->listSessions($this->profile($request), $classId, $filters));
    }

    public function store(Request $request, int $classId): JsonResponse
    {
        $validated = $request->validate([
            'session_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'records' => ['sometimes', 'array', 'max:300'],
            'records.*.student_web_user_id' => ['required', 'integer', 'min:1'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->service->createSession($this->profile($request), $classId, $validated), 201);
    }

    public function show(Request $request, int $classId, int $sessionId): JsonResponse
    {
        return response()->json($this->service->showSession($this->profile($request), $classId, $sessionId));
    }

    public function mark(Request $request, int $classId, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'bulk_status' => ['required_without:records', 'nullable', 'in:present,absent,late,excused'],
            'records' => ['required_without:bulk_status', 'array', 'min:1', 'max:300'],
            'records.*.student_web_user_id' => ['required', 'integer', 'min:1'],
            'records.*.status' => ['required', 'in:present,absent,late,excused'],
            'records.*.remarks' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->service->markRecords($this->profile($request), $classId, $sessionId, $validated));
    }

    public function finalize(Request $request, int $classId, int $sessionId): JsonResponse
    {
        return response()->json($this->service->finalizeSession($this->profile($request), $classId, $sessionId));
    }

    public function reopen(Request $request, int $classId, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        return response()->json(
            $this->service->reopenSession($this->profile($request), $classId, $sessionId, $validated['reason'])
        );
    }

    public function studentHistory(Request $request, int $classId, int $studentWebUserId): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->service->studentHistoryForTeacher($this->profile($request), $classId, $studentWebUserId, $filters)
        );
    }

    private function profile(Request $request): TeacherProfile
    {
        return $request->user()->teacherProfile;
    }
}
