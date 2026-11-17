<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestQuestion;
use App\Models\TeacherProfile;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Teacher Portal — Phase 7C MCQ Test Builder Service.
 *
 * Strictly enforces:
 * - Owned, active TeacherClass context (curriculum_board_id, class_id, subject_id derived server-side)
 * - Canonical MCQ curriculum scoping via TeacherSubjectResourceService (never stale exam_question_tbl.unit_id/book_id)
 * - No answer-key or explanation exposure in general MCQ browsing/auto-select APIs
 * - Dedicated teacher-only answer-key/explanation review & immutable test question snapshots
 * - Draft-only edits/deletes (no Phase 7D scheduling, assignments, or student attempts)
 */
class TeacherMcqTestService
{
    private const ALLOWED_LANGUAGES = [
        TeacherClassTest::LANGUAGE_ENGLISH,
        TeacherClassTest::LANGUAGE_URDU,
        TeacherClassTest::LANGUAGE_BILINGUAL,
    ];

    private const ALLOWED_METHODS = [
        TeacherClassTest::SELECTION_MANUAL,
        TeacherClassTest::SELECTION_AUTOMATIC,
        TeacherClassTest::SELECTION_MIXED,
    ];

    private const CRITERIA_WHITELIST = [
        'book_id',
        'unit_id',
        'unit_ids',
        'topic_id',
        'topic_ids',
        'slo_topic_id',
        'slo_topic_ids',
        'difficulty',
        'difficulties',
        'cognitive_domain_id',
        'cognitive_domain',
        'cognitive_domain_ids',
        'question_presentation_type_id',
        'mcq_format',
        'board_id',
        'year',
        'session_id',
        'is_alp',
        'exercise_question',
        'search',
        'question_count',
    ];

    public function __construct(private TeacherSubjectResourceService $resources) {}

    public function resolveOwnedActiveClass(TeacherProfile $profile, int $classId): TeacherClass
    {
        return $this->resources->resolveOwnedActiveClass($profile, $classId);
    }

    public function meta(TeacherClass $class): array
    {
        $overview = $this->resources->overview($class);
        $units = $this->resources->units($class);
        $slos = $this->resources->slos($class, ['per_page' => 200]);

        return [
            'class' => $overview['class'],
            'counts' => $overview['counts'],
            'filters' => array_merge($overview['filters'], [
                'unit_tree' => $units['data'] ?? [],
                'slos' => $slos['data'] ?? [],
            ]),
        ];
    }

    public function questionBank(TeacherClass $class, array $filters = []): array
    {
        $this->validateScopedFilterIds($class, $filters);

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 12)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $pool = $this->resolveMatchingSelectionUnits($class, $filters);
        $allUnits = $pool['units'];

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $allUnits->forPage($page, $perPage)->values(),
            $allUnits->count(),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );

        $hydrated = $this->hydrateBankUnits($class, collect($paginator->items()), false);

        return [
            'class' => $this->resources->presentClassSummary($class),
            'policy' => [
                'mcq_only' => true,
                'answers_included' => false,
                'explanations_included' => false,
            ],
            'summary' => [
                'total_units' => $allUnits->count(),
                'standalone_count' => $pool['standalone_count'],
                'stimulus_group_count' => $pool['stimulus_group_count'],
                'total_eligible_mcqs' => $pool['available_mcq_count'],
            ],
            'units' => $hydrated['units']->values(),
            'data' => $hydrated['flat_questions']->values(),
            'pagination' => $this->resources->presentPagination($paginator),
        ];
    }

    /**
     * Resolve a single active stimulus group strictly within the teacher class's canonical curriculum scope.
     * Ineligible siblings (other curriculum, subject, grade, inactive, or non-MCQ) are excluded cleanly.
     */
    public function showStimulusGroup(TeacherClass $class, int $groupId): array
    {
        $groupRow = DB::table('question_scenario_groups_tbl')
            ->where('id', $groupId)
            ->where('activate', 1)
            ->select(['id', 'title', 'scenario_text', 'scenario_text_um', 'scenario_image'])
            ->first();

        if (!$groupRow) {
            $this->resources->abortJson(404, 'stimulus_group_not_found', 'Stimulus group not found.');
        }

        $eligibleRows = $this->buildFilteredMcqQuery($class, [])
            ->where('q.scenario_group_id', $groupId)
            ->orderBy('q.scenario_question_order')
            ->orderBy('q.id')
            ->get();

        if ($eligibleRows->isEmpty()) {
            $hasRawRows = DB::table('exam_question_tbl')
                ->where('scenario_group_id', $groupId)
                ->exists();

            if ($hasRawRows) {
                $this->resources->abortJson(
                    403,
                    'resource_out_of_class_scope',
                    "Stimulus group #{$groupId} does not contain eligible MCQs in this class's curriculum, grade, and subject."
                );
            }

            $this->resources->abortJson(422, 'empty_stimulus_group', "Stimulus group #{$groupId} has no active eligible MCQs.");
        }

        if ($eligibleRows->count() > 4) {
            $shuffled = $eligibleRows->all();
            for ($i = count($shuffled) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
            }
            $selectedMap = array_flip(array_map(fn ($r) => (int) $r->id, array_slice($shuffled, 0, 4)));
            $eligibleRows = $eligibleRows->filter(fn ($r) => isset($selectedMap[(int) $r->id]))->values();
        }

        $rawCounts = $this->loadRawCountsForScenarioGroups([$groupId]);
        $totalRaw = $rawCounts[$groupId] ?? $eligibleRows->count();
        $excludedIneligibleCount = max(0, $totalRaw - $eligibleRows->count());
        $presentedQuestions = $this->presentSafeMcqRows($class, $eligibleRows)->values();
        $firstQuestion = $presentedQuestions->first();

        return [
            'success' => true,
            'class' => $this->resources->presentClassSummary($class),
            'policy' => [
                'mcq_only' => true,
                'answers_included' => false,
                'explanations_included' => false,
            ],
            'data' => [
                'unit_key' => 'g:' . $groupId,
                'item_type' => 'stimulus_group',
                'scenario_group_id' => $groupId,
                'question_count' => $presentedQuestions->count(),
                'eligible_question_count' => $presentedQuestions->count(),
                'total_group_rows' => $totalRaw,
                'excluded_ineligible_count' => $excludedIneligibleCount,
                'has_ineligible_members' => $excludedIneligibleCount > 0,
                'scenario_group' => [
                    'id' => (int) $groupRow->id,
                    'title' => $groupRow->title,
                    'scenario_text' => $groupRow->scenario_text,
                    'scenario_text_um' => $groupRow->scenario_text_um,
                    'scenario_image' => $this->sanitizeMediaUrl($groupRow->scenario_image),
                ],
                'unit' => $firstQuestion['unit'] ?? null,
                'topic' => $firstQuestion['topic'] ?? null,
                'slos' => $firstQuestion['slos'] ?? [],
                'board_links' => $firstQuestion['board_links'] ?? [],
                'questions' => $presentedQuestions,
            ],
        ];
    }

    public function autoSelect(TeacherClass $class, array $payload): array
    {
        $requestedCount = (int) ($payload['question_count'] ?? 0);
        if ($requestedCount < 1 || $requestedCount > 100) {
            $this->resources->abortJson(422, 'invalid_question_count', 'Question count must be between 1 and 100.');
        }

        $filters = $payload['filters'] ?? $payload;
        $this->validateScopedFilterIds($class, $filters);

        $excludeIds = $this->extractIntList(
            array_merge(is_array($filters) ? $filters : [], $payload),
            'exclude_question_ids'
        );

        $seed = isset($payload['seed']) && is_numeric($payload['seed']) ? (int) $payload['seed'] : null;
        $pool = $this->resolveMatchingSelectionUnits($class, $filters, $excludeIds, $seed);
        $availableCount = $pool['available_mcq_count'];

        if ($availableCount < $requestedCount) {
            $poolBreakdown = [];
            if ($pool['standalone_count'] > 0) {
                $poolBreakdown[] = "{$pool['standalone_count']} standalone";
            }
            if ($pool['stimulus_group_count'] > 0) {
                $poolBreakdown[] = "{$pool['stimulus_group_count']} stimulus group(s)";
            }
            $breakdownText = $poolBreakdown !== [] ? ' (' . implode(', ', $poolBreakdown) . ')' : '';

            $guidance = $availableCount === 0
                ? "No active MCQs match your selected filter criteria for this class. Try clearing or broadening your filters (e.g., choose 'All Formats', 'All Chapters', or adjust difficulty)."
                : "Only {$availableCount} matching active MCQs are available for this class and filter criteria{$breakdownText}, but {$requestedCount} were requested. Reduce the requested question count to {$availableCount} or broaden your filter criteria.";

            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'insufficient_matching_mcqs',
                'message' => $guidance,
                'data' => [
                    'requested_count' => $requestedCount,
                    'available_count' => $availableCount,
                    'standalone_count' => $pool['standalone_count'],
                    'stimulus_group_count' => $pool['stimulus_group_count'],
                ],
            ], 422));
        }

        $seed = isset($payload['seed']) && is_numeric($payload['seed']) ? (int) $payload['seed'] : null;
        $picked = $this->pickAtomicUnits($pool['units']->all(), $requestedCount, $seed);
        $chosenUnits = collect($picked['units']);

        $orderedChosenUnits = $chosenUnits->sortBy(function (array $unit) {
            return [$unit['bucket'], $unit['sort_id']];
        })->values();

        $hydrated = $this->hydrateBankUnits($class, $orderedChosenUnits, false);
        $items = $hydrated['flat_questions']->values();
        $selectedCount = $items->count();
        $exactCountMet = $selectedCount === $requestedCount;

        $selectedStandaloneCount = $orderedChosenUnits->where('item_type', 'standalone')->count();
        $selectedGroupCount = $orderedChosenUnits->where('item_type', 'stimulus_group')->count();

        $countAdjustmentReason = null;
        if (!$exactCountMet) {
            $countAdjustmentReason = "Requested {$requestedCount} MCQs, but {$selectedCount} MCQs were selected ({$selectedStandaloneCount} standalone, {$selectedGroupCount} complete stimulus group(s)) because stimulus-based question groups cannot be split.";
        }

        return [
            'success' => true,
            'class' => $this->resources->presentClassSummary($class),
            'policy' => [
                'mcq_only' => true,
                'answers_included' => false,
                'explanations_included' => false,
            ],
            'requested_count' => $requestedCount,
            'available_count' => $availableCount,
            'selected_count' => $selectedCount,
            'exact_count_met' => $exactCountMet,
            'count_adjustment_reason' => $countAdjustmentReason,
            'selection_summary' => [
                'standalone_count' => $selectedStandaloneCount,
                'stimulus_group_count' => $selectedGroupCount,
                'total_questions' => $selectedCount,
            ],
            'criteria' => $this->sanitizeSelectionCriteria(array_merge(
                is_array($filters) ? $filters : [],
                ['question_count' => $requestedCount]
            )),
            'units' => $hydrated['units']->values(),
            'data' => $items,
        ];
    }

    /**
     * Teacher-only pre-save answer-key and explanation review for verified scoped MCQs.
     * Separated from general browsing endpoints so answer keys stay excluded unless explicitly requested
     * by an authorized teacher building a test.
     */
    public function reviewQuestions(TeacherClass $class, array $questionIds): array
    {
        $orderedIds = collect($questionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($orderedIds === []) {
            $this->resources->abortJson(422, 'invalid_question_ids', 'Provide at least one valid MCQ question ID to review.');
        }

        $verifiedRows = $this->verifyScopedActiveMcqsWithDetails($class, $orderedIds);
        $canonicalRows = $this->orderRowsAllFormats($verifiedRows);
        $presented = $this->presentTeacherReviewRows($class, $canonicalRows)->values();

        return [
            'success' => true,
            'class' => $this->resources->presentClassSummary($class),
            'policy' => [
                'teacher_only_review' => true,
                'answers_included' => true,
                'explanations_included' => true,
            ],
            'presentation' => $this->buildPresentationSections($presented),
            'data' => $presented,
        ];
    }

    public function listTests(TeacherClass $class, array $filters = []): array
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));

        $summaryBase = TeacherClassTest::forClass($class);
        $summary = [
            'total_tests' => (clone $summaryBase)->count(),
            'draft_tests' => (clone $summaryBase)->where('status', TeacherClassTest::STATUS_DRAFT)->count(),
            'scheduled_tests' => (clone $summaryBase)->where('status', TeacherClassTest::STATUS_SCHEDULED)->count(),
            'published_tests' => (clone $summaryBase)->where('status', TeacherClassTest::STATUS_PUBLISHED)->count(),
            'closed_tests' => (clone $summaryBase)->where('status', TeacherClassTest::STATUS_CLOSED)->count(),
            'archived_tests' => (clone $summaryBase)->where('status', TeacherClassTest::STATUS_ARCHIVED)->count(),
        ];

        $query = TeacherClassTest::forClass($class)
            ->withCount('assignments')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['selection_method'])) {
            $query->where('selection_method', (string) $filters['selection_method']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($sq) use ($term) {
                $sq->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage);

        return [
            'class' => $this->resources->presentClassSummary($class),
            'summary' => $summary,
            'data' => collect($paginator->items())
                ->map(fn (TeacherClassTest $test) => $this->presentTestSummary($test))
                ->values(),
            'pagination' => $this->resources->presentPagination($paginator),
        ];
    }

    public function showTest(TeacherClass $class, int $testId): array
    {
        $test = $this->findOwnedTest($class, $testId);
        $test->load(['questions' => fn ($q) => $q->orderBy('question_order')->orderBy('id')]);

        return [
            'success' => true,
            'class' => $this->resources->presentClassSummary($class),
            'data' => $this->presentTestDetail($test),
        ];
    }

    public function createDraft(TeacherClass $class, array $payload): TeacherClassTest
    {
        return DB::transaction(function () use ($class, $payload) {
            $normalized = $this->prepareValidatedTestPayload($class, $payload);

            $test = new TeacherClassTest();
            $test->teacher_class_id = $class->id;
            $test->teacher_profile_id = $class->teacher_profile_id;
            $test->curriculum_board_id = $class->curriculum_board_id;
            $test->class_id = $class->class_id;
            $test->subject_id = $class->subject_id;
            $test->status = TeacherClassTest::STATUS_DRAFT;

            $this->persistTestAndQuestions($test, $normalized);

            return $test->fresh(['questions']);
        });
    }

    public function updateDraft(TeacherClass $class, int $testId, array $payload): TeacherClassTest
    {
        return DB::transaction(function () use ($class, $testId, $payload) {
            $test = $this->findOwnedTestForUpdate($class, $testId);

            if (!$test->isDraft()) {
                $this->resources->abortJson(409, 'test_not_editable', 'Only draft tests can be edited.');
            }

            $normalized = $this->prepareValidatedTestPayload($class, $payload);
            $this->persistTestAndQuestions($test, $normalized);

            return $test->fresh(['questions']);
        });
    }

    public function deleteDraft(TeacherClass $class, int $testId): void
    {
        DB::transaction(function () use ($class, $testId) {
            $test = $this->findOwnedTestForUpdate($class, $testId);

            if (!$test->isDraft()) {
                $this->resources->abortJson(409, 'test_not_editable', 'Only draft tests can be deleted.');
            }

            $test->delete();
        });
    }

    public function presentTestSummary(TeacherClassTest $test): array
    {
        $summary = [
            'id' => (int) $test->id,
            'teacher_class_id' => (int) $test->teacher_class_id,
            'curriculum_board_id' => (int) $test->curriculum_board_id,
            'class_id' => (int) $test->class_id,
            'subject_id' => (int) $test->subject_id,
            'title' => $test->title,
            'description' => $test->description,
            'instructions' => $test->instructions,
            'selection_method' => $test->selection_method,
            'selection_criteria' => $test->selection_criteria_json,
            'total_questions' => (int) $test->total_questions,
            'marks_per_question' => round((float) $test->marks_per_question, 2),
            'total_marks' => round((float) $test->total_marks, 2),
            'negative_marks_per_wrong' => round((float) $test->negative_marks_per_wrong, 2),
            'language_mode' => $test->language_mode,
            'status' => $test->status,
            'test_version' => (int) ($test->test_version ?? 1),
            'scheduled_start_at' => $test->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $test->scheduled_end_at?->toIso8601String(),
            'duration_minutes' => $test->duration_minutes !== null ? (int) $test->duration_minutes : null,
            'max_attempts' => (int) ($test->max_attempts ?? 1),
            'result_visibility' => $test->result_visibility ?? TeacherClassTest::VISIBILITY_MANUAL_RELEASE,
            'allow_answer_review' => (bool) ($test->allow_answer_review ?? false),
            'results_released_at' => $test->results_released_at?->toIso8601String(),
            'published_at' => $test->published_at?->toIso8601String(),
            'closed_at' => $test->closed_at?->toIso8601String(),
            'archived_at' => $test->archived_at?->toIso8601String(),
            'assignments_count' => isset($test->assignments_count) ? (int) $test->assignments_count : $test->assignments()->count(),
            'is_editable' => $test->isDraft(),
            'can_publish' => $test->isDraft() && (int) $test->total_questions > 0,
            'can_assign' => in_array($test->status, [TeacherClassTest::STATUS_SCHEDULED, TeacherClassTest::STATUS_PUBLISHED], true),
            'created_at' => $test->created_at?->toIso8601String(),
            'updated_at' => $test->updated_at?->toIso8601String(),
        ];

        return $summary;
    }

    public function presentTestDetail(TeacherClassTest $test): array
    {
        $summary = $this->presentTestSummary($test);
        $flatQuestions = $test->questions->map(function (TeacherClassTestQuestion $q) {
            $meta = is_array($q->metadata_snapshot_json) ? $q->metadata_snapshot_json : [];

            return [
                'id' => (int) $q->id,
                'question_id' => (int) $q->question_id,
                'question_order' => (int) $q->question_order,
                'scenario_question_order' => isset($meta['scenario_question_order']) ? (int) $meta['scenario_question_order'] : null,
                'marks' => round((float) $q->marks, 2),
                'negative_marks' => round((float) $q->negative_marks, 2),
                'question_source' => $q->question_source,
                'question' => $q->question_text_snapshot,
                'question_um' => $q->question_text_um_snapshot,
                'question_lang' => (int) ($meta['question_lang'] ?? 0),
                'question_um_lang' => (int) ($meta['question_um_lang'] ?? 1),
                'difficulty' => $meta['difficulty'] ?? null,
                'difficulty_label' => $meta['difficulty_label'] ?? null,
                'is_alp_question' => (bool) ($meta['is_alp_question'] ?? false),
                'exercise_question' => (bool) ($meta['exercise_question'] ?? false),
                'has_diagram' => (bool) ($meta['has_diagram'] ?? false),
                'cognitive_domain' => $meta['cognitive_domain'] ?? null,
                'presentation_type' => $meta['presentation_type'] ?? null,
                'unit' => $meta['unit'] ?? ($q->unit_id ? ['id' => (int) $q->unit_id] : null),
                'topic' => $meta['topic'] ?? ($q->topic_id ? ['id' => (int) $q->topic_id] : null),
                'slos' => $meta['slos'] ?? [],
                'board_links' => $meta['board_links'] ?? [],
                'scenario_group' => ($q->scenario_group_id || $q->scenario_text_snapshot || $q->scenario_text_um_snapshot || $q->scenario_image_snapshot) ? [
                    'id' => $q->scenario_group_id ? (int) $q->scenario_group_id : null,
                    'title' => $meta['scenario_title'] ?? null,
                    'scenario_text' => $q->scenario_text_snapshot,
                    'scenario_text_um' => $q->scenario_text_um_snapshot,
                    'scenario_image' => $this->sanitizeMediaUrl($q->scenario_image_snapshot),
                ] : null,
                'options' => $q->options_snapshot_json ?? [],
                'correct_option_id' => $q->correct_option_id_snapshot ? (int) $q->correct_option_id_snapshot : null,
                'correct_option_key' => $q->correct_option_key_snapshot,
                'explanation' => $q->explanation_snapshot,
                'explanation_um' => $q->explanation_um_snapshot,
            ];
        })->values();

        $summary['questions'] = $flatQuestions;
        $summary['presentation'] = $this->buildPresentationSections($flatQuestions);

        return $summary;
    }

    private function prepareValidatedTestPayload(TeacherClass $class, array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $this->resources->abortJson(422, 'invalid_test_title', 'Test title is required.');
        }

        $method = (string) ($payload['selection_method'] ?? TeacherClassTest::SELECTION_MANUAL);
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            $this->resources->abortJson(422, 'invalid_selection_method', 'Selection method must be manual, automatic, or mixed.');
        }

        $languageMode = (string) ($payload['language_mode'] ?? TeacherClassTest::LANGUAGE_BILINGUAL);
        if (!in_array($languageMode, self::ALLOWED_LANGUAGES, true)) {
            $this->resources->abortJson(422, 'invalid_language_mode', 'Language mode must be english, urdu, or bilingual.');
        }

        $defaultMarks = isset($payload['marks_per_question']) ? (float) $payload['marks_per_question'] : 1.0;
        if ($defaultMarks <= 0 || $defaultMarks > 100) {
            $this->resources->abortJson(422, 'invalid_marks_per_question', 'Marks per question must be greater than 0 and at most 100.');
        }
        $defaultMarks = round($defaultMarks, 2);

        $defaultNegative = isset($payload['negative_marks_per_wrong']) ? (float) $payload['negative_marks_per_wrong'] : 0.0;
        if ($defaultNegative < 0 || $defaultNegative > 100) {
            $this->resources->abortJson(422, 'invalid_negative_marks', 'Negative marks per wrong answer cannot be negative.');
        }
        $defaultNegative = round($defaultNegative, 2);

        $rawQuestions = $payload['questions'] ?? [];
        if (!is_array($rawQuestions) || count($rawQuestions) === 0) {
            $this->resources->abortJson(422, 'empty_test_questions', 'Select at least one MCQ before saving the test.');
        }

        $seenIds = [];
        $questionItems = [];
        foreach ($rawQuestions as $item) {
            $qid = is_array($item) ? (int) ($item['question_id'] ?? 0) : (int) $item;
            if ($qid <= 0) {
                $this->resources->abortJson(422, 'invalid_test_question', 'Every test question entry must specify a valid question ID.');
            }
            if (isset($seenIds[$qid])) {
                $this->resources->abortJson(422, 'duplicate_test_question', "Duplicate MCQ #{$qid} is not allowed in the same test.");
            }
            $seenIds[$qid] = true;

            $qMarks = (is_array($item) && array_key_exists('marks', $item) && $item['marks'] !== null && $item['marks'] !== '')
                ? (float) $item['marks']
                : $defaultMarks;
            if ($qMarks <= 0 || $qMarks > 100) {
                $this->resources->abortJson(422, 'invalid_question_marks', "Question #{$qid} must have marks greater than 0.");
            }

            $qNegative = (is_array($item) && array_key_exists('negative_marks', $item) && $item['negative_marks'] !== null && $item['negative_marks'] !== '')
                ? (float) $item['negative_marks']
                : $defaultNegative;
            if ($qNegative < 0 || $qNegative > 100) {
                $this->resources->abortJson(422, 'invalid_question_negative_marks', "Question #{$qid} cannot have negative penalty marks less than 0.");
            }

            $questionItems[$qid] = [
                'question_id' => $qid,
                'marks' => round($qMarks, 2),
                'negative_marks' => round($qNegative, 2),
            ];
        }

        $orderedIds = array_keys($questionItems);
        $verifiedRows = $this->verifyScopedActiveMcqsWithDetails($class, $orderedIds);

        // Enforce atomic stimulus groups (cannot save only part of an eligible group)
        // and normalize All Formats ordering (standalone first, then contiguous stimulus groups).
        $this->assertCompleteStimulusGroupsForSave($class, $verifiedRows);
        $canonicalRows = $this->orderRowsAllFormats($verifiedRows);
        $reviewRows = $this->presentTeacherReviewRows($class, $canonicalRows);

        $snapshots = [];
        $totalMarks = 0.0;
        foreach ($reviewRows as $index => $review) {
            $qid = (int) $review['id'];
            $item = $questionItems[$qid] ?? null;
            if (!$item) {
                $this->resources->abortJson(422, 'invalid_test_question', "Question #{$qid} could not be resolved.");
            }

            $totalMarks += $item['marks'];
            $scenario = $review['scenario_group'] ?? null;

            $snapshots[] = [
                'question_id' => $qid,
                'unit_id' => $review['unit']['id'] ?? null,
                'topic_id' => $review['topic']['id'] ?? null,
                'slo_topic_id' => $review['slo_topic_id'] ?? null,
                'scenario_group_id' => $scenario['id'] ?? null,
                'question_order' => $index + 1,
                'marks' => $item['marks'],
                'negative_marks' => $item['negative_marks'],
                'question_source' => TeacherClassTestQuestion::SOURCE_BANK,
                'question_text_snapshot' => (string) ($review['question'] ?? ''),
                'question_text_um_snapshot' => $review['question_um'],
                'scenario_text_snapshot' => $scenario['scenario_text'] ?? null,
                'scenario_text_um_snapshot' => $scenario['scenario_text_um'] ?? null,
                'scenario_image_snapshot' => $scenario['scenario_image'] ?? null,
                'options_snapshot_json' => $review['options'] ?? [],
                'correct_option_id_snapshot' => $review['correct_option_id'],
                'correct_option_key_snapshot' => $review['correct_option_key'],
                'explanation_snapshot' => $review['explanation'],
                'explanation_um_snapshot' => $review['explanation_um'],
                'metadata_snapshot_json' => [
                    'question_lang' => $review['question_lang'],
                    'question_um_lang' => $review['question_um_lang'],
                    'difficulty' => $review['difficulty'],
                    'difficulty_label' => $review['difficulty_label'],
                    'is_alp_question' => $review['is_alp_question'],
                    'exercise_question' => $review['exercise_question'],
                    'has_diagram' => $review['has_diagram'],
                    'cognitive_domain' => $review['cognitive_domain'],
                    'presentation_type' => $review['presentation_type'],
                    'unit' => $review['unit'],
                    'topic' => $review['topic'],
                    'slos' => $review['slos'],
                    'board_links' => $review['board_links'],
                    'scenario_title' => $scenario['title'] ?? null,
                    'scenario_question_order' => $review['scenario_question_order'] ?? null,
                ],
            ];
        }

        return [
            'title' => $title,
            'description' => isset($payload['description']) ? $this->nullableTrim((string) $payload['description']) : null,
            'instructions' => isset($payload['instructions']) ? $this->nullableTrim((string) $payload['instructions']) : null,
            'selection_method' => $method,
            'selection_criteria_json' => $this->sanitizeSelectionCriteria($payload['selection_criteria'] ?? null),
            'total_questions' => count($snapshots),
            'marks_per_question' => $defaultMarks,
            'total_marks' => round($totalMarks, 2),
            'negative_marks_per_wrong' => $defaultNegative,
            'language_mode' => $languageMode,
            'snapshots' => $snapshots,
        ];
    }

    private function persistTestAndQuestions(TeacherClassTest $test, array $normalized): void
    {
        $test->title = $normalized['title'];
        $test->description = $normalized['description'];
        $test->instructions = $normalized['instructions'];
        $test->selection_method = $normalized['selection_method'];
        $test->selection_criteria_json = $normalized['selection_criteria_json'];
        $test->total_questions = $normalized['total_questions'];
        $test->marks_per_question = $normalized['marks_per_question'];
        $test->total_marks = $normalized['total_marks'];
        $test->negative_marks_per_wrong = $normalized['negative_marks_per_wrong'];
        $test->language_mode = $normalized['language_mode'];
        $test->save();

        $test->questions()->delete();
        foreach ($normalized['snapshots'] as $snapshot) {
            $test->questions()->create($snapshot);
        }
    }

    private function findOwnedTest(TeacherClass $class, int $testId): TeacherClassTest
    {
        $test = TeacherClassTest::find($testId);
        if (!$test) {
            $this->resources->abortJson(404, 'teacher_class_test_not_found', 'Test not found.');
        }

        if ((int) $test->teacher_class_id !== (int) $class->id || (int) $test->teacher_profile_id !== (int) $class->teacher_profile_id) {
            $this->resources->abortJson(403, 'forbidden_teacher_test', 'You do not have permission to access this test.');
        }

        return $test;
    }

    private function findOwnedTestForUpdate(TeacherClass $class, int $testId): TeacherClassTest
    {
        $test = TeacherClassTest::lockForUpdate()->find($testId);
        if (!$test) {
            $this->resources->abortJson(404, 'teacher_class_test_not_found', 'Test not found.');
        }

        if ((int) $test->teacher_class_id !== (int) $class->id || (int) $test->teacher_profile_id !== (int) $class->teacher_profile_id) {
            $this->resources->abortJson(403, 'forbidden_teacher_test', 'You do not have permission to modify this test.');
        }

        return $test;
    }

    private function validateScopedFilterIds(TeacherClass $class, array $filters): void
    {
        if (!empty($filters['book_id'])) {
            $this->resources->assertScopedBook($class, (int) $filters['book_id']);
        }

        foreach ($this->extractIntList($filters, 'unit_ids', 'unit_id') as $unitId) {
            $this->resources->assertScopedUnit($class, $unitId);
        }

        foreach ($this->extractIntList($filters, 'topic_ids', 'topic_id') as $topicId) {
            $this->resources->assertScopedTopic($class, $topicId);
        }

        foreach ($this->extractIntList($filters, 'slo_topic_ids', 'slo_topic_id') as $sloId) {
            $this->resources->assertScopedSloTopic($class, $sloId);
        }
    }

    private function buildFilteredMcqQuery(TeacherClass $class, array $filters = [], bool $includeExplanations = false): Builder
    {
        $selects = [
            'q.id',
            'q.question',
            'q.question_um',
            'q.question_lang',
            'q.question_um_lang',
            'q.marks',
            'q.difficulty',
            'q.is_alp_question',
            'q.exercise_question',
            'q.has_diagram',
            'q.topic_id',
            'q.cognitive_domain as cognitive_domain_id',
            'cd.name as cognitive_domain_name',
            'cd.short as cognitive_domain_short',
            'q.question_presentation_type_id',
            'qpt.type_name as presentation_type_name',
            'qpt.code as presentation_type_code',
            'q.scenario_group_id',
            'active_sg.id as active_scenario_group_id',
            'q.scenario_question_order',
            'q.slo_topic_id',
            'u.id as unit_id',
            'u.unit_no',
            'u.unit_name',
            't.topic_name',
            'b.id as book_id',
            'b.book_name',
        ];

        if ($includeExplanations) {
            $selects[] = 'q.explanation';
            $selects[] = 'q.explanation_um';
        }

        $query = $this->resources->scopedMcqQuestionsQuery($class)
            ->leftJoin('cognitive_domain_tbl as cd', 'cd.id', '=', 'q.cognitive_domain')
            ->leftJoin('question_presentation_type_tbl as qpt', 'qpt.id', '=', 'q.question_presentation_type_id')
            ->leftJoin('question_scenario_groups_tbl as active_sg', function ($join) {
                $join->on('active_sg.id', '=', 'q.scenario_group_id')
                    ->where('active_sg.activate', '=', 1);
            })
            ->select($selects);

        if (!empty($filters['book_id'])) {
            $query->where('b.id', (int) $filters['book_id']);
        }

        $unitIds = $this->extractIntList($filters, 'unit_ids', 'unit_id');
        if ($unitIds !== []) {
            $query->whereIn('u.id', $unitIds);
        }

        $topicIds = $this->extractIntList($filters, 'topic_ids', 'topic_id');
        if ($topicIds !== []) {
            $query->whereIn('t.id', $topicIds);
        }

        $sloIds = $this->extractIntList($filters, 'slo_topic_ids', 'slo_topic_id');
        if ($sloIds !== []) {
            $query->where(function ($outer) use ($sloIds) {
                $outer->whereIn('q.slo_topic_id', $sloIds)
                    ->orWhereExists(function ($sub) use ($sloIds) {
                        $sub->selectRaw('1')
                            ->from('topic_slo_topic_tbl as tst')
                            ->whereColumn('tst.topic_id', 't.id')
                            ->whereIn('tst.slo_topic_id', $sloIds);
                    })
                    ->orWhereExists(function ($sub) use ($sloIds) {
                        $sub->selectRaw('1')
                            ->from('unit_slo_topic_tbl as ust')
                            ->whereColumn('ust.unit_id', 'u.id')
                            ->whereIn('ust.slo_topic_id', $sloIds);
                    });
            });
        }

        $difficulties = $this->extractIntList($filters, 'difficulties', 'difficulty');
        if ($difficulties !== []) {
            $query->whereIn('q.difficulty', $difficulties);
        }

        $cognitiveIds = $this->extractIntList($filters, 'cognitive_domain_ids', 'cognitive_domain_id');
        if ($cognitiveIds === [] && !empty($filters['cognitive_domain']) && is_numeric($filters['cognitive_domain'])) {
            $cognitiveIds = [(int) $filters['cognitive_domain']];
        }
        if ($cognitiveIds !== []) {
            $query->whereIn('q.cognitive_domain', $cognitiveIds);
        }

        $presentationTypeId = null;
        if (!empty($filters['question_presentation_type_id']) && is_numeric($filters['question_presentation_type_id'])) {
            $presentationTypeId = (int) $filters['question_presentation_type_id'];
        } elseif (!empty($filters['mcq_format'])) {
            $fmt = strtolower(trim((string) $filters['mcq_format']));
            if (is_numeric($fmt)) {
                $presentationTypeId = (int) $fmt;
            } elseif ($fmt === 'straight' || $fmt === 'st') {
                $presentationTypeId = 1;
            } elseif ($fmt === 'stimulus') {
                $presentationTypeId = 2;
            }
        }

        if ($presentationTypeId === 1) {
            $query->where(function ($fq) {
                $fq->where('q.question_presentation_type_id', 1)
                    ->orWhereNull('q.question_presentation_type_id')
                    ->orWhere('qpt.code', 'st');
            });
        } elseif ($presentationTypeId === 2) {
            $query->where(function ($fq) {
                $fq->where('q.question_presentation_type_id', 2)
                    ->orWhere('qpt.code', 'stimulus');
            })->whereNotNull('q.scenario_group_id');
        } elseif ($presentationTypeId !== null) {
            $query->where('q.question_presentation_type_id', $presentationTypeId);
        }

        if (!empty($filters['board_id']) || !empty($filters['year']) || !empty($filters['session_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('exam_question_board_tbl as eqb')
                    ->whereColumn('eqb.question_id', 'q.id')
                    ->where('eqb.activate', 1);

                if (!empty($filters['board_id'])) {
                    $sub->where('eqb.board_id', (int) $filters['board_id']);
                }
                if (!empty($filters['year'])) {
                    $sub->where('eqb.year', (int) $filters['year']);
                }
                if (!empty($filters['session_id'])) {
                    $sub->where('eqb.session_id', (int) $filters['session_id']);
                }
            });
        }

        if (array_key_exists('is_alp', $filters) && $filters['is_alp'] !== '' && $filters['is_alp'] !== null) {
            $query->where('q.is_alp_question', filter_var($filters['is_alp'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (array_key_exists('exercise_question', $filters) && $filters['exercise_question'] !== '' && $filters['exercise_question'] !== null) {
            $query->where('q.exercise_question', filter_var($filters['exercise_question'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($sq) use ($term) {
                $sq->where('q.question', 'like', $term)
                    ->orWhere('q.question_um', 'like', $term)
                    ->orWhere('t.topic_name', 'like', $term)
                    ->orWhere('u.unit_name', 'like', $term)
                    ->orWhere('active_sg.title', 'like', $term)
                    ->orWhere('active_sg.scenario_text', 'like', $term)
                    ->orWhere('active_sg.scenario_text_um', 'like', $term);
            });
        }

        $excludeIds = $this->extractIntList($filters, 'exclude_question_ids');
        if ($excludeIds !== []) {
            $query->whereNotIn('q.id', $excludeIds);
        }

        return $query;
    }

    private function verifyScopedActiveMcqsWithDetails(TeacherClass $class, array $orderedIds): Collection
    {
        $scopedRows = $this->buildFilteredMcqQuery($class, [], true)
            ->whereIn('q.id', $orderedIds)
            ->get()
            ->keyBy('id');

        $missingIds = array_values(array_diff($orderedIds, $scopedRows->keys()->map(fn ($k) => (int) $k)->all()));
        if ($missingIds !== []) {
            $rawQuestions = DB::table('exam_question_tbl')
                ->whereIn('id', $missingIds)
                ->get(['id', 'activate', 'is_mcq'])
                ->keyBy('id');

            foreach ($missingIds as $missingId) {
                $raw = $rawQuestions->get($missingId);
                if (!$raw || (int) $raw->activate !== 1 || (int) $raw->is_mcq !== 1) {
                    $this->resources->abortJson(
                        422,
                        'invalid_test_question',
                        "Question #{$missingId} is missing, inactive, or is not an MCQ."
                    );
                }

                $this->resources->abortJson(
                    403,
                    'resource_out_of_class_scope',
                    "Question #{$missingId} belongs to another curriculum, grade, or subject outside this class."
                );
            }
        }

        return collect($orderedIds)
            ->map(fn (int $id) => $scopedRows->get($id))
            ->filter();
    }

    private function presentSafeMcqRows(TeacherClass $class, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $questionIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $scenarioGroupIds = $rows->pluck('scenario_group_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $directSloIds = $rows->pluck('slo_topic_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $topicIds = $rows->pluck('topic_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $unitIds = $rows->pluck('unit_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();

        $optionsByQuestion = $this->resources->loadSafeOptionsForQuestions($questionIds);
        $scenariosById = $this->loadBuilderScenarioGroups($scenarioGroupIds);
        $boardLinksByQuestion = $this->resources->loadBoardLinksForQuestions($questionIds);
        $sloIndex = $this->resources->loadQuestionSloLookup($class, $directSloIds, $topicIds, $unitIds);

        return $rows->map(fn ($q) => $this->formatQuestionPayload(
            $q,
            $optionsByQuestion[(int) $q->id] ?? [],
            $q->scenario_group_id ? ($scenariosById[(int) $q->scenario_group_id] ?? null) : null,
            $boardLinksByQuestion[(int) $q->id] ?? [],
            $this->resolveQuestionSlos($q, $sloIndex),
            false
        ));
    }

    private function presentTeacherReviewRows(TeacherClass $class, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $questionIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $scenarioGroupIds = $rows->pluck('scenario_group_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $directSloIds = $rows->pluck('slo_topic_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $topicIds = $rows->pluck('topic_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $unitIds = $rows->pluck('unit_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();

        $optionsWithAnswers = $this->loadTeacherReviewOptionsForQuestions($questionIds);
        $scenariosById = $this->loadBuilderScenarioGroups($scenarioGroupIds);
        $boardLinksByQuestion = $this->resources->loadBoardLinksForQuestions($questionIds);
        $sloIndex = $this->resources->loadQuestionSloLookup($class, $directSloIds, $topicIds, $unitIds);

        return $rows->map(fn ($q) => $this->formatQuestionPayload(
            $q,
            $optionsWithAnswers[(int) $q->id] ?? [],
            $q->scenario_group_id ? ($scenariosById[(int) $q->scenario_group_id] ?? null) : null,
            $boardLinksByQuestion[(int) $q->id] ?? [],
            $this->resolveQuestionSlos($q, $sloIndex),
            true
        ));
    }

    private function formatQuestionPayload(
        object $q,
        array $options,
        ?array $scenarioGroup,
        array $boardLinks,
        array $slos,
        bool $includeTeacherAnswers
    ): array {
        if ($scenarioGroup !== null) {
            $scenarioGroup['scenario_image'] = $this->sanitizeMediaUrl($scenarioGroup['scenario_image'] ?? null);
        }

        $base = [
            'id' => (int) $q->id,
            'question' => $q->question,
            'question_um' => $q->question_um,
            'question_lang' => (int) $q->question_lang,
            'question_um_lang' => (int) $q->question_um_lang,
            'marks' => (float) ($q->marks ?? 1),
            'difficulty' => $q->difficulty !== null ? (int) $q->difficulty : null,
            'difficulty_label' => TeacherSubjectResourceService::DIFFICULTY_LABELS[(int) $q->difficulty] ?? null,
            'is_alp_question' => (bool) $q->is_alp_question,
            'exercise_question' => (bool) $q->exercise_question,
            'has_diagram' => (bool) $q->has_diagram,
            'slo_topic_id' => $q->slo_topic_id ? (int) $q->slo_topic_id : null,
            'cognitive_domain' => $q->cognitive_domain_id ? [
                'id' => (int) $q->cognitive_domain_id,
                'name' => $q->cognitive_domain_name,
                'short' => $q->cognitive_domain_short,
            ] : null,
            'presentation_type' => $q->question_presentation_type_id ? [
                'id' => (int) $q->question_presentation_type_id,
                'name' => $q->presentation_type_name,
                'code' => $q->presentation_type_code,
            ] : null,
            'scenario_question_order' => $q->scenario_question_order !== null ? (int) $q->scenario_question_order : null,
            'scenario_group' => $scenarioGroup,
            'unit' => [
                'id' => (int) $q->unit_id,
                'unit_no' => $q->unit_no !== null ? (int) $q->unit_no : null,
                'unit_name' => $q->unit_name,
                'book_id' => (int) $q->book_id,
                'book_name' => $q->book_name,
            ],
            'topic' => [
                'id' => (int) $q->topic_id,
                'topic_name' => $q->topic_name,
            ],
            'slos' => $slos,
            'options' => $options,
            'board_links' => $boardLinks,
        ];

        if ($includeTeacherAnswers) {
            $correctOpt = collect($options)->firstWhere('is_answer', true);
            $base['correct_option_id'] = $correctOpt['id'] ?? null;
            $base['correct_option_key'] = $correctOpt['option_key'] ?? null;
            $base['explanation'] = $q->explanation ?? null;
            $base['explanation_um'] = $q->explanation_um ?? null;
        }

        return $base;
    }

    private function loadBuilderScenarioGroups(array $scenarioGroupIds): array
    {
        if (empty($scenarioGroupIds)) {
            return [];
        }

        return DB::table('question_scenario_groups_tbl')
            ->whereIn('id', $scenarioGroupIds)
            ->where('activate', 1)
            ->select(['id', 'title', 'scenario_text', 'scenario_text_um', 'scenario_image'])
            ->get()
            ->keyBy('id')
            ->map(fn ($sg) => [
                'id' => (int) $sg->id,
                'title' => $sg->title,
                'scenario_text' => $sg->scenario_text,
                'scenario_text_um' => $sg->scenario_text_um,
                'scenario_image' => $this->sanitizeMediaUrl($sg->scenario_image),
            ])
            ->all();
    }

    private function loadTeacherReviewOptionsForQuestions(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $grouped = DB::table('exam_question_options_tbl')
            ->whereIn('question_id', $questionIds)
            ->orderBy('id')
            ->get(['id', 'question_id', 'option', 'option_um', 'option_lang', 'option_um_lang', 'is_answer'])
            ->groupBy('question_id');

        $letters = range('A', 'Z');
        $result = [];
        foreach ($grouped as $qid => $rows) {
            $result[(int) $qid] = $rows->values()->map(function ($row, int $idx) use ($letters) {
                return [
                    'id' => (int) $row->id,
                    'option_key' => $letters[$idx] ?? (string) ($idx + 1),
                    'option' => $row->option,
                    'option_um' => $row->option_um,
                    'option_lang' => (int) $row->option_lang,
                    'option_um_lang' => (int) $row->option_um_lang,
                    'is_answer' => (bool) $row->is_answer,
                ];
            })->all();
        }

        return $result;
    }

    private function resolveQuestionSlos(object $q, array $sloIndex): array
    {
        $resolved = [];
        if ($q->slo_topic_id && isset($sloIndex['byId'][(int) $q->slo_topic_id])) {
            $resolved[(int) $q->slo_topic_id] = $sloIndex['byId'][(int) $q->slo_topic_id];
        }
        foreach ($sloIndex['byTopic'][(int) $q->topic_id] ?? [] as $slo) {
            $resolved[$slo['id']] = $slo;
        }
        if ($resolved === []) {
            foreach ($sloIndex['byUnit'][(int) $q->unit_id] ?? [] as $slo) {
                $resolved[$slo['id']] = $slo;
            }
        }

        return array_values($resolved);
    }

    /**
     * Resolve selectable atomic units matching $filters in All Formats order:
     *   0. Standalone MCQs (no active scenario_group_id)
     *   1. Stimulus groups (complete curriculum-eligible active MCQs per active_scenario_group_id)
     *
     * Any stimulus group intersecting $excludeIds is excluded atomically so partial groups are never added or duplicated.
     */
    private function resolveMatchingSelectionUnits(TeacherClass $class, array $filters, array $excludeIds = [], ?int $seed = null): array
    {
        $excludeLookup = $excludeIds !== [] ? array_flip($excludeIds) : [];
        $filtersWithoutExclude = $filters;
        unset($filtersWithoutExclude['exclude_question_ids']);

        $matchedRows = (clone $this->buildFilteredMcqQuery($class, $filtersWithoutExclude))
            ->select([
                'q.id',
                'active_sg.id as active_scenario_group_id',
                'q.question_presentation_type_id',
                'qpt.code as qpt_code',
            ])
            ->distinct()
            ->orderByRaw('CASE WHEN active_sg.id IS NULL OR q.question_presentation_type_id IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('q.id')
            ->get();

        $standaloneIds = [];
        $matchedGroupIds = [];
        foreach ($matchedRows as $row) {
            $isExplicitStimulus = (
                (int) $row->question_presentation_type_id === 2 ||
                $row->qpt_code === 'stimulus'
            );

            if ($row->active_scenario_group_id && $isExplicitStimulus) {
                $matchedGroupIds[(int) $row->active_scenario_group_id] = true;
            } else {
                $qid = (int) $row->id;
                if (!isset($excludeLookup[$qid])) {
                    $standaloneIds[$qid] = $qid;
                }
            }
        }

        $groupIdsList = array_keys($matchedGroupIds);
        sort($groupIdsList, SORT_NUMERIC);

        $groupMembersMap = [];
        if ($groupIdsList !== []) {
            $allEligibleGroupRows = $this->buildFilteredMcqQuery($class, [])
                ->whereIn('q.scenario_group_id', $groupIdsList)
                ->where(function ($fq) {
                    $fq->where('q.question_presentation_type_id', 2)
                        ->orWhere('qpt.code', 'stimulus');
                })
                ->select(['q.id', 'q.scenario_group_id', 'q.scenario_question_order'])
                ->distinct()
                ->orderBy('q.scenario_group_id')
                ->orderByRaw('COALESCE(q.scenario_question_order, 0) ASC')
                ->orderBy('q.id')
                ->get();

            foreach ($allEligibleGroupRows as $member) {
                $gid = (int) $member->scenario_group_id;
                $groupMembersMap[$gid][] = (int) $member->id;
            }
        }

        $units = collect();
        $standaloneCount = 0;
        $stimulusGroupCount = 0;
        $availableMcqCount = 0;

        foreach ($standaloneIds as $qid) {
            $units->push([
                'unit_key' => 'q:' . $qid,
                'item_type' => 'standalone',
                'bucket' => 0,
                'sort_id' => $qid,
                'question_id' => $qid,
                'scenario_group_id' => null,
                'question_ids' => [$qid],
            ]);
            $standaloneCount++;
            $availableMcqCount++;
        }

        foreach ($groupIdsList as $gid) {
            $memberIds = $groupMembersMap[$gid] ?? [];
            if ($memberIds === []) {
                continue;
            }

            if ($excludeLookup !== []) {
                $hasExcludedMember = false;
                foreach ($memberIds as $mid) {
                    if (isset($excludeLookup[$mid])) {
                        $hasExcludedMember = true;
                        break;
                    }
                }
                if ($hasExcludedMember) {
                    continue;
                }
            }

            // A stimulus group on an examination paper consists of at most 4 selected MCQs.
            // If the bank pool has > 4 active eligible MCQs, select exactly 4 MCQs
            // (using seed if provided or random selection), preserving natural scenario question order.
            if (count($memberIds) > 4) {
                $poolIds = $memberIds;
                if ($seed !== null) {
                    mt_srand($seed + $gid);
                    for ($i = count($poolIds) - 1; $i > 0; $i--) {
                        $j = mt_rand(0, $i);
                        [$poolIds[$i], $poolIds[$j]] = [$poolIds[$j], $poolIds[$i]];
                    }
                    mt_srand();
                } else {
                    for ($i = count($poolIds) - 1; $i > 0; $i--) {
                        $j = random_int(0, $i);
                        [$poolIds[$i], $poolIds[$j]] = [$poolIds[$j], $poolIds[$i]];
                    }
                }
                $selectedMap = array_flip(array_slice($poolIds, 0, 4));
                $memberIds = array_values(array_filter($memberIds, fn ($id) => isset($selectedMap[$id])));
            }

            $units->push([
                'unit_key' => 'g:' . $gid,
                'item_type' => 'stimulus_group',
                'bucket' => 1,
                'sort_id' => $gid,
                'question_id' => null,
                'scenario_group_id' => $gid,
                'question_ids' => $memberIds,
            ]);
            $stimulusGroupCount++;
            $availableMcqCount += count($memberIds);
        }

        return [
            'units' => $units,
            'standalone_count' => $standaloneCount,
            'stimulus_group_count' => $stimulusGroupCount,
            'available_mcq_count' => $availableMcqCount,
        ];
    }

    /**
     * Hydrate a page/selection of atomic units without duplicating queries or exposing answers.
     */
    private function hydrateBankUnits(TeacherClass $class, Collection $units, bool $includeAnswers = false): array
    {
        if ($units->isEmpty()) {
            return [
                'units' => collect(),
                'flat_questions' => collect(),
            ];
        }

        $allQuestionIds = $units->flatMap(fn (array $u) => $u['question_ids'])->unique()->values()->all();
        $groupIds = $units->pluck('scenario_group_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();

        $rowsById = $this->buildFilteredMcqQuery($class, [], $includeAnswers)
            ->whereIn('q.id', $allQuestionIds)
            ->get()
            ->keyBy('id');

        $orderedRows = collect($allQuestionIds)
            ->map(fn (int $id) => $rowsById->get($id))
            ->filter();

        $presentedMap = ($includeAnswers
            ? $this->presentTeacherReviewRows($class, $orderedRows)
            : $this->presentSafeMcqRows($class, $orderedRows)
        )->keyBy('id');

        $rawGroupCounts = $this->loadRawCountsForScenarioGroups($groupIds);

        $hydratedUnits = collect();
        $flatQuestions = collect();

        foreach ($units as $unit) {
            if ($unit['item_type'] === 'standalone') {
                $qPayload = $presentedMap->get((int) $unit['question_id']);
                if (!$qPayload) {
                    continue;
                }
                $hydratedUnits->push([
                    'unit_key' => $unit['unit_key'],
                    'item_type' => 'standalone',
                    'question_id' => (int) $qPayload['id'],
                    'question_count' => 1,
                    'question' => $qPayload,
                ]);
                $flatQuestions->push($qPayload);
                continue;
            }

            $gid = (int) $unit['scenario_group_id'];
            $groupQuestions = collect($unit['question_ids'])
                ->map(fn (int $qid) => $presentedMap->get($qid))
                ->filter()
                ->values();

            if ($groupQuestions->isEmpty()) {
                continue;
            }

            $first = $groupQuestions->first();
            $totalRaw = $rawGroupCounts[$gid] ?? $groupQuestions->count();
            $excludedCount = max(0, $totalRaw - $groupQuestions->count());

            $hydratedUnits->push([
                'unit_key' => $unit['unit_key'],
                'item_type' => 'stimulus_group',
                'scenario_group_id' => $gid,
                'question_count' => $groupQuestions->count(),
                'eligible_question_count' => $groupQuestions->count(),
                'total_group_rows' => $totalRaw,
                'excluded_ineligible_count' => $excludedCount,
                'has_ineligible_members' => $excludedCount > 0,
                'scenario_group' => $first['scenario_group'] ?? null,
                'unit' => $first['unit'] ?? null,
                'topic' => $first['topic'] ?? null,
                'slos' => $first['slos'] ?? [],
                'board_links' => $first['board_links'] ?? [],
                'questions' => $groupQuestions,
            ]);

            foreach ($groupQuestions as $gq) {
                $flatQuestions->push($gq);
            }
        }

        return [
            'units' => $hydratedUnits,
            'flat_questions' => $flatQuestions,
        ];
    }

    private function loadRawCountsForScenarioGroups(array $scenarioGroupIds): array
    {
        if ($scenarioGroupIds === []) {
            return [];
        }

        return DB::table('exam_question_tbl')
            ->whereIn('scenario_group_id', $scenarioGroupIds)
            ->selectRaw('scenario_group_id, COUNT(*) as total_cnt')
            ->groupBy('scenario_group_id')
            ->pluck('total_cnt', 'scenario_group_id')
            ->map(fn ($cnt) => (int) $cnt)
            ->all();
    }

    /**
     * Enforce atomic stimulus group selection at save time:
     * A stimulus group in an exam must consist of exactly 4 selected MCQs (or all eligible if fewer than 4 exist).
     * All submitted questions must belong to the stimulus group, and partial incomplete groups cannot be saved.
     */
    private function assertCompleteStimulusGroupsForSave(TeacherClass $class, Collection $scopedRows): void
    {
        $groupIds = $scopedRows
            ->pluck('active_scenario_group_id')
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($groupIds === []) {
            return;
        }

        $submittedByGroup = $scopedRows
            ->filter(fn ($row) => !empty($row->active_scenario_group_id))
            ->groupBy('active_scenario_group_id');

        $expectedByGroup = $this->buildFilteredMcqQuery($class, [])
            ->whereIn('q.scenario_group_id', $groupIds)
            ->select(['q.id', 'q.scenario_group_id'])
            ->get()
            ->groupBy('scenario_group_id');

        foreach ($groupIds as $gid) {
            $eligibleInGroup = ($expectedByGroup->get($gid) ?? collect())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $submittedInGroup = ($submittedByGroup->get($gid) ?? collect())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $invalidSubmitted = array_values(array_diff($submittedInGroup, $eligibleInGroup));
            if ($invalidSubmitted !== []) {
                $this->resources->abortJson(
                    422,
                    'invalid_stimulus_group_questions',
                    "Stimulus group #{$gid} contains questions that do not belong to it."
                );
            }

            // A scenario on an examination paper must include exactly 4 MCQs (or all eligible if fewer than 4 exist).
            $expectedCount = min(4, count($eligibleInGroup));
            if (count($submittedInGroup) !== $expectedCount) {
                $this->resources->abortJson(
                    422,
                    'incomplete_stimulus_group',
                    "Stimulus group #{$gid} must include exactly {$expectedCount} selected MCQs and cannot be split."
                );
            }
        }
    }

    /**
     * Normalize rows into canonical All Formats order:
     *   1. Standalone MCQs first (preserving caller's standalone relative order)
     *   2. Stimulus groups second (preserving group arrival order, keeping members contiguous by scenario_question_order, id)
     */
    private function orderRowsAllFormats(Collection $rows): Collection
    {
        $standalone = [];
        $groupOrder = [];
        $grouped = [];

        foreach ($rows as $row) {
            $gid = !empty($row->active_scenario_group_id) ? (int) $row->active_scenario_group_id : null;
            if ($gid === null) {
                $standalone[] = $row;
                continue;
            }

            if (!isset($grouped[$gid])) {
                $groupOrder[] = $gid;
                $grouped[$gid] = [];
            }
            $grouped[$gid][] = $row;
        }

        $ordered = collect($standalone);
        foreach ($groupOrder as $gid) {
            $members = collect($grouped[$gid])->sortBy(function ($member) {
                return [
                    (int) ($member->scenario_question_order ?? 0),
                    (int) $member->id,
                ];
            });

            foreach ($members as $m) {
                $ordered->push($m);
            }
        }

        return $ordered->values();
    }

    /**
     * Build structured presentation sections so UI views render standalone MCQs first and each stimulus group once.
     */
    private function buildPresentationSections(Collection $presentedQuestions): array
    {
        $standalone = [];
        $groupOrder = [];
        $groupedQuestions = [];
        $groupMeta = [];

        foreach ($presentedQuestions as $q) {
            $scenario = $q['scenario_group'] ?? null;
            $gid = is_array($scenario) && !empty($scenario['id']) ? (int) $scenario['id'] : null;

            if ($gid === null) {
                $standalone[] = $q;
                continue;
            }

            if (!isset($groupedQuestions[$gid])) {
                $groupOrder[] = $gid;
                $groupedQuestions[$gid] = [];
                $groupMeta[$gid] = $scenario;
            }
            $groupedQuestions[$gid][] = $q;
        }

        $stimulusGroups = [];
        foreach ($groupOrder as $gid) {
            $members = $groupedQuestions[$gid];
            $stimulusGroups[] = [
                'scenario_group_id' => $gid,
                'scenario_group' => $groupMeta[$gid],
                'question_count' => count($members),
                'questions' => array_values($members),
            ];
        }

        return [
            'standalone_questions' => array_values($standalone),
            'stimulus_groups' => $stimulusGroups,
        ];
    }

    /**
     * Randomized atomic unit subset selector that never splits stimulus groups.
     * Prefers an exact sum matching $targetCount; otherwise picks the closest non-empty whole-unit sum
     * without splitting any stimulus group.
     */
    private function pickAtomicUnits(array $candidateUnits, int $targetCount, ?int $seed = null): array
    {
        $pool = array_values($candidateUnits);
        $total = count($pool);

        if ($seed !== null) {
            mt_srand($seed);
            for ($i = $total - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
            }
            mt_srand();
        } else {
            for ($i = $total - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
            }
        }

        $parentChoice = array_fill(0, $targetCount + 1, null);
        $parentChoice[0] = ['prev' => -1, 'unit_idx' => -1];
        $smallestLargerIdx = null;
        $smallestLargerSize = null;

        foreach ($pool as $idx => $unit) {
            $size = count($unit['question_ids']);
            if ($size <= 0) {
                continue;
            }

            if ($size > $targetCount) {
                if ($smallestLargerSize === null || $size < $smallestLargerSize) {
                    $smallestLargerSize = $size;
                    $smallestLargerIdx = $idx;
                }
                continue;
            }

            for ($sum = $targetCount; $sum >= $size; $sum--) {
                if ($parentChoice[$sum] === null && $parentChoice[$sum - $size] !== null) {
                    $parentChoice[$sum] = ['prev' => $sum - $size, 'unit_idx' => $idx];
                }
            }

            if ($parentChoice[$targetCount] !== null) {
                break;
            }
        }

        $chosenSum = null;
        for ($sum = $targetCount; $sum >= 1; $sum--) {
            if ($parentChoice[$sum] !== null) {
                $chosenSum = $sum;
                break;
            }
        }

        if ($chosenSum !== null) {
            $selected = [];
            $cursor = $chosenSum;
            while ($cursor > 0 && $parentChoice[$cursor] !== null) {
                $entry = $parentChoice[$cursor];
                $unitIdx = $entry['unit_idx'];
                if ($unitIdx >= 0) {
                    $selected[] = $pool[$unitIdx];
                }
                $cursor = $entry['prev'];
            }

            return [
                'units' => array_reverse($selected),
                'total_questions' => $chosenSum,
            ];
        }

        if ($smallestLargerIdx !== null) {
            return [
                'units' => [$pool[$smallestLargerIdx]],
                'total_questions' => $smallestLargerSize,
            ];
        }

        return [
            'units' => [],
            'total_questions' => 0,
        ];
    }

    private function extractIntList(array $source, string $arrayKey, ?string $singleKey = null): array
    {
        $values = [];
        if (isset($source[$arrayKey]) && is_array($source[$arrayKey])) {
            $values = $source[$arrayKey];
        } elseif (isset($source[$arrayKey]) && is_string($source[$arrayKey]) && $source[$arrayKey] !== '') {
            $values = explode(',', $source[$arrayKey]);
        }

        if ($singleKey !== null && isset($source[$singleKey]) && $source[$singleKey] !== '' && $source[$singleKey] !== null) {
            $values[] = $source[$singleKey];
        }

        return collect($values)
            ->map(fn ($v) => is_numeric($v) ? (int) $v : 0)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function sanitizeSelectionCriteria(mixed $criteria): ?array
    {
        if (!is_array($criteria) || $criteria === []) {
            return null;
        }

        $clean = [];
        foreach (self::CRITERIA_WHITELIST as $key) {
            if (!array_key_exists($key, $criteria)) {
                continue;
            }
            $val = $criteria[$key];
            if ($val === null || $val === '' || (is_array($val) && $val === [])) {
                continue;
            }
            $clean[$key] = $val;
        }

        return $clean === [] ? null : $clean;
    }

    private function sanitizeMediaUrl(?string $value): ?string
    {
        $trimmed = $this->nullableTrim($value);
        if ($trimmed === null) {
            return null;
        }

        $lower = Str::lower($trimmed);
        if (Str::startsWith($lower, ['http://', 'https://'])) {
            return $trimmed;
        }

        // Block javascript:, data:, vbscript:, or raw private bucket keys unless valid relative web path
        if (Str::contains($lower, ['javascript:', 'data:', 'vbscript:'])) {
            return null;
        }

        return Str::startsWith($trimmed, '/') ? $trimmed : null;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
