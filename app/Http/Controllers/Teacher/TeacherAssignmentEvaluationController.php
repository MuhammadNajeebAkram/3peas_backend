<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Phase7G\SaveAssignmentEvaluationRequest;
use App\Http\Services\TeacherAssignmentEvaluationService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;

class TeacherAssignmentEvaluationController extends Controller
{
    public function __construct(private readonly TeacherAssignmentEvaluationService $service)
    {
    }

    /**
     * Save draft, release, reopen, correct, or finalize missing submission as 0.
     */
    public function evaluate(
        SaveAssignmentEvaluationRequest $request,
        int $classId,
        int $assignmentId,
        int $studentWebUserId
    ): JsonResponse {
        /** @var TeacherProfile $profile */
        $profile = $request->user()->teacherProfile;

        return response()->json(
            $this->service->evaluate($profile, $classId, $assignmentId, $studentWebUserId, $request->validated())
        );
    }
}
