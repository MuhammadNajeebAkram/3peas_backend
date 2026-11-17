<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassAttendanceService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassAttendanceController extends Controller
{
    public function __construct(private readonly TeacherClassAttendanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->service->listForStudent($this->student($request), $filters));
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
