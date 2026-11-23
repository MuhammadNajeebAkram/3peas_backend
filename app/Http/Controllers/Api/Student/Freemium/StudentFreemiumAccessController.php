<?php

namespace App\Http\Controllers\Api\Student\Freemium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\Freemium\EvaluateAccessRequest;
use App\Http\Requests\Student\Freemium\FreeResourcesRequest;
use App\Http\Services\Freemium\FreemiumAccessRuleEvaluator;
use App\Http\Services\Freemium\FreemiumEvaluationResult;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Http\Services\StudentClassAccessService;
use App\Models\Book;
use App\Models\BookUnit;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\OfferedProgram;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student Freemium Access Controller.
 *
 * Exposes authenticated endpoints for:
 * - Entitlement discovery across Paid and Freemium tiers.
 * - Resource preflight access evaluation.
 * - Free catalogue resource discovery with pagination.
 */
class StudentFreemiumAccessController extends Controller
{
    public function __construct(
        protected UnifiedEntitlementResolver $resolver,
        protected StudentClassAccessService $paidAccessService,
        protected FreemiumAccessRuleEvaluator $ruleEvaluator
    ) {
    }

    /**
     * Discover current entitlement status for authenticated student.
     */
    public function entitlements(Request $request): JsonResponse
    {
        $student = $request->user();

        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
            ], 403);
        }

        // Active paid subscriptions
        $activeProgramIds = $this->paidAccessService->getActiveProgramIdsForStudent((int) $student->id);
        $programs = OfferedProgram::whereIn('id', $activeProgramIds)->where('is_active', true)->get();
        $paidSubscriptions = $programs->map(fn($p) => [
            'program_id'    => (int) $p->id,
            'program_title' => $p->title,
            'program_slug'  => $p->slug,
        ])->values()->all();

        // Active Freemium campaign
        $participating = $this->ruleEvaluator->resolveParticipatingCampaigns($student, Carbon::now('UTC'));
        $primary = $participating['primary'];
        $freemiumAccess = [
            'has_active_campaign'   => $primary !== null,
            'campaign_display_name' => $primary?->name,
            'valid_until'           => $primary?->end_at?->toIso8601String(),
        ];

        return response()->json([
            'success' => 1,
            'data'    => [
                'student' => [
                    'id'   => (int) $student->id,
                    'name' => $student->name,
                ],
                'active_paid_subscriptions' => $paidSubscriptions,
                'freemium_access'           => $freemiumAccess,
                'academic_scopes'           => [
                    'covered_program_ids' => $activeProgramIds,
                ],
            ],
        ]);
    }

    /**
     * Preflight access check for a requested target context.
     */
    public function evaluateAccess(EvaluateAccessRequest $request): JsonResponse
    {
        $student = $request->user();

        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
            ], 403);
        }

        $context = array_filter($request->only([
            'offered_program_id',
            'curriculum_board_id',
            'class_id',
            'subject_id',
            'book_id',
            'chapter_id',
            'topic_id',
            'question_id',
            'model_paper_id',
            'past_paper_id',
        ]), fn($v) => $v !== null && $v !== '');

        $dimension = (string) $request->input('dimension', 'perm_mcqs');
        $examType  = $request->input('exam_type');

        $decision = $this->resolver->resolve($student, $context, $dimension, $examType);

        return response()->json([
            'success' => 1,
            'data'    => [
                'granted'                   => $decision->isGranted(),
                'tier'                      => $decision->getProvider(),
                'dimension'                 => $decision->getDimension(),
                'action'                    => $decision->isGranted() ? 'allow' : 'deny',
                'requires_quota_evaluation' => $decision->requiresQuotaEvaluation(),
                'exam_type'                 => $decision->getExamType(),
                'upgrade_required'          => $decision->isDenied(),
                'subscription_url'          => $decision->getUpgradeProgramSlug()
                    ? "/lms/subscribe/{$decision->getUpgradeProgramSlug()}"
                    : null,
                'message'                   => $decision->isGranted()
                    ? 'Access granted.'
                    : $decision->getDenialReason(),
            ],
        ]);
    }

    /**
     * Discover academic catalogue resources accessible to student under active Freemium campaigns.
     *
     * Campaign-scoped discovery:
     * - Only returns resources genuinely authorized by active Freemium campaigns.
     * - Excludes paid-only subscriptions and legacy free flags.
     * - For chapter-level rules, preserves exact authorized unit scope without unlocking full book.
     * - Paginates strictly over the campaign-authorized resource set.
     */
    public function freeResources(FreeResourcesRequest $request): JsonResponse
    {
        $student = $request->user();

        if (!$student || $student->role !== 'student' || $student->status !== 'active') {
            return response()->json([
                'success' => 0,
                'message' => 'Active student account required.',
            ], 403);
        }

        $classId   = $request->input('class_id') ? (int) $request->input('class_id') : null;
        $boardId   = $request->input('curriculum_board_id') ? (int) $request->input('curriculum_board_id') : null;
        $subjectId = $request->input('subject_id') ? (int) $request->input('subject_id') : null;
        $dimension = (string) $request->input('dimension', 'perm_mcqs');
        $perPage   = min(max((int) $request->input('per_page', 15), 1), 50);
        $page      = max((int) $request->input('page', 1), 1);
        $now       = Carbon::now('UTC');

        // 1. Resolve participating active campaigns for this student
        $participating = $this->ruleEvaluator->resolveParticipatingCampaigns($student, $now);
        /** @var FreemiumCampaign[] $participatingCampaigns */
        $participatingCampaigns = $participating['participating'] ?? [];

        if (empty($participatingCampaigns)) {
            return response()->json([
                'success' => 1,
                'data'    => [],
                'meta'    => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => 0,
                    'last_page'    => 1,
                ],
            ]);
        }

        $campaignIds = array_map(fn($c) => $c->id, $participatingCampaigns);
        $campaignMap = collect($participatingCampaigns)->keyBy('id');

        // 2. Load active rules for participating campaigns
        $rules = FreemiumAccessRule::whereIn('campaign_id', $campaignIds)
            ->where('target_status', 'active')
            ->get();

        if ($rules->isEmpty()) {
            return response()->json([
                'success' => 1,
                'data'    => [],
                'meta'    => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => 0,
                    'last_page'    => 1,
                ],
            ]);
        }

        // 3. Identify candidate targeted entities from rules
        $targetedBookIds = [];
        $targetedSubjectIds = [];
        $targetedClassIds = [];
        $targetedBoardIds = [];
        $hasGlobal = false;

        foreach ($rules as $r) {
            if ($r->target_scope === 'global') {
                $hasGlobal = true;
            } elseif ($r->target_scope === 'curriculum_board' && $r->curriculum_board_id) {
                $targetedBoardIds[] = (int) $r->curriculum_board_id;
            } elseif ($r->target_scope === 'class' && $r->class_id) {
                $targetedClassIds[] = (int) $r->class_id;
            } elseif ($r->target_scope === 'subject' && $r->subject_id) {
                $targetedSubjectIds[] = (int) $r->subject_id;
            } elseif ($r->target_scope === 'book' && $r->book_id) {
                $targetedBookIds[] = (int) $r->book_id;
            } elseif ($r->target_scope === 'chapter') {
                if ($r->book_id) {
                    $targetedBookIds[] = (int) $r->book_id;
                } elseif ($r->chapter_id) {
                    $u = BookUnit::find($r->chapter_id);
                    if ($u && $u->book_id) {
                        $targetedBookIds[] = (int) $u->book_id;
                    }
                }
            }
        }

        // 4. Query candidate books matching rule targets and request filters
        $bookQuery = Book::query()->where('activate', 1)
            ->with([
                'subject:id,subject_name',
                'userClass:id,class_name',
                'curriculmBoard:id,name',
                'units:id,book_id,unit_name,unit_no'
            ])
            ->orderBy('id', 'asc');

        if ($classId) {
            $bookQuery->where('class_id', $classId);
        }
        if ($boardId) {
            $bookQuery->where('curriculum_board_id', $boardId);
        }
        if ($subjectId) {
            $bookQuery->where('subject_id', $subjectId);
        }

        if (!$hasGlobal) {
            $bookQuery->where(function ($q) use ($targetedBookIds, $targetedSubjectIds, $targetedClassIds, $targetedBoardIds) {
                $hasAny = false;
                if (!empty($targetedBookIds)) {
                    $q->orWhereIn('id', array_unique($targetedBookIds));
                    $hasAny = true;
                }
                if (!empty($targetedSubjectIds)) {
                    $q->orWhereIn('subject_id', array_unique($targetedSubjectIds));
                    $hasAny = true;
                }
                if (!empty($targetedClassIds)) {
                    $q->orWhereIn('class_id', array_unique($targetedClassIds));
                    $hasAny = true;
                }
                if (!empty($targetedBoardIds)) {
                    $q->orWhereIn('curriculum_board_id', array_unique($targetedBoardIds));
                    $hasAny = true;
                }
                if (!$hasAny) {
                    $q->whereRaw('0 = 1');
                }
            });
        }

        $candidateBooks = $bookQuery->get();

        // 5. Evaluate each candidate book against active Freemium campaign rules
        $authorizedResources = [];

        foreach ($candidateBooks as $book) {
            // Check book-level (or broader) authorization
            $bookContext = [
                'book_id'             => (int) $book->id,
                'subject_id'          => (int) $book->subject_id,
                'class_id'            => (int) $book->class_id,
                'curriculum_board_id' => (int) $book->curriculum_board_id,
            ];

            $bookResult = $this->ruleEvaluator->evaluate($student, $bookContext, $dimension, $now);

            if ($bookResult->isAllowed() && $bookResult->getSource() === FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE) {
                $winningRule = $bookResult->getWinningRule();
                $campaign = ($winningRule ? ($campaignMap[$winningRule->campaign_id] ?? null) : null)
                    ?? $bookResult->getPrimaryCampaign();

                $authorizedUnitList = $book->units->map(fn($u) => [
                    'unit_id'   => (int) $u->id,
                    'unit_name' => $u->unit_name,
                    'unit_no'   => $u->unit_no ? (int) $u->unit_no : null,
                ])->values()->all();

                $authorizedResources[] = [
                    'book_id'               => (int) $book->id,
                    'book_name'             => $book->book_name,
                    'class_id'              => (int) $book->class_id,
                    'class_name'            => $book->userClass?->class_name,
                    'curriculum_board_id'   => (int) $book->curriculum_board_id,
                    'curriculum_board_name' => $book->curriculmBoard?->name,
                    'subject_id'            => (int) $book->subject_id,
                    'subject_name'          => $book->subject?->subject_name,
                    'is_accessible'         => true,
                    'access_tier'           => 'freemium',
                    'target_scope'          => $winningRule?->target_scope ?? 'book',
                    'campaign_id'           => $campaign?->id,
                    'campaign_name'         => $campaign?->name,
                    'rule_id'               => $winningRule?->id,
                    'authorized_unit_ids'   => array_column($authorizedUnitList, 'unit_id'),
                    'authorized_units'      => $authorizedUnitList,
                    'upgrade_required'      => false,
                    'subscription_url'      => null,
                ];
                continue;
            }

            // If not authorized at book level, evaluate individual chapters for chapter-level authorization
            $authorizedUnitsForBook = [];
            $unitWinningRule = null;
            $unitWinningCampaign = null;

            foreach ($book->units as $unit) {
                $unitContext = [
                    'book_id'             => (int) $book->id,
                    'chapter_id'          => (int) $unit->id,
                    'subject_id'          => (int) $book->subject_id,
                    'class_id'            => (int) $book->class_id,
                    'curriculum_board_id' => (int) $book->curriculum_board_id,
                ];

                $unitResult = $this->ruleEvaluator->evaluate($student, $unitContext, $dimension, $now);

                if ($unitResult->isAllowed() && $unitResult->getSource() === FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE) {
                    $authorizedUnitsForBook[] = [
                        'unit_id'   => (int) $unit->id,
                        'unit_name' => $unit->unit_name,
                        'unit_no'   => $unit->unit_no ? (int) $unit->unit_no : null,
                    ];
                    if (!$unitWinningRule) {
                        $unitWinningRule = $unitResult->getWinningRule();
                        $unitWinningCampaign = ($unitWinningRule ? ($campaignMap[$unitWinningRule->campaign_id] ?? null) : null)
                            ?? $unitResult->getPrimaryCampaign();
                    }
                }
            }

            if (!empty($authorizedUnitsForBook)) {
                $authorizedResources[] = [
                    'book_id'               => (int) $book->id,
                    'book_name'             => $book->book_name,
                    'class_id'              => (int) $book->class_id,
                    'class_name'            => $book->userClass?->class_name,
                    'curriculum_board_id'   => (int) $book->curriculum_board_id,
                    'curriculum_board_name' => $book->curriculmBoard?->name,
                    'subject_id'            => (int) $book->subject_id,
                    'subject_name'          => $book->subject?->subject_name,
                    'is_accessible'         => true,
                    'access_tier'           => 'freemium',
                    'target_scope'          => 'chapter',
                    'campaign_id'           => $unitWinningCampaign?->id,
                    'campaign_name'         => $unitWinningCampaign?->name,
                    'rule_id'               => $unitWinningRule?->id,
                    'authorized_unit_ids'   => array_column($authorizedUnitsForBook, 'unit_id'),
                    'authorized_units'      => $authorizedUnitsForBook,
                    'upgrade_required'      => false,
                    'subscription_url'      => null,
                ];
            }
        }

        // 6. Paginate strictly over the campaign-authorized resource set
        $total    = count($authorizedResources);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset   = ($page - 1) * $perPage;
        $items    = array_slice($authorizedResources, $offset, $perPage);

        return response()->json([
            'success' => 1,
            'data'    => $items,
            'meta'    => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => $lastPage,
            ],
        ]);
    }
}
