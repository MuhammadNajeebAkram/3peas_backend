<?php

namespace App\Http\Services\Freemium;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FreemiumAccessRuleEvaluator
{
    public const SPECIFICITY_QUESTION = 100000;
    public const SPECIFICITY_PAPER    = 50000;
    public const SPECIFICITY_TOPIC    = 10000;
    public const SPECIFICITY_CHAPTER  = 1000;
    public const SPECIFICITY_BOOK     = 100;
    public const SPECIFICITY_SUBJECT  = 10;
    public const SPECIFICITY_CLASS    = 2;
    public const SPECIFICITY_BOARD    = 1;
    public const SPECIFICITY_GLOBAL   = 0;

    /**
     * Specificity levels in descending order of precedence.
     */
    protected const SPECIFICITY_ORDER = [
        self::SPECIFICITY_QUESTION,
        self::SPECIFICITY_PAPER,
        self::SPECIFICITY_TOPIC,
        self::SPECIFICITY_CHAPTER,
        self::SPECIFICITY_BOOK,
        self::SPECIFICITY_SUBJECT,
        self::SPECIFICITY_CLASS,
        self::SPECIFICITY_BOARD,
        self::SPECIFICITY_GLOBAL,
    ];

    /**
     * Evaluate access for a trusted student against target academic hierarchy.
     *
     * @param WebUser $student Trusted authenticated student
     * @param array $targetContext Associative array of target hierarchy IDs
     * @param string $permission Dimension to evaluate (e.g. 'perm_mcqs', 'perm_past_papers')
     * @param Carbon|null $now Time authority for interval evaluations (UTC)
     */
    public function evaluate(
        WebUser $student,
        array $targetContext,
        string $permission = 'perm_mcqs',
        ?Carbon $now = null
    ): FreemiumEvaluationResult {
        $now = $now ? $now->copy()->setTimezone('UTC') : Carbon::now('UTC');

        // 1. Resolve participating campaigns based on stacking policy & student eligibility
        $resolvedCampaigns = $this->resolveParticipatingCampaigns($student, $now);
        $primaryCampaign = $resolvedCampaigns['primary'];
        /** @var FreemiumCampaign[] $participatingCampaigns */
        $participatingCampaigns = $resolvedCampaigns['participating'];

        // 2. If campaigns participate, evaluate rules by descending specificity
        if (!empty($participatingCampaigns)) {
            $campaignIds = array_map(fn($c) => $c->id, $participatingCampaigns);
            $campaignMap = [];
            foreach ($participatingCampaigns as $c) {
                $campaignMap[$c->id] = $c;
            }

            // Load active rules for participating campaigns (filter out orphaned rules)
            $rules = FreemiumAccessRule::whereIn('campaign_id', $campaignIds)
                ->where('target_status', 'active')
                ->get();

            foreach (self::SPECIFICITY_ORDER as $specificity) {
                $candidateRules = $this->filterRulesAtSpecificity($rules, $specificity, $targetContext);

                if ($candidateRules->isEmpty()) {
                    continue;
                }

                // Sort candidate rules at this specificity level:
                // 1. Lower campaign priority integer wins
                // 2. Lower rule priority integer wins
                $sorted = $candidateRules->sort(function (FreemiumAccessRule $a, FreemiumAccessRule $b) use ($campaignMap) {
                    $campaignA = $campaignMap[$a->campaign_id] ?? null;
                    $campaignB = $campaignMap[$b->campaign_id] ?? null;

                    $cPriorityA = $campaignA?->priority ?? 1000;
                    $cPriorityB = $campaignB?->priority ?? 1000;

                    if ($cPriorityA !== $cPriorityB) {
                        return $cPriorityA <=> $cPriorityB;
                    }

                    return $a->rule_priority <=> $b->rule_priority;
                })->values();

                // Group top-priority tied rules
                $first = $sorted->first();
                $firstCampaignPriority = $campaignMap[$first->campaign_id]?->priority ?? 1000;
                $firstRulePriority = $first->rule_priority;

                $tiedRules = $sorted->filter(function (FreemiumAccessRule $r) use ($campaignMap, $firstCampaignPriority, $firstRulePriority) {
                    $cp = $campaignMap[$r->campaign_id]?->priority ?? 1000;
                    return $cp === $firstCampaignPriority && $r->rule_priority === $firstRulePriority;
                });

                // Precedence resolution among tied rules: DENY wins on tie!
                $hasDeny = $tiedRules->contains(fn(FreemiumAccessRule $r) => $r->{$permission} === 'deny');
                if ($hasDeny) {
                    $winningRule = $tiedRules->first(fn(FreemiumAccessRule $r) => $r->{$permission} === 'deny');
                    return new FreemiumEvaluationResult(
                        FreemiumEvaluationResult::DECISION_DENY,
                        $winningRule,
                        $participatingCampaigns,
                        $primaryCampaign,
                        $specificity,
                        FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE,
                        "Explicit DENY resolved at specificity {$specificity} (campaign priority {$firstCampaignPriority}, rule priority {$firstRulePriority}, tie-break DENY)",
                        $targetContext
                    );
                }

                $hasAllow = $tiedRules->contains(fn(FreemiumAccessRule $r) => $r->{$permission} === 'allow');
                if ($hasAllow) {
                    $winningRule = $tiedRules->first(fn(FreemiumAccessRule $r) => $r->{$permission} === 'allow');
                    return new FreemiumEvaluationResult(
                        FreemiumEvaluationResult::DECISION_ALLOW,
                        $winningRule,
                        $participatingCampaigns,
                        $primaryCampaign,
                        $specificity,
                        FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE,
                        "Explicit ALLOW resolved at specificity {$specificity} (campaign priority {$firstCampaignPriority}, rule priority {$firstRulePriority})",
                        $targetContext
                    );
                }

                // If all tied top rules are 'inherit', move evaluation to the next lower specificity level
            }
        }

        // 3. Fallback: Legacy free flags (Tier 3 fallback only after explicit Freemium rules evaluated)
        if ($this->checkLegacyFreeFallback($targetContext)) {
            return new FreemiumEvaluationResult(
                FreemiumEvaluationResult::DECISION_ALLOW,
                null,
                $participatingCampaigns,
                $primaryCampaign,
                -1,
                FreemiumEvaluationResult::SOURCE_LEGACY_FALLBACK,
                'Allowed via legacy free flag fallback (program_subjects.is_free or offered_classes.is_free)',
                $targetContext
            );
        }

        // 4. Default to DENY when no applicable rule or legacy allowance exists
        return new FreemiumEvaluationResult(
            FreemiumEvaluationResult::DECISION_DENY,
            null,
            $participatingCampaigns,
            $primaryCampaign,
            -1,
            FreemiumEvaluationResult::SOURCE_DEFAULT_DENY,
            'Default deny: no matching freemium rule or legacy free entitlement',
            $targetContext
        );
    }

    /**
     * Resolve participating campaigns according to stacking and priority policy.
     *
     * Policy:
     * - Respect half-open interval [start_at, end_at)
     * - Select primary non-stackable campaign with lowest priority integer, if one exists.
     * - Otherwise select primary stackable campaign with lowest priority integer.
     * - Other stackable campaigns participate only when their priority is <= primary campaign's priority.
     *
     * @return array{primary: ?FreemiumCampaign, participating: FreemiumCampaign[]}
     */
    public function resolveParticipatingCampaigns(WebUser $student, Carbon $now): array
    {
        // 1. Fetch currently active campaigns in half-open interval [start_at, end_at)
        $nowTs = $now->getTimestamp();
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $fromUnix = $isSqlite ? "datetime(?, 'unixepoch')" : 'FROM_UNIXTIME(?)';

        $activeCampaigns = FreemiumCampaign::active()
            ->whereRaw("start_at <= {$fromUnix}", [$nowTs])
            ->where(function ($sub) use ($nowTs, $fromUnix) {
                $sub->whereNull('end_at')->orWhereRaw("end_at > {$fromUnix}", [$nowTs]);
            })
            ->orderBy('priority', 'asc')
            ->get();

        if ($activeCampaigns->isEmpty()) {
            return ['primary' => null, 'participating' => []];
        }

        // 2. Filter campaigns applicable to this student
        $eligibleCampaigns = $activeCampaigns->filter(function (FreemiumCampaign $campaign) use ($student, $now) {
            return $this->isStudentEligibleForCampaign($campaign, $student, $now);
        });

        if ($eligibleCampaigns->isEmpty()) {
            return ['primary' => null, 'participating' => []];
        }

        // 3. Check for non-stackable campaigns
        $nonStackable = $eligibleCampaigns->where('is_stackable', false)->sortBy('priority')->values();
        if ($nonStackable->isNotEmpty()) {
            $primary = $nonStackable->first();
            // A non-stackable campaign isolates evaluation; no other campaign participates
            return [
                'primary'       => $primary,
                'participating' => [$primary],
            ];
        }

        // 4. Stackable campaigns
        $stackable = $eligibleCampaigns->where('is_stackable', true)->sortBy('priority')->values();
        if ($stackable->isEmpty()) {
            return ['primary' => null, 'participating' => []];
        }

        $primary = $stackable->first();
        $participating = $stackable->filter(function (FreemiumCampaign $campaign) use ($primary) {
            return $campaign->priority <= $primary->priority;
        })->values()->all();

        return [
            'primary'       => $primary,
            'participating' => $participating,
        ];
    }

    /**
     * Check if student satisfies campaign eligibility criteria and grants.
     */
    public function isStudentEligibleForCampaign(FreemiumCampaign $campaign, WebUser $student, Carbon $now): bool
    {
        $criteria = $campaign->eligibility_criteria ?? [];

        // If campaign requires voucher/direct grant, verify student has an active non-expired grant
        $requiresGrant = $criteria['require_grant'] ?? false;
        if ($requiresGrant) {
            $nowTs = $now->getTimestamp();
            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $fromUnix = $isSqlite ? "datetime(?, 'unixepoch')" : 'FROM_UNIXTIME(?)';

            $hasGrant = FreemiumStudentGrant::where('campaign_id', $campaign->id)
                ->where('user_id', $student->id)
                ->where('status', 'active')
                ->whereRaw("granted_at <= {$fromUnix}", [$nowTs])
                ->where(function ($q) use ($nowTs, $fromUnix) {
                    $q->whereNull('expires_at')->orWhereRaw("expires_at > {$fromUnix}", [$nowTs]);
                })
                ->exists();

            if (!$hasGrant) {
                return false;
            }
        }

        // Check board filter if present
        if (!empty($criteria['curriculum_board_id'])) {
            $studentBoardId = $student->curriculum_board_id ?? null;
            if ($studentBoardId && $studentBoardId != $criteria['curriculum_board_id']) {
                return false;
            }
        }

        // Check class filter if present
        if (!empty($criteria['class_id'])) {
            $studentClassId = $student->class_id ?? null;
            if ($studentClassId && $studentClassId != $criteria['class_id']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter rules matching the specific specificity level.
     * Enforces strict scope-to-specificity mapping: a rule only matches if its declared target_scope
     * is valid for the current specificity level AND the relevant target ID matches.
     */
    protected function filterRulesAtSpecificity(Collection $rules, int $specificity, array $targetContext): Collection
    {
        return $rules->filter(function (FreemiumAccessRule $rule) use ($specificity, $targetContext) {
            $scope = $rule->target_scope;

            return match ($specificity) {
                self::SPECIFICITY_QUESTION => $scope === 'question'
                    && !empty($targetContext['question_id'])
                    && $rule->question_id !== null
                    && (int) $rule->question_id === (int) $targetContext['question_id'],

                self::SPECIFICITY_PAPER => in_array($scope, ['model_paper', 'past_paper', 'paper'], true)
                    && (
                        (!empty($targetContext['model_paper_id'])
                            && $rule->model_paper_id !== null
                            && (int) $rule->model_paper_id === (int) $targetContext['model_paper_id'])
                        || (!empty($targetContext['past_paper_id'])
                            && $rule->past_paper_id !== null
                            && (int) $rule->past_paper_id === (int) $targetContext['past_paper_id'])
                    ),

                self::SPECIFICITY_TOPIC => $scope === 'topic'
                    && !empty($targetContext['topic_id'])
                    && $rule->topic_id !== null
                    && (int) $rule->topic_id === (int) $targetContext['topic_id'],

                self::SPECIFICITY_CHAPTER => $scope === 'chapter'
                    && !empty($targetContext['chapter_id'])
                    && $rule->chapter_id !== null
                    && (int) $rule->chapter_id === (int) $targetContext['chapter_id'],

                self::SPECIFICITY_BOOK => $scope === 'book'
                    && !empty($targetContext['book_id'])
                    && $rule->book_id !== null
                    && (int) $rule->book_id === (int) $targetContext['book_id'],

                self::SPECIFICITY_SUBJECT => $scope === 'subject'
                    && !empty($targetContext['subject_id'])
                    && $rule->subject_id !== null
                    && (int) $rule->subject_id === (int) $targetContext['subject_id'],

                self::SPECIFICITY_CLASS => $scope === 'class'
                    && !empty($targetContext['class_id'])
                    && $rule->class_id !== null
                    && (int) $rule->class_id === (int) $targetContext['class_id'],

                self::SPECIFICITY_BOARD => in_array($scope, ['curriculum_board', 'board'], true)
                    && !empty($targetContext['curriculum_board_id'])
                    && $rule->curriculum_board_id !== null
                    && (int) $rule->curriculum_board_id === (int) $targetContext['curriculum_board_id'],

                self::SPECIFICITY_GLOBAL => $scope === 'global',

                default => false,
            };
        });
    }

    /**
     * Check legacy fallback free flags on program_subjects and offered_classes.
     */
    protected function checkLegacyFreeFallback(array $targetContext): bool
    {
        // 1. Check subject level on program_subjects
        if (!empty($targetContext['subject_id'])) {
            $query = DB::table('program_subjects')
                ->where('subject_id', $targetContext['subject_id'])
                ->where('is_free', 1)
                ->where('is_active', 1);

            if (!empty($targetContext['offered_program_id'])) {
                $query->where('offered_program_id', $targetContext['offered_program_id']);
            }

            if ($query->exists()) {
                return true;
            }
        }

        // 2. Check class level on offered_classes
        if (!empty($targetContext['class_id'])) {
            $query = DB::table('offered_classes')
                ->where('class_id', $targetContext['class_id'])
                ->where('is_free', 1)
                ->where('is_active', 1);

            if (!empty($targetContext['curriculum_board_id'])) {
                $query->where('curriculum_board_id', $targetContext['curriculum_board_id']);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
