<?php

namespace App\Http\Services\Freemium;

use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Http\Services\StudentClassAccessService;
use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\ExamQuestion;
use App\Models\OfferedProgram;
use App\Models\ProgramSubject;
use App\Models\WebUser;
use Carbon\Carbon;

/**
 * Unified Entitlement Resolver Service.
 *
 * Implements the authoritative 4-tier decision hierarchy for student academic access:
 *   - Tier 1: Valid Paid Subscription (covers target scope via StudentClassAccessService / UserSubscription)
 *   - Tier 2: Explicit Freemium Rules (evaluated via FreemiumAccessRuleEvaluator)
 *   - Tier 3: Legacy Free Fallback (program_subjects.is_free or offered_classes.is_free)
 *   - Tier 4: Default Deny (fails closed, returns upgrade guidance)
 */
class UnifiedEntitlementResolver
{
    public function __construct(
        protected StudentClassAccessService $paidAccessService,
        protected FreemiumAccessRuleEvaluator $ruleEvaluator
    ) {
    }

    /**
     * Resolve unified access decision for a student against a requested target context and permission dimension.
     *
     * @param WebUser $student Authenticated student
     * @param array $targetContext Academic target hierarchy (subject_id, book_id, chapter_id, etc.)
     * @param string $dimension Permission dimension ('perm_mcqs', 'perm_view', etc.)
     * @param string|null $examType Exam type if assessment is requested ('practice', 'formal_test', 'mock')
     * @param Carbon|null $now Reference timestamp (defaults to now)
     * @return UnifiedAccessDecision Authoritative immutable access decision
     */
    public function resolve(
        WebUser $student,
        array $targetContext,
        string $dimension = 'perm_mcqs',
        ?string $examType = null,
        ?Carbon $now = null
    ): UnifiedAccessDecision {
        $now = $now ?? Carbon::now('UTC');

        // Step 0: Student account and role verification
        if ($student->role !== 'student' || $student->status !== 'active') {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'Active student account required.',
                diagnostic: 'Student account is inactive or not assigned the student role.',
                upgradeSlug: null
            );
        }

        // Multi-question handling: When multiple question IDs are requested, each question must be individually authorized.
        if (!empty($targetContext['question_ids']) && is_array($targetContext['question_ids']) && count($targetContext['question_ids']) > 1) {
            return $this->resolveMultiQuestion(
                $student,
                $targetContext,
                $dimension,
                $examType,
                $now
            );
        }

        // Multi-topic handling: When multiple topic IDs are requested, each topic must be individually authorized.
        if (!empty($targetContext['topic_ids']) && is_array($targetContext['topic_ids']) && count($targetContext['topic_ids']) > 1) {
            return $this->resolveMultiTopic(
                $student,
                $targetContext,
                $dimension,
                $examType,
                $now
            );
        }

        // Multi-unit handling: When multiple unit IDs are requested, each unit must be individually authorized.
        // This prevents unauthorized units from piggybacking on an authorized unit or falling back improperly.
        if (!empty($targetContext['unit_ids']) && is_array($targetContext['unit_ids']) && count($targetContext['unit_ids']) > 1) {
            return $this->resolveMultiUnit(
                $student,
                $targetContext,
                $dimension,
                $examType,
                $now
            );
        }

        // Normalize target context from lower-level entities if higher-level keys are absent
        $normalizedContext = $this->normalizeTargetContext($targetContext);

        // Fail-closed check: if normalization detected invalid or mismatched entities, deny immediately
        if (!empty($normalizedContext['_fail_closed'])) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: $normalizedContext['_fail_closed_reason'] ?? 'Invalid or mismatched academic target context.',
                diagnostic: $normalizedContext['_fail_closed_diagnostic'] ?? 'Target context failed validation during normalization.',
                upgradeSlug: null
            );
        }

        // Step 1: Tier 1 — Valid Paid Subscription Check
        $activeProgramIds = $this->paidAccessService->getActiveProgramIdsForStudent((int) $student->id);
        if (!empty($activeProgramIds)) {
            $coveringProgram = $this->findCoveringPaidProgram($activeProgramIds, $normalizedContext);
            if ($coveringProgram !== null) {
                return UnifiedAccessDecision::grantedPaid(
                    dimension: $dimension,
                    diagnostic: "Access granted via active paid subscription for program #{$coveringProgram->id} ('{$coveringProgram->title}').",
                    programId: (int) $coveringProgram->id,
                    programSlug: $coveringProgram->slug
                );
            }
        }

        // Step 2 & 3 & 4: Freemium, Legacy Free Fallback, and Default Deny
        $freemiumResult = $this->ruleEvaluator->evaluate($student, $normalizedContext, $dimension, $now);

        // Tier 2: Explicit Freemium Rules
        if ($freemiumResult->getSource() === FreemiumEvaluationResult::SOURCE_EXPLICIT_RULE) {
            $campaignId = $freemiumResult->getPrimaryCampaign()?->id
                ?? $freemiumResult->getWinningRule()?->campaign_id;
            $winningRuleId = $freemiumResult->getWinningRule()?->id;

            if ($freemiumResult->isAllowed()) {
                $requiresQuota = ($examType !== null);
                return UnifiedAccessDecision::grantedFreemium(
                    campaignId: $campaignId,
                    winningRuleId: $winningRuleId,
                    dimension: $dimension,
                    requiresQuota: $requiresQuota,
                    examType: $examType,
                    diagnostic: $freemiumResult->getReason()
                );
            }

            // Explicit Freemium DENY
            return UnifiedAccessDecision::deniedFreemium(
                campaignId: $campaignId,
                winningRuleId: $winningRuleId,
                dimension: $dimension,
                denialReason: 'Access denied by Freemium policy.',
                diagnostic: $freemiumResult->getReason(),
                upgradeSlug: $this->resolveUpgradeProgramSlug($normalizedContext)
            );
        }

        // Tier 3: Legacy Free Fallback
        if ($freemiumResult->getSource() === FreemiumEvaluationResult::SOURCE_LEGACY_FALLBACK) {
            return UnifiedAccessDecision::grantedLegacy(
                dimension: $dimension,
                diagnostic: $freemiumResult->getReason()
            );
        }

        // Tier 4: Default Deny (fail-closed)
        return UnifiedAccessDecision::deniedDefault(
            dimension: $dimension,
            denialReason: 'An active subscription is required to access this resource.',
            diagnostic: $freemiumResult->getReason(),
            upgradeSlug: $this->resolveUpgradeProgramSlug($normalizedContext)
        );
    }

    /**
     * Find an active OfferedProgram subscribed by the student that covers the requested target context.
     *
     * @param int[] $activeProgramIds
     * @param array $context
     * @return OfferedProgram|null
     */
    protected function findCoveringPaidProgram(array $activeProgramIds, array $context): ?OfferedProgram
    {
        // 1. Explicit offered_program_id requested
        if (!empty($context['offered_program_id'])) {
            $requestedProgramId = (int) $context['offered_program_id'];
            if (!in_array($requestedProgramId, $activeProgramIds, true)) {
                return null;
            }

            // If subject_id is specified, ensure that subject belongs to this offered program
            if (!empty($context['subject_id'])) {
                $coversSubject = ProgramSubject::query()
                    ->where('offered_program_id', $requestedProgramId)
                    ->where('subject_id', $context['subject_id'])
                    ->where('is_active', true)
                    ->exists();

                if (!$coversSubject) {
                    return null;
                }
            }

            return OfferedProgram::find($requestedProgramId);
        }

        // 2. Subject specified without explicit offered_program_id
        if (!empty($context['subject_id'])) {
            $query = OfferedProgram::query()
                ->whereIn('id', $activeProgramIds)
                ->where('is_active', true)
                ->whereHas('programSubjects', function ($q) use ($context) {
                    $q->where('subject_id', $context['subject_id'])
                      ->where('is_active', true);
                });

            if (!empty($context['class_id']) || !empty($context['curriculum_board_id'])) {
                $query->whereHas('offeredClass', function ($q) use ($context) {
                    if (!empty($context['class_id'])) {
                        $q->where('class_id', $context['class_id']);
                    }
                    if (!empty($context['curriculum_board_id'])) {
                        $q->where('curriculum_board_id', $context['curriculum_board_id']);
                    }
                });
            }

            return $query->first();
        }

        // 3. Class or Board specified without subject
        if (!empty($context['class_id']) || !empty($context['curriculum_board_id'])) {
            $query = OfferedProgram::query()
                ->whereIn('id', $activeProgramIds)
                ->where('is_active', true)
                ->whereHas('offeredClass', function ($q) use ($context) {
                    if (!empty($context['class_id'])) {
                        $q->where('class_id', $context['class_id']);
                    }
                    if (!empty($context['curriculum_board_id'])) {
                        $q->where('curriculum_board_id', $context['curriculum_board_id']);
                    }
                });

            return $query->first();
        }

        // 4. Global or generic access
        if (empty($context) || (count($context) === 1 && isset($context['global']))) {
            return OfferedProgram::whereIn('id', $activeProgramIds)
                ->where('is_active', true)
                ->first();
        }

        return null;
    }

    /**
     * Enrich and validate target context from lower-level entities (unit_ids, chapter_id, book_id).
     *
     * @param array $context
     * @return array
     */
    public function normalizeTargetContext(array $context): array
    {
        // 0a. Process and normalize question_ids / question_id if present
        $rawQuestionIds = null;
        if (isset($context['question_ids'])) {
            if (is_array($context['question_ids'])) {
                $rawQuestionIds = array_values(array_unique(array_filter(
                    array_map('intval', $context['question_ids']),
                    fn($id) => $id > 0
                )));
            } elseif (is_numeric($context['question_ids']) && (int)$context['question_ids'] > 0) {
                $rawQuestionIds = [(int) $context['question_ids']];
            } else {
                $rawQuestionIds = [];
            }

            if (empty($rawQuestionIds) && !empty($context['question_ids'])) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Invalid question IDs supplied.';
                $context['_fail_closed_diagnostic'] = 'The question_ids array contains no valid positive integer question IDs.';
                return $context;
            }

            $context['question_ids'] = $rawQuestionIds;
        }

        if ((is_array($rawQuestionIds) && count($rawQuestionIds) === 1) || !empty($context['question_id'])) {
            $qid = !empty($context['question_id']) ? (int) $context['question_id'] : $rawQuestionIds[0];
            if ($qid <= 0) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Invalid question ID supplied.';
                $context['_fail_closed_diagnostic'] = "Question ID #{$qid} is not a valid positive integer.";
                return $context;
            }

            if (!empty($rawQuestionIds) && !empty($context['question_id']) && (int)$context['question_id'] !== $rawQuestionIds[0]) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Conflicting question_id and question_ids supplied.';
                $context['_fail_closed_diagnostic'] = "Explicit question_id #{$context['question_id']} conflicts with question_ids [{$rawQuestionIds[0]}].";
                return $context;
            }

            $question = ExamQuestion::find($qid);
            if (!$question || !$question->activate) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested question does not exist or is inactive.';
                $context['_fail_closed_diagnostic'] = "Question #{$qid} was not found or is inactive in exam_question_tbl.";
                return $context;
            }

            if (!empty($context['topic_id']) && (int)$context['topic_id'] !== (int)$question->topic_id) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested question does not belong to the specified topic.';
                $context['_fail_closed_diagnostic'] = "Question #{$qid} belongs to topic #{$question->topic_id}, but topic #{$context['topic_id']} was specified.";
                return $context;
            }

            $context['question_id'] = $qid;
            $context['topic_id'] = (int) $question->topic_id;
        }

        // 0b. Process and normalize topic_ids / topic_id if present
        $rawTopicIds = null;
        if (isset($context['topic_ids'])) {
            if (is_array($context['topic_ids'])) {
                $rawTopicIds = array_values(array_unique(array_filter(
                    array_map('intval', $context['topic_ids']),
                    fn($id) => $id > 0
                )));
            } elseif (is_numeric($context['topic_ids']) && (int)$context['topic_ids'] > 0) {
                $rawTopicIds = [(int) $context['topic_ids']];
            } else {
                $rawTopicIds = [];
            }

            if (empty($rawTopicIds) && !empty($context['topic_ids'])) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Invalid topic IDs supplied.';
                $context['_fail_closed_diagnostic'] = 'The topic_ids array contains no valid positive integer topic IDs.';
                return $context;
            }

            $context['topic_ids'] = $rawTopicIds;
        }

        if ((is_array($rawTopicIds) && count($rawTopicIds) === 1) || !empty($context['topic_id'])) {
            $tid = !empty($context['topic_id']) ? (int) $context['topic_id'] : $rawTopicIds[0];
            if ($tid <= 0) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Invalid topic ID supplied.';
                $context['_fail_closed_diagnostic'] = "Topic ID #{$tid} is not a valid positive integer.";
                return $context;
            }

            if (!empty($rawTopicIds) && !empty($context['topic_id']) && (int)$context['topic_id'] !== $rawTopicIds[0]) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Conflicting topic_id and topic_ids supplied.';
                $context['_fail_closed_diagnostic'] = "Explicit topic_id #{$context['topic_id']} conflicts with topic_ids [{$rawTopicIds[0]}].";
                return $context;
            }

            $topic = BookUnitTopic::find($tid);
            if (!$topic || !$topic->activate) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested topic does not exist or is inactive.';
                $context['_fail_closed_diagnostic'] = "Topic #{$tid} was not found or is inactive in book_unit_topic_tbl.";
                return $context;
            }

            if (!empty($context['chapter_id']) && (int)$context['chapter_id'] !== (int)$topic->unit_id) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested topic does not belong to the specified chapter.';
                $context['_fail_closed_diagnostic'] = "Topic #{$tid} belongs to chapter/unit #{$topic->unit_id}, but chapter #{$context['chapter_id']} was specified.";
                return $context;
            }

            if (!empty($context['unit_ids'])) {
                $uids = is_array($context['unit_ids']) ? array_map('intval', $context['unit_ids']) : [(int)$context['unit_ids']];
                if (count($uids) === 1 && $uids[0] !== (int)$topic->unit_id) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Requested topic does not belong to the specified unit.';
                    $context['_fail_closed_diagnostic'] = "Topic #{$tid} belongs to unit #{$topic->unit_id}, but unit_ids [{$uids[0]}] was specified.";
                    return $context;
                }
            }

            $context['topic_id'] = $tid;
            $context['chapter_id'] = (int) $topic->unit_id;
            $context['unit_ids'] = [(int) $topic->unit_id];
        }

        // 1. Process and normalize unit_ids if present
        if (isset($context['unit_ids'])) {
            if (is_array($context['unit_ids'])) {
                $rawUnitIds = array_values(array_unique(array_filter(
                    array_map('intval', $context['unit_ids']),
                    fn($id) => $id > 0
                )));
            } elseif (is_numeric($context['unit_ids']) && (int)$context['unit_ids'] > 0) {
                $rawUnitIds = [(int) $context['unit_ids']];
            } else {
                $rawUnitIds = [];
            }

            // If unit_ids was supplied in context but had no valid positive integer IDs
            if (empty($rawUnitIds) && !empty($context['unit_ids'])) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Invalid unit IDs supplied.';
                $context['_fail_closed_diagnostic'] = 'The unit_ids array contains no valid positive integer unit IDs.';
                return $context;
            }

            $context['unit_ids'] = $rawUnitIds;

            // Handle Single Unit ID
            if (count($rawUnitIds) === 1) {
                $unitId = $rawUnitIds[0];

                // Check conflict with explicit chapter_id if also provided
                if (!empty($context['chapter_id']) && (int)$context['chapter_id'] !== $unitId) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Conflicting chapter_id and unit_ids supplied.';
                    $context['_fail_closed_diagnostic'] = "Explicit chapter_id #{$context['chapter_id']} conflicts with unit_ids [{$unitId}].";
                    return $context;
                }

                $unit = BookUnit::find($unitId);
                if (!$unit) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Requested unit does not exist.';
                    $context['_fail_closed_diagnostic'] = "Unit #{$unitId} was not found in book_unit_tbl.";
                    return $context;
                }

                // Check book consistency
                if (!empty($context['book_id']) && (int)$context['book_id'] !== (int)$unit->book_id) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified book.';
                    $context['_fail_closed_diagnostic'] = "Unit #{$unitId} belongs to book #{$unit->book_id}, but book #{$context['book_id']} was specified.";
                    return $context;
                }

                $book = Book::find($unit->book_id);
                if (!$book) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Book associated with requested unit does not exist.';
                    $context['_fail_closed_diagnostic'] = "Book #{$unit->book_id} for unit #{$unitId} not found in book_tbl.";
                    return $context;
                }

                // Check subject consistency
                if (!empty($context['subject_id']) && (int)$context['subject_id'] !== (int)$book->subject_id) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified subject.';
                    $context['_fail_closed_diagnostic'] = "Unit #{$unitId} belongs to subject #{$book->subject_id}, but subject #{$context['subject_id']} was specified.";
                    return $context;
                }

                // Check class consistency
                if (!empty($context['class_id']) && (int)$context['class_id'] !== (int)$book->class_id) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified class.';
                    $context['_fail_closed_diagnostic'] = "Unit #{$unitId} belongs to class #{$book->class_id}, but class #{$context['class_id']} was specified.";
                    return $context;
                }

                // Check board consistency
                if (!empty($context['curriculum_board_id']) && (int)$context['curriculum_board_id'] !== (int)$book->curriculum_board_id) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified board.';
                    $context['_fail_closed_diagnostic'] = "Unit #{$unitId} belongs to board #{$book->curriculum_board_id}, but board #{$context['curriculum_board_id']} was specified.";
                    return $context;
                }

                // Populate normalized hierarchy
                $context['chapter_id'] = $unitId;
                $context['book_id'] = (int) $unit->book_id;
                $context['subject_id'] = (int) $book->subject_id;
                $context['class_id'] = $context['class_id'] ?? (int) $book->class_id;
                $context['curriculum_board_id'] = $context['curriculum_board_id'] ?? (int) $book->curriculum_board_id;
            } elseif (count($rawUnitIds) > 1) {
                // Handle Multiple Unit IDs
                if (!empty($context['chapter_id'])) {
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'Conflicting chapter_id and multiple unit_ids supplied.';
                    $context['_fail_closed_diagnostic'] = "Explicit chapter_id #{$context['chapter_id']} supplied alongside multiple unit_ids.";
                    return $context;
                }

                $units = BookUnit::whereIn('id', $rawUnitIds)->get()->keyBy('id');
                if ($units->count() !== count($rawUnitIds)) {
                    $missing = array_diff($rawUnitIds, $units->keys()->all());
                    $context['_fail_closed'] = true;
                    $context['_fail_closed_reason'] = 'One or more requested unit IDs do not exist.';
                    $context['_fail_closed_diagnostic'] = 'Requested unit_ids contain non-existent book units: ' . implode(', ', $missing);
                    return $context;
                }

                // Validate hierarchy for each unit against any explicitly supplied context
                foreach ($units as $u) {
                    if (!empty($context['book_id']) && (int)$context['book_id'] !== (int)$u->book_id) {
                        $context['_fail_closed'] = true;
                        $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified book.';
                        $context['_fail_closed_diagnostic'] = "Unit #{$u->id} belongs to book #{$u->book_id}, but book #{$context['book_id']} was specified.";
                        return $context;
                    }

                    $b = Book::find($u->book_id);
                    if (!$b) {
                        $context['_fail_closed'] = true;
                        $context['_fail_closed_reason'] = 'Book associated with requested unit does not exist.';
                        $context['_fail_closed_diagnostic'] = "Book #{$u->book_id} for unit #{$u->id} not found in book_tbl.";
                        return $context;
                    }

                    if (!empty($context['subject_id']) && (int)$context['subject_id'] !== (int)$b->subject_id) {
                        $context['_fail_closed'] = true;
                        $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified subject.';
                        $context['_fail_closed_diagnostic'] = "Unit #{$u->id} belongs to subject #{$b->subject_id}, but subject #{$context['subject_id']} was specified.";
                        return $context;
                    }

                    if (!empty($context['class_id']) && (int)$context['class_id'] !== (int)$b->class_id) {
                        $context['_fail_closed'] = true;
                        $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified class.';
                        $context['_fail_closed_diagnostic'] = "Unit #{$u->id} belongs to class #{$b->class_id}, but class #{$context['class_id']} was specified.";
                        return $context;
                    }

                    if (!empty($context['curriculum_board_id']) && (int)$context['curriculum_board_id'] !== (int)$b->curriculum_board_id) {
                        $context['_fail_closed'] = true;
                        $context['_fail_closed_reason'] = 'Requested unit does not belong to the specified board.';
                        $context['_fail_closed_diagnostic'] = "Unit #{$u->id} belongs to board #{$b->curriculum_board_id}, but board #{$context['curriculum_board_id']} was specified.";
                        return $context;
                    }
                }

                // If all units belong to the same book, enrich book_id and parent hierarchy if empty
                $bookIds = $units->pluck('book_id')->unique()->values();
                if ($bookIds->count() === 1) {
                    $commonBook = Book::find($bookIds[0]);
                    if ($commonBook) {
                        $context['book_id'] = $context['book_id'] ?? (int) $commonBook->id;
                        $context['subject_id'] = $context['subject_id'] ?? (int) $commonBook->subject_id;
                        $context['class_id'] = $context['class_id'] ?? (int) $commonBook->class_id;
                        $context['curriculum_board_id'] = $context['curriculum_board_id'] ?? (int) $commonBook->curriculum_board_id;
                    }
                }

                // Note: Do NOT set chapter_id for multi-unit requests!
            }
        }

        // 2. If chapter_id is given without unit_ids or without book_id, resolve and validate
        if (!empty($context['chapter_id'])) {
            $chapterId = (int) $context['chapter_id'];
            $unit = BookUnit::find($chapterId);
            if (!$unit) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested chapter does not exist.';
                $context['_fail_closed_diagnostic'] = "Chapter #{$chapterId} was not found in book_unit_tbl.";
                return $context;
            }

            if (!empty($context['book_id']) && (int)$context['book_id'] !== (int)$unit->book_id) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested chapter does not belong to the specified book.';
                $context['_fail_closed_diagnostic'] = "Chapter #{$chapterId} belongs to book #{$unit->book_id}, but book #{$context['book_id']} was specified.";
                return $context;
            }

            $context['book_id'] = $context['book_id'] ?? (int)$unit->book_id;
        }

        // 3. If book_id is given, resolve and validate subject, class, board
        if (!empty($context['book_id'])) {
            $bookId = (int) $context['book_id'];
            $book = Book::find($bookId);
            if (!$book) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested book does not exist.';
                $context['_fail_closed_diagnostic'] = "Book #{$bookId} was not found in book_tbl.";
                return $context;
            }

            if (!empty($context['subject_id']) && (int)$context['subject_id'] !== (int)$book->subject_id) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested book does not belong to the specified subject.';
                $context['_fail_closed_diagnostic'] = "Book #{$bookId} belongs to subject #{$book->subject_id}, but subject #{$context['subject_id']} was specified.";
                return $context;
            }

            if (!empty($context['class_id']) && (int)$context['class_id'] !== (int)$book->class_id) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested book does not belong to the specified class.';
                $context['_fail_closed_diagnostic'] = "Book #{$bookId} belongs to class #{$book->class_id}, but class #{$context['class_id']} was specified.";
                return $context;
            }

            if (!empty($context['curriculum_board_id']) && (int)$context['curriculum_board_id'] !== (int)$book->curriculum_board_id) {
                $context['_fail_closed'] = true;
                $context['_fail_closed_reason'] = 'Requested book does not belong to the specified board.';
                $context['_fail_closed_diagnostic'] = "Book #{$bookId} belongs to board #{$book->curriculum_board_id}, but board #{$context['curriculum_board_id']} was specified.";
                return $context;
            }

            $context['subject_id'] = $context['subject_id'] ?? (int) $book->subject_id;
            $context['class_id'] = $context['class_id'] ?? (int) $book->class_id;
            $context['curriculum_board_id'] = $context['curriculum_board_id'] ?? (int) $book->curriculum_board_id;
        }

        return $context;
    }

    /**
     * Resolve entitlement for multi-question requests by evaluating each question independently.
     * Fails closed if any question is denied or invalid.
     */
    protected function resolveMultiQuestion(
        WebUser $student,
        array $targetContext,
        string $dimension,
        ?string $examType,
        ?Carbon $now
    ): UnifiedAccessDecision {
        $rawQuestionIds = array_values(array_unique(array_filter(
            array_map('intval', $targetContext['question_ids'] ?? []),
            fn($id) => $id > 0
        )));

        if (empty($rawQuestionIds)) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'No valid question IDs supplied in multi-question request.',
                diagnostic: 'Target context question_ids contains no valid positive integers.',
                upgradeSlug: null
            );
        }

        // Conflicting explicit question_id in multi-question request
        if (!empty($targetContext['question_id'])) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'Conflicting question_id and multiple question_ids supplied.',
                diagnostic: "Explicit question_id #{$targetContext['question_id']} supplied alongside multiple question_ids.",
                upgradeSlug: null
            );
        }

        // Check if any questions do not exist or are inactive
        $existingQuestions = ExamQuestion::whereIn('id', $rawQuestionIds)->where('activate', 1)->get()->keyBy('id');
        if ($existingQuestions->count() !== count($rawQuestionIds)) {
            $missing = array_diff($rawQuestionIds, $existingQuestions->keys()->all());
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'One or more requested question IDs do not exist or are inactive.',
                diagnostic: 'Requested question_ids contain non-existent or inactive questions: ' . implode(', ', $missing),
                upgradeSlug: null
            );
        }

        // Evaluate each question individually
        $decisions = [];
        foreach ($rawQuestionIds as $singleQuestionId) {
            $singleContext = $targetContext;
            $singleContext['question_ids'] = [$singleQuestionId];
            $singleContext['question_id'] = $singleQuestionId;

            $decision = $this->resolve(
                $student,
                $singleContext,
                $dimension,
                $examType,
                $now
            );

            if ($decision->isDenied()) {
                return UnifiedAccessDecision::deniedDefault(
                    dimension: $dimension,
                    denialReason: "Access denied: Question #{$singleQuestionId} is not covered by an active subscription or freemium campaign.",
                    diagnostic: "Multi-question request denied because question #{$singleQuestionId} was denied: " . $decision->getDiagnosticMessage(),
                    upgradeSlug: $decision->getUpgradeProgramSlug() ?? $this->resolveUpgradeProgramSlug($targetContext)
                );
            }

            $decisions[] = $decision;
        }

        return $this->combineGrantedDecisions($decisions, $dimension, $examType);
    }

    /**
     * Resolve entitlement for multi-topic requests by evaluating each topic independently.
     * Fails closed if any topic is denied or invalid.
     */
    protected function resolveMultiTopic(
        WebUser $student,
        array $targetContext,
        string $dimension,
        ?string $examType,
        ?Carbon $now
    ): UnifiedAccessDecision {
        $rawTopicIds = array_values(array_unique(array_filter(
            array_map('intval', $targetContext['topic_ids'] ?? []),
            fn($id) => $id > 0
        )));

        if (empty($rawTopicIds)) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'No valid topic IDs supplied in multi-topic request.',
                diagnostic: 'Target context topic_ids contains no valid positive integers.',
                upgradeSlug: null
            );
        }

        // Conflicting explicit topic_id in multi-topic request
        if (!empty($targetContext['topic_id'])) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'Conflicting topic_id and multiple topic_ids supplied.',
                diagnostic: "Explicit topic_id #{$targetContext['topic_id']} supplied alongside multiple topic_ids.",
                upgradeSlug: null
            );
        }

        // Check if any topics do not exist or are inactive
        $existingTopics = BookUnitTopic::whereIn('id', $rawTopicIds)->where('activate', 1)->get()->keyBy('id');
        if ($existingTopics->count() !== count($rawTopicIds)) {
            $missing = array_diff($rawTopicIds, $existingTopics->keys()->all());
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'One or more requested topic IDs do not exist or are inactive.',
                diagnostic: 'Requested topic_ids contain non-existent or inactive topics: ' . implode(', ', $missing),
                upgradeSlug: null
            );
        }

        // Evaluate each topic individually
        $decisions = [];
        foreach ($rawTopicIds as $singleTopicId) {
            $singleContext = $targetContext;
            $singleContext['topic_ids'] = [$singleTopicId];
            $singleContext['topic_id'] = $singleTopicId;

            $decision = $this->resolve(
                $student,
                $singleContext,
                $dimension,
                $examType,
                $now
            );

            if ($decision->isDenied()) {
                return UnifiedAccessDecision::deniedDefault(
                    dimension: $dimension,
                    denialReason: "Access denied: Topic #{$singleTopicId} is not covered by an active subscription or freemium campaign.",
                    diagnostic: "Multi-topic request denied because topic #{$singleTopicId} was denied: " . $decision->getDiagnosticMessage(),
                    upgradeSlug: $decision->getUpgradeProgramSlug() ?? $this->resolveUpgradeProgramSlug($targetContext)
                );
            }

            $decisions[] = $decision;
        }

        return $this->combineGrantedDecisions($decisions, $dimension, $examType);
    }

    /**
     * Resolve entitlement for multi-unit requests by evaluating each unit independently.
     * Fails closed if any unit is denied or invalid.
     */
    protected function resolveMultiUnit(
        WebUser $student,
        array $targetContext,
        string $dimension,
        ?string $examType,
        ?Carbon $now
    ): UnifiedAccessDecision {
        $rawUnitIds = array_values(array_unique(array_filter(
            array_map('intval', $targetContext['unit_ids'] ?? []),
            fn($id) => $id > 0
        )));

        if (empty($rawUnitIds)) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'No valid unit IDs supplied in multi-unit request.',
                diagnostic: 'Target context unit_ids contains no valid positive integers.',
                upgradeSlug: null
            );
        }

        // Conflicting explicit chapter_id in multi-unit request
        if (!empty($targetContext['chapter_id'])) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'Conflicting chapter_id and multiple unit_ids supplied.',
                diagnostic: "Explicit chapter_id #{$targetContext['chapter_id']} supplied alongside multiple unit_ids.",
                upgradeSlug: null
            );
        }

        // Check if any units do not exist in the database upfront
        $existingUnits = BookUnit::whereIn('id', $rawUnitIds)->get()->keyBy('id');
        if ($existingUnits->count() !== count($rawUnitIds)) {
            $missing = array_diff($rawUnitIds, $existingUnits->keys()->all());
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'One or more requested unit IDs do not exist.',
                diagnostic: 'Requested unit_ids contain non-existent book units: ' . implode(', ', $missing),
                upgradeSlug: null
            );
        }

        // Evaluate each unit individually
        $decisions = [];
        foreach ($rawUnitIds as $singleUnitId) {
            $singleContext = $targetContext;
            $singleContext['unit_ids'] = [$singleUnitId];
            unset($singleContext['chapter_id']);

            $decision = $this->resolve(
                $student,
                $singleContext,
                $dimension,
                $examType,
                $now
            );

            if ($decision->isDenied()) {
                return UnifiedAccessDecision::deniedDefault(
                    dimension: $dimension,
                    denialReason: "Access denied: Unit #{$singleUnitId} is not covered by an active subscription or freemium campaign.",
                    diagnostic: "Multi-unit request denied because unit #{$singleUnitId} was denied: " . $decision->getDiagnosticMessage(),
                    upgradeSlug: $decision->getUpgradeProgramSlug() ?? $this->resolveUpgradeProgramSlug($targetContext)
                );
            }

            $decisions[] = $decision;
        }

        return $this->combineGrantedDecisions($decisions, $dimension, $examType);
    }

    /**
     * Combine multiple granted decisions into a single granted decision for multi-unit requests.
     *
     * @param UnifiedAccessDecision[] $decisions
     * @param string $dimension
     * @param string|null $examType
     * @return UnifiedAccessDecision
     */
    protected function combineGrantedDecisions(
        array $decisions,
        string $dimension,
        ?string $examType
    ): UnifiedAccessDecision {
        if (empty($decisions)) {
            return UnifiedAccessDecision::deniedDefault(
                dimension: $dimension,
                denialReason: 'No units evaluated.',
                diagnostic: 'Empty decisions array in multi-unit resolution.',
                upgradeSlug: null
            );
        }

        $allPaid = true;
        $firstPaid = null;
        foreach ($decisions as $d) {
            if ($d->getProvider() === UnifiedAccessDecision::PROVIDER_PAID) {
                $firstPaid = $firstPaid ?? $d;
            } else {
                $allPaid = false;
            }
        }

        if ($allPaid && $firstPaid) {
            return $firstPaid;
        }

        $allFreemium = true;
        $campaignIds = [];
        $ruleIds = [];
        $requiresQuota = false;
        foreach ($decisions as $d) {
            if ($d->getProvider() === UnifiedAccessDecision::PROVIDER_FREEMIUM) {
                if ($d->getCampaignId()) {
                    $campaignIds[] = $d->getCampaignId();
                }
                if ($d->getWinningRuleId()) {
                    $ruleIds[] = $d->getWinningRuleId();
                }
                if ($d->requiresQuotaEvaluation()) {
                    $requiresQuota = true;
                }
            } else {
                $allFreemium = false;
            }
        }

        if ($allFreemium) {
            $primaryCampaignId = !empty($campaignIds) ? $campaignIds[0] : null;
            $primaryRuleId = !empty($ruleIds) ? $ruleIds[0] : null;
            return UnifiedAccessDecision::grantedFreemium(
                campaignId: $primaryCampaignId,
                winningRuleId: $primaryRuleId,
                dimension: $dimension,
                requiresQuota: $requiresQuota,
                examType: $examType,
                diagnostic: 'All requested units granted via Freemium campaigns.'
            );
        }

        if ($firstPaid) {
            return $firstPaid;
        }

        return $decisions[0];
    }

    /**
     * Resolve upgrade program slug for guidance when access is denied.
     */
    protected function resolveUpgradeProgramSlug(array $context): ?string
    {
        if (!empty($context['offered_program_id'])) {
            $slug = OfferedProgram::where('id', $context['offered_program_id'])->value('slug');
            if ($slug) {
                return $slug;
            }
        }

        $subjectId = $context['subject_id'] ?? null;
        $classId   = $context['class_id'] ?? null;
        $boardId   = $context['curriculum_board_id'] ?? null;

        if ($subjectId) {
            $query = OfferedProgram::query()
                ->where('is_active', true)
                ->whereHas('programSubjects', function ($q) use ($subjectId) {
                    $q->where('subject_id', $subjectId)->where('is_active', true);
                });

            if ($classId || $boardId) {
                $query->whereHas('offeredClass', function ($q) use ($classId, $boardId) {
                    if ($classId) {
                        $q->where('class_id', $classId);
                    }
                    if ($boardId) {
                        $q->where('curriculum_board_id', $boardId);
                    }
                });
            }

            $program = $query->orderBy('display_order')->orderBy('id')->first();
            if ($program) {
                return $program->slug;
            }
        }

        if ($classId || $boardId) {
            $query = OfferedProgram::query()
                ->where('is_active', true)
                ->whereHas('offeredClass', function ($q) use ($classId, $boardId) {
                    if ($classId) {
                        $q->where('class_id', $classId);
                    }
                    if ($boardId) {
                        $q->where('curriculum_board_id', $boardId);
                    }
                });

            $program = $query->orderBy('display_order')->orderBy('id')->first();
            if ($program) {
                return $program->slug;
            }
        }

        return null;
    }

    public function getPaidAccessService(): StudentClassAccessService
    {
        return $this->paidAccessService;
    }

    public function getRuleEvaluator(): FreemiumAccessRuleEvaluator
    {
        return $this->ruleEvaluator;
    }
}
