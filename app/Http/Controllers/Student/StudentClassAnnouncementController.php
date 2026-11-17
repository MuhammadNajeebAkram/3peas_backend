<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassAnnouncementService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassAnnouncementController extends Controller
{
    public function __construct(private TeacherClassAnnouncementService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->service->listForStudent($this->student($request), $request->query())
        );
    }

    public function show(Request $request, int $announcementId): JsonResponse
    {
        return response()->json(
            $this->service->showForStudent($this->student($request), $announcementId)
        );
    }

    public function markRead(Request $request, int $announcementId): JsonResponse
    {
        return response()->json(
            $this->service->markReadForStudent($this->student($request), $announcementId)
        );
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
