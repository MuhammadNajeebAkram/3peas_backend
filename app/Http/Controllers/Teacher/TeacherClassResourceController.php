<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherSubjectResourceService;
use App\Http\Services\TeacherSubscriptionService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 7B Teacher Portal Read-Only Subject-Specific Educational Resource Library.
 */
class TeacherClassResourceController extends Controller
{
    public function __construct(
        private TeacherSubjectResourceService $resources,
        private TeacherSubscriptionService $subscriptions
    ) {}

    public function overview(Request $request, int $classId): JsonResponse
    {
        $profile = $this->profile($request);
        $class = $this->resources->resolveOwnedActiveClass($profile, $classId);

        return response()->json([
            'success' => true,
            'entitlements' => [
                'classes_manage' => true,
                'question_bank_access' => $this->subscriptions->canUseFeature($profile->id, 'question_bank.access'),
            ],
            ...$this->resources->overview($class),
        ]);
    }

    public function textbooks(Request $request, int $classId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->resources->textbooks($class, $filters),
        ]);
    }

    public function textbookDetail(Request $request, int $classId, int $bookId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->resources->textbookDetail($class, $bookId),
        ]);
    }

    public function units(Request $request, int $classId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $filters = $request->validate([
            'book_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->resources->units($class, $filters),
        ]);
    }

    public function topicContent(Request $request, int $classId, int $topicId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->resources->topicContent($class, $topicId),
        ]);
    }

    public function slos(Request $request, int $classId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $filters = $request->validate([
            'slo_domain_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cognitive_level' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_alp' => ['sometimes', 'nullable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->resources->slos($class, $filters),
        ]);
    }

    public function questions(Request $request, int $classId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $filters = $request->validate([
            'book_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'slo_topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'difficulty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4'],
            'cognitive_domain' => ['sometimes', 'nullable', 'string', 'max:40'],
            'mcq_format' => ['sometimes', 'nullable', 'string', 'max:40'],
            'board_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1990', 'max:2100'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_alp' => ['sometimes', 'nullable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->resources->questions($class, $filters),
        ]);
    }

    public function pastPapers(Request $request, int $classId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $filters = $request->validate([
            'source' => ['sometimes', 'in:all,structured,catalog'],
            'board_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1990', 'max:2100'],
            'group' => ['sometimes', 'nullable', 'string', 'max:20'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->resources->pastPapers($class, $filters),
        ]);
    }

    public function pastPaperDetail(Request $request, int $classId, string $source, string $identifier): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->resources->pastPaperDetail($class, $source, $identifier),
        ]);
    }

    public function streamPastPaperPage(
        Request $request,
        int $classId,
        string $source,
        string $identifier,
        int $pageId
    ): Response {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $variant = $request->query('variant', 'image') === 'thumb' ? 'thumb' : 'image';

        return $this->resources->streamPastPaperPage($class, $source, $identifier, $pageId, $variant);
    }

    public function modelPapers(Request $request, int $classId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            ...$this->resources->modelPapers($class, $filters),
        ]);
    }

    public function modelPaperDetail(Request $request, int $classId, int $paperId): JsonResponse
    {
        $class = $this->resources->resolveOwnedActiveClass($this->profile($request), $classId);

        return response()->json([
            'success' => true,
            ...$this->resources->modelPaperDetail($class, $paperId),
        ]);
    }

    private function profile(Request $request): TeacherProfile
    {
        $profile = $request->attributes->get('teacher_profile');
        abort_unless($profile instanceof TeacherProfile, 403, 'Teacher access required.');

        return $profile;
    }
}
