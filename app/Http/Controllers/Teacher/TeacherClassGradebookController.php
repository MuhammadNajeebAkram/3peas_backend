<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Phase7G\ToggleAssessmentExclusionRequest;
use App\Http\Requests\Phase7G\UpdateGradebookConfigRequest;
use App\Http\Services\AcademicPerformanceService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassGradebookController extends Controller
{
    public function __construct(private readonly AcademicPerformanceService $service)
    {
    }

    public function gradebook(Request $request, int $classId): JsonResponse
    {
        $filters = $request->validate([
            'study_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assessment_type' => ['sometimes', 'nullable', 'in:all,mcq_test,assignment'],
            'assessment_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'book_unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'book_unit_topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'released_only' => ['sometimes', 'nullable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'student_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->service->getTeacherGradebook($this->profile($request), $classId, $filters)
        );
    }

    public function config(Request $request, int $classId): JsonResponse
    {
        return response()->json(
            $this->service->getGradebookConfig($this->profile($request), $classId)
        );
    }

    public function updateConfig(UpdateGradebookConfigRequest $request, int $classId): JsonResponse
    {
        return response()->json(
            $this->service->updateGradebookConfig($this->profile($request), $classId, $request->validated())
        );
    }

    public function toggleExclusion(ToggleAssessmentExclusionRequest $request, int $classId): JsonResponse
    {
        return response()->json(
            $this->service->toggleAssessmentExclusion($this->profile($request), $classId, $request->validated())
        );
    }

    public function performance(Request $request, int $classId): JsonResponse
    {
        $filters = $request->validate([
            'study_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assessment_type' => ['sometimes', 'nullable', 'in:all,mcq_test,assignment'],
            'assessment_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'book_unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'book_unit_topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'released_only' => ['sometimes', 'nullable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'student_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->service->getTeacherClassPerformance($this->profile($request), $classId, $filters)
        );
    }

    private function profile(Request $request): TeacherProfile
    {
        return $request->user()->teacherProfile;
    }
}
