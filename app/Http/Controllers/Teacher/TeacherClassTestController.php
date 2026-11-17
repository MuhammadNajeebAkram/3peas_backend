<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\SaveTeacherClassTestRequest;
use App\Http\Services\TeacherMcqTestService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassTestController extends Controller
{
    public function __construct(private TeacherMcqTestService $service) {}

    public function meta(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->service->meta($class),
        ]);
    }

    public function questionBank(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->service->questionBank($class, $request->query()),
        ]);
    }

    public function showStimulusGroup(Request $request, int $classId, int $groupId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->showStimulusGroup($class, $groupId));
    }

    public function autoSelect(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'question_count' => ['required', 'integer', 'min:1', 'max:100'],
            'book_id' => ['nullable', 'integer', 'min:1'],
            'unit_id' => ['nullable', 'integer', 'min:1'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['integer', 'min:1'],
            'topic_id' => ['nullable', 'integer', 'min:1'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'min:1'],
            'slo_topic_id' => ['nullable', 'integer', 'min:1'],
            'slo_topic_ids' => ['nullable', 'array'],
            'slo_topic_ids.*' => ['integer', 'min:1'],
            'difficulty' => ['nullable', 'integer', 'min:1', 'max:5'],
            'difficulties' => ['nullable', 'array'],
            'cognitive_domain_id' => ['nullable', 'integer', 'min:1'],
            'cognitive_domain_ids' => ['nullable', 'array'],
            'question_presentation_type_id' => ['nullable', 'integer', 'min:1'],
            'mcq_format' => ['nullable', 'string', 'max:32'],
            'board_id' => ['nullable', 'integer', 'min:1'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'session_id' => ['nullable', 'integer', 'min:1'],
            'is_alp' => ['nullable'],
            'exercise_question' => ['nullable'],
            'search' => ['nullable', 'string', 'max:200'],
            'exclude_question_ids' => ['nullable', 'array'],
            'exclude_question_ids.*' => ['integer', 'min:1'],
            'seed' => ['nullable', 'integer'],
        ]);

        return response()->json($this->service->autoSelect($class, $validated));
    }

    public function reviewQuestions(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1', 'max:150'],
            'question_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->reviewQuestions($class, $validated['question_ids']));
    }

    public function index(Request $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->service->listTests($class, $request->query()),
        ]);
    }

    public function show(Request $request, int $classId, int $testId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json($this->service->showTest($class, $testId));
    }

    public function store(SaveTeacherClassTestRequest $request, int $classId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $test = $this->service->createDraft($class, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Draft MCQ test created successfully.',
            'data' => $this->service->presentTestDetail($test),
        ], 201);
    }

    public function update(SaveTeacherClassTestRequest $request, int $classId, int $testId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $test = $this->service->updateDraft($class, $testId, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Draft MCQ test updated successfully.',
            'data' => $this->service->presentTestDetail($test),
        ]);
    }

    public function destroy(Request $request, int $classId, int $testId): JsonResponse
    {
        $class = $this->service->resolveOwnedActiveClass($this->profile($request), $classId);
        $this->service->deleteDraft($class, $testId);

        return response()->json([
            'success' => true,
            'message' => 'Draft MCQ test deleted successfully.',
        ]);
    }

    private function profile(Request $request): TeacherProfile
    {
        return $request->attributes->get('teacher_profile');
    }
}
