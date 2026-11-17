<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassAssignmentEvaluation;
use App\Models\TeacherClassAssignmentSubmission;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherClassGradebookAssessmentExclusion;
use App\Models\TeacherClassGradebookCategoryRule;
use App\Models\TeacherClassGradebookConfig;
use App\Models\TeacherClassTest;
use App\Models\TeacherClassTestAssignment;
use App\Models\TeacherClassTestAttempt;
use App\Models\TeacherClassTestQuestion;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized Phase 7G Gradebook, Student Performance & Teacher Analytics Service.
 * Single source of truth built exclusively from authoritative Phase 7D MCQ attempts
 * and Phase 7F/7G assignment submissions and released evaluations.
 */
class AcademicPerformanceService
{
    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService,
        private readonly TeacherClassTestAttemptLifecycleService $testLifecycle,
        private readonly TeacherClassAssignmentService $assignmentService,
        private readonly TeacherAssignmentEvaluationService $evaluationService,
        private readonly StudentClassAccessService $classAccess
    ) {
    }

    public function resolveOwnedClassForRead(TeacherProfile $profile, int $classId): TeacherClass
    {
        $class = TeacherClass::query()
            ->ownedBy($profile)
            ->with(['academicClass', 'subject', 'curriculumBoard'])
            ->find($classId);

        if (!$class) {
            $this->resourceService->abortJson(404, 'class_not_found', 'Teacher class not found.');
        }

        return $class;
    }

    public function getGradebookConfig(TeacherProfile $profile, int $classId): array
    {
        $class = $this->resolveOwnedClassForRead($profile, $classId);

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'config' => $this->loadClassConfig($class),
            'study_sessions' => $this->loadAvailableStudySessions($class),
        ];
    }

    public function updateGradebookConfig(TeacherProfile $profile, int $classId, array $payload): array
    {
        $class = $this->resourceService->resolveOwnedActiveClass($profile, $classId);

        $studySessionId = !empty($payload['study_session_id']) ? (int) $payload['study_session_id'] : null;
        if ($studySessionId !== null && Schema::hasTable('study_session_tbl')) {
            $validSession = DB::table('study_session_tbl')
                ->where('id', $studySessionId)
                ->where('class_id', $class->class_id)
                ->where('curriculum_board_id', $class->curriculum_board_id)
                ->exists();

            if (!$validSession) {
                $this->resourceService->abortJson(
                    422,
                    'invalid_study_session',
                    'Selected academic study session does not match this class curriculum and grade.'
                );
            }
        }

        $categoriesInput = collect($payload['categories'] ?? []);
        if ($categoriesInput->pluck('category_key')->unique()->count() !== $categoriesInput->count()) {
            $this->resourceService->abortJson(422, 'duplicate_category_key', 'Duplicate assessment category key provided.');
        }

        $weightingEnabled = (bool) ($payload['weighting_enabled'] ?? false);
        $included = $categoriesInput->filter(fn ($c) => (bool) ($c['is_included'] ?? false));

        if ($included->isEmpty()) {
            $this->resourceService->abortJson(422, 'included_category_required', 'At least one assessment category must remain included in the gradebook.');
        }

        if ($weightingEnabled) {
            $weightSum = round((float) $included->sum(fn ($c) => (float) ($c['weight_percent'] ?? 0)), 2);
            if (abs($weightSum - 100.00) > 0.01) {
                $this->resourceService->abortJson(
                    422,
                    'invalid_category_weights',
                    'When category weighting is enabled, weights of included categories must total 100%.'
                );
            }
        }

        DB::transaction(function () use ($profile, $class, $payload, $studySessionId, $weightingEnabled, $categoriesInput) {
            TeacherClass::query()->where('id', $class->id)->lockForUpdate()->first();

            $config = TeacherClassGradebookConfig::updateOrCreate(
                ['teacher_class_id' => $class->id],
                [
                    'retake_score_policy' => (string) $payload['retake_score_policy'],
                    'weighting_enabled' => $weightingEnabled,
                    'study_session_id' => $studySessionId,
                    'effective_from' => $payload['effective_from'] ?? null,
                    'effective_to' => $payload['effective_to'] ?? null,
                    'updated_by_web_user_id' => $profile->web_user_id,
                ]
            );

            $defaults = [
                TeacherClassGradebookCategoryRule::CAT_MCQ_TEST => 'MCQ Examinations',
                TeacherClassGradebookCategoryRule::CAT_ASSIGNMENT => 'Assignments & Homework',
            ];

            foreach (TeacherClassGradebookCategoryRule::CATEGORIES as $key) {
                $row = $categoriesInput->firstWhere('category_key', $key);
                TeacherClassGradebookCategoryRule::updateOrCreate(
                    [
                        'teacher_class_id' => $class->id,
                        'category_key' => $key,
                    ],
                    [
                        'gradebook_config_id' => $config->id,
                        'label' => $row && !empty(trim((string) ($row['label'] ?? ''))) ? trim((string) $row['label']) : $defaults[$key],
                        'weight_percent' => $row ? round((float) $row['weight_percent'], 2) : 0.00,
                        'is_included' => $row ? (bool) $row['is_included'] : false,
                        'exclude_ungraded' => $row && array_key_exists('exclude_ungraded', $row) ? (bool) $row['exclude_ungraded'] : true,
                    ]
                );
            }
        });

        return $this->getGradebookConfig($profile, $classId);
    }

    public function toggleAssessmentExclusion(TeacherProfile $profile, int $classId, array $payload): array
    {
        $class = $this->resourceService->resolveOwnedActiveClass($profile, $classId);
        $type = (string) $payload['assessment_type'];
        $sourceId = (int) $payload['source_id'];
        $excluded = (bool) $payload['is_excluded'];

        $exists = $type === TeacherClassGradebookAssessmentExclusion::TYPE_MCQ_TEST
            ? TeacherClassTestAssignment::forClass($class)->where('id', $sourceId)->exists()
            : TeacherClassAssignment::where('teacher_class_id', $class->id)->where('id', $sourceId)->exists();

        if (!$exists) {
            $this->resourceService->abortJson(404, 'assessment_not_found', 'Target assessment does not belong to this class.');
        }

        TeacherClassGradebookAssessmentExclusion::updateOrCreate(
            [
                'teacher_class_id' => $class->id,
                'assessment_type' => $type,
                'source_id' => $sourceId,
            ],
            [
                'is_excluded' => $excluded,
                'updated_by_web_user_id' => $profile->web_user_id,
            ]
        );

        return [
            'success' => true,
            'assessment_type' => $type,
            'source_id' => $sourceId,
            'is_excluded' => $excluded,
        ];
    }

    public function getTeacherGradebook(TeacherProfile $profile, int $classId, array $filters = []): array
    {
        $class = $this->resolveOwnedClassForRead($profile, $classId);
        $model = $this->buildClassModel($class, $filters, 'teacher', null, false);

        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $allRows = $model['rows'];
        $totalRows = $allRows->count();
        $slicedRows = $allRows->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'config' => $model['config'],
            'study_sessions' => $this->loadAvailableStudySessions($class),
            'filter_options' => $model['filter_options'],
            'assessments' => $model['assessments']->values()->all(),
            'rows' => $slicedRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalRows,
                'last_page' => max(1, (int) ceil($totalRows / $perPage)),
            ],
            'policy_summary' => $this->describeCalculationPolicy($model['config']),
        ];
    }

    public function getTeacherClassPerformance(TeacherProfile $profile, int $classId, array $filters = []): array
    {
        $class = $this->resolveOwnedClassForRead($profile, $classId);

        return $this->computeClassAnalyticsPayload($class, $filters);
    }

    /**
     * Authoritative class analytics used by both Teacher Analytics and Admin Academic Reporting.
     */
    public function computeClassAnalyticsPayload(TeacherClass $class, array $filters = []): array
    {
        $model = $this->buildClassModel($class, $filters, 'teacher', null, false);
        $assessments = $model['assessments'];
        $rows = $model['rows'];

        $activeStudentCount = $rows->count();
        $studentPercentages = $rows->pluck('overall_percentage')->filter(fn ($v) => $v !== null)->values();
        $classAvgPercentage = $studentPercentages->isNotEmpty()
            ? round((float) $studentPercentages->avg(), 2)
            : null;

        $totalEvaluatedSubmissions = 0;
        $totalSubmittedAssignments = 0;
        $totalExpectedCells = 0;
        $totalCompletedCells = 0;

        $assessmentStats = $assessments->map(function (array $col) use (
            $rows,
            &$totalEvaluatedSubmissions,
            &$totalSubmittedAssignments,
            &$totalExpectedCells,
            &$totalCompletedCells
        ) {
            $colKey = $col['key'];
            $targetedCount = 0;
            $completedCount = 0;
            $releasedScores = collect();
            $releasedPercentages = collect();
            $submittedPendingEval = 0;
            $missingCount = 0;

            foreach ($rows as $row) {
                $cell = $row['cells'][$colKey] ?? null;
                if (!$cell || $cell['cell_state'] === 'not_assigned') {
                    continue;
                }
                $targetedCount++;
                $totalExpectedCells++;

                if ($col['assessment_type'] === 'assignment') {
                    if ($cell['has_submitted']) {
                        $totalSubmittedAssignments++;
                        if ($cell['result_status'] === 'released') {
                            $totalEvaluatedSubmissions++;
                        } else {
                            $submittedPendingEval++;
                        }
                    }
                }

                if (in_array($cell['cell_state'], ['released', 'unreleased_completed'], true)) {
                    $completedCount++;
                    $totalCompletedCells++;
                } elseif (in_array($cell['cell_state'], ['pending', 'missing', 'overdue'], true)) {
                    $missingCount++;
                }

                // Released results ONLY enter published assessment averages!
                if ($cell['result_status'] === 'released' && $cell['obtained_marks'] !== null) {
                    $releasedScores->push((float) $cell['obtained_marks']);
                    if ($cell['percentage'] !== null) {
                        $releasedPercentages->push((float) $cell['percentage']);
                    }
                }
            }

            $releasedCount = $releasedScores->count();

            return [
                'key' => $colKey,
                'assessment_type' => $col['assessment_type'],
                'source_id' => $col['source_id'],
                'title' => $col['title'],
                'max_marks' => $col['max_marks'],
                'assessment_date' => $col['assessment_date'],
                'is_excluded' => $col['is_excluded'],
                'result_released' => $col['result_released'],
                'unit' => $col['unit'],
                'topic' => $col['topic'],
                'targeted_count' => $targetedCount,
                'completed_count' => $completedCount,
                'released_count' => $releasedCount,
                'submitted_pending_evaluation_count' => $submittedPendingEval,
                'missing_count' => $missingCount,
                'completion_rate' => $targetedCount > 0 ? round(($completedCount / $targetedCount) * 100, 2) : null,
                'average_marks' => $releasedScores->isNotEmpty() ? round((float) $releasedScores->avg(), 2) : null,
                'average_percentage' => $releasedPercentages->isNotEmpty() ? round((float) $releasedPercentages->avg(), 2) : null,
                'highest_marks' => $releasedScores->isNotEmpty() ? round((float) $releasedScores->max(), 2) : null,
                'lowest_marks' => $releasedScores->isNotEmpty() ? round((float) $releasedScores->min(), 2) : null,
            ];
        })->values();

        // Topic/chapter breakdown from assessments with unit/topic mappings and released results
        $unitGroups = [];
        foreach ($assessmentStats as $stat) {
            if ($stat['average_percentage'] === null || $stat['is_excluded']) {
                continue;
            }
            $uId = $stat['unit']['id'] ?? null;
            $uLabel = $stat['unit']['name'] ?? null;
            if (!$uId || !$uLabel) {
                continue;
            }
            if (!isset($unitGroups[$uId])) {
                $unitGroups[$uId] = [
                    'unit_id' => (int) $uId,
                    'unit_name' => $uLabel,
                    'assessments_count' => 0,
                    'percentages' => [],
                ];
            }
            $unitGroups[$uId]['assessments_count']++;
            $unitGroups[$uId]['percentages'][] = (float) $stat['average_percentage'];
        }
        $unitBreakdown = collect($unitGroups)->map(fn (array $g) => [
            'unit_id' => $g['unit_id'],
            'unit_name' => $g['unit_name'],
            'assessments_count' => $g['assessments_count'],
            'average_percentage' => round(array_sum($g['percentages']) / count($g['percentages']), 2),
        ])->values()->all();

        $studentsWithMissing = $rows->filter(fn (array $r) => $r['pending_count'] > 0)->map(fn (array $r) => [
            'student_id' => $r['student']['id'],
            'name' => $r['student']['name'],
            'email' => $r['student']['email'],
            'completed_count' => $r['completed_count'],
            'pending_count' => $r['pending_count'],
            'overall_percentage' => $r['overall_percentage'],
        ])->values()->all();

        $trendPoints = $assessmentStats
            ->filter(fn (array $s) => !$s['is_excluded'] && $s['average_percentage'] !== null)
            ->sortBy('assessment_date')
            ->map(fn (array $s) => [
                'key' => $s['key'],
                'title' => $s['title'],
                'assessment_type' => $s['assessment_type'],
                'assessment_date' => $s['assessment_date'],
                'average_percentage' => $s['average_percentage'],
                'released_count' => $s['released_count'],
            ])
            ->values();

        return [
            'success' => true,
            'class' => $this->resourceService->presentClassSummary($class),
            'config' => $model['config'],
            'study_sessions' => $this->loadAvailableStudySessions($class),
            'filter_options' => $model['filter_options'],
            'overview' => [
                'active_students_count' => $activeStudentCount,
                'assessments_count' => $assessments->count(),
                'released_assessments_count' => $assessmentStats->where('released_count', '>', 0)->count(),
                'class_average_percentage' => $classAvgPercentage,
                'completion_rate' => $totalExpectedCells > 0 ? round(($totalCompletedCells / $totalExpectedCells) * 100, 2) : null,
                'assignment_evaluation_completion_rate' => $totalSubmittedAssignments > 0
                    ? round(($totalEvaluatedSubmissions / $totalSubmittedAssignments) * 100, 2)
                    : null,
                'evaluated_assignments_count' => $totalEvaluatedSubmissions,
                'submitted_assignments_count' => $totalSubmittedAssignments,
                'students_with_missing_count' => count($studentsWithMissing),
            ],
            'assessment_stats' => $assessmentStats->all(),
            'unit_breakdown' => $unitBreakdown,
            'students_with_missing' => $studentsWithMissing,
            'trend' => [
                'has_enough_data' => $trendPoints->count() >= 2,
                'points' => $trendPoints->all(),
            ],
            'policy_summary' => $this->describeCalculationPolicy($model['config']),
        ];
    }

    public function getStudentPerformance(WebUser $student, array $filters = []): array
    {
        $classFilter = !empty($filters['class_id']) ? (int) $filters['class_id'] : null;

        $enrollments = TeacherClassEnrollment::query()
            ->where('student_web_user_id', $student->id)
            ->when($classFilter, fn ($q) => $q->where('teacher_class_id', $classFilter))
            ->with(['teacherClass.academicClass', 'teacherClass.subject', 'teacherClass.curriculumBoard'])
            ->get()
            ->filter(fn (TeacherClassEnrollment $e) => $e->teacherClass !== null)
            ->values();

        $allItems = collect();
        $classesPayload = [];
        $subjectBuckets = [];
        $categoryBuckets = [
            'mcq_test' => ['category_key' => 'mcq_test', 'label' => 'MCQ Examinations', 'completed_count' => 0, 'obtained' => 0.0, 'max' => 0.0],
            'assignment' => ['category_key' => 'assignment', 'label' => 'Assignments & Homework', 'completed_count' => 0, 'obtained' => 0.0, 'max' => 0.0],
        ];
        $studySessions = collect();
        $totalCompleted = 0;
        $totalPending = 0;
        $totalReleasedTests = 0;
        $totalEvaluatedAssignments = 0;

        foreach ($enrollments as $enrollment) {
            /** @var TeacherClass $class */
            $class = $enrollment->teacherClass;
            $hasActiveSub = $this->classAccess->hasActiveSubscription((int) $student->id, $class);
            $isActiveEnrollment = $enrollment->status === TeacherClassEnrollment::STATUS_ACTIVE && $class->is_active && $hasActiveSub;

            // Removed/left or expired students see ONLY historical released items they already submitted/were evaluated on, never active pending work.
            $model = $this->buildClassModel($class, $filters, 'student', (int) $student->id, !$isActiveEnrollment);
            $row = $model['rows']->first();
            $assessmentsByKey = $model['assessments']->keyBy('key');

            if (!$row) {
                continue;
            }

            $classSessions = $this->loadAvailableStudySessions($class);
            $studySessions = $studySessions->merge($classSessions)->unique('id')->values();

            $classHasHistoricalItem = false;
            $classSubjectName = $class->subject?->subject_name ?? 'Subject';
            $classSubjectId = (int) ($class->subject_id ?? 0);

            foreach ($row['cells'] as $colKey => $cell) {
                $col = $assessmentsByKey->get($colKey);
                if (!$col || $cell['cell_state'] === 'not_assigned') {
                    continue;
                }

                // For non-active/historical enrollment, ONLY show items with a finalized test attempt or released evaluation
                if (!$isActiveEnrollment) {
                    $hasHistoricalEvidence = ($col['assessment_type'] === 'mcq_test' && $cell['result_status'] === 'released')
                        || ($col['assessment_type'] === 'assignment' && $cell['result_status'] === 'released');
                    if (!$hasHistoricalEvidence) {
                        continue;
                    }
                }

                $classHasHistoricalItem = true;

                if ($cell['result_status'] === 'released' && $cell['obtained_marks'] !== null && $col['max_marks'] > 0 && !$col['is_excluded'] && $cell['category_included']) {
                    $totalCompleted++;
                    if ($col['assessment_type'] === 'mcq_test') {
                        $totalReleasedTests++;
                    } else {
                        $totalEvaluatedAssignments++;
                    }

                    $obt = (float) $cell['obtained_marks'];
                    $max = (float) $col['max_marks'];

                    $categoryBuckets[$col['assessment_type']]['completed_count']++;
                    $categoryBuckets[$col['assessment_type']]['obtained'] += $obt;
                    $categoryBuckets[$col['assessment_type']]['max'] += $max;

                    $subjKey = $classSubjectId > 0 ? (string) $classSubjectId : $classSubjectName;
                    if (!isset($subjectBuckets[$subjKey])) {
                        $subjectBuckets[$subjKey] = [
                            'subject_id' => $classSubjectId > 0 ? $classSubjectId : null,
                            'subject_name' => $classSubjectName,
                            'completed_count' => 0,
                            'obtained' => 0.0,
                            'max' => 0.0,
                        ];
                    }
                    $subjectBuckets[$subjKey]['completed_count']++;
                    $subjectBuckets[$subjKey]['obtained'] += $obt;
                    $subjectBuckets[$subjKey]['max'] += $max;
                } elseif (in_array($cell['cell_state'], ['pending', 'missing', 'overdue'], true) && $isActiveEnrollment) {
                    $totalPending++;
                }

                $allItems->push([
                    'key' => $col['key'],
                    'assessment_type' => $col['assessment_type'],
                    'assessment_id' => $col['source_id'],
                    'teacher_class_id' => (int) $class->id,
                    'class_name' => $class->name,
                    'section' => $class->section !== '' ? $class->section : null,
                    'subject_name' => $classSubjectName,
                    'enrollment_status' => $enrollment->status,
                    'title' => $col['title'],
                    'assessment_date' => $col['assessment_date'],
                    'max_marks' => $col['max_marks'],
                    'obtained_marks' => $cell['obtained_marks'],
                    'percentage' => $cell['percentage'],
                    'result_status' => $cell['result_status'],
                    'cell_state' => $cell['cell_state'],
                    'is_excluded' => $col['is_excluded'],
                    'feedback_text' => $cell['feedback_text'],
                    'evaluated_at' => $cell['evaluated_at'],
                    'attempts_used' => $cell['attempts_used'],
                    'max_attempts' => $cell['max_attempts'],
                    'retake_policy' => $model['config']['retake_score_policy'],
                    'detail_url' => $col['assessment_type'] === 'mcq_test'
                        ? "/lms/student/performance/assessments/{$col['source_id']}?type=mcq_test"
                        : "/lms/student/performance/assessments/{$col['source_id']}?type=assignment",
                ]);
            }

            if ($isActiveEnrollment || $classHasHistoricalItem) {
                $classesPayload[] = [
                    'id' => (int) $class->id,
                    'name' => $class->name,
                    'section' => $class->section !== '' ? $class->section : null,
                    'subject_name' => $classSubjectName,
                    'enrollment_status' => $enrollment->status,
                    'overall_percentage' => $row['overall_percentage'],
                    'raw_percentage' => $row['raw_percentage'],
                    'calculation_mode' => $model['config']['weighting_enabled'] ? 'weighted' : 'unweighted',
                ];
            }
        }

        $releasedItems = $allItems->filter(fn ($i) => $i['result_status'] === 'released' && $i['obtained_marks'] !== null && !$i['is_excluded'])->values();
        $sumObtained = round((float) $releasedItems->sum('obtained_marks'), 2);
        $sumMax = round((float) $releasedItems->sum('max_marks'), 2);
        $rawPercentage = $sumMax > 0 ? round(($sumObtained / $sumMax) * 100, 2) : null;

        // Single-class enrollment/filter uses that class's configured overall percentage (raw or weighted);
        // multi-class overview aggregates all released points cleanly without inventing cross-class weights.
        $singleClassOverview = count($classesPayload) === 1 && $classesPayload[0]['overall_percentage'] !== null;
        $overallPercentage = $singleClassOverview
            ? $classesPayload[0]['overall_percentage']
            : $rawPercentage;
        $calculationMode = count($classesPayload) === 1
            ? ($classesPayload[0]['calculation_mode'] ?? 'unweighted')
            : 'unweighted';

        $trendPoints = $releasedItems
            ->sortBy('assessment_date')
            ->map(fn ($i) => [
                'key' => $i['key'],
                'title' => $i['title'],
                'assessment_type' => $i['assessment_type'],
                'subject_name' => $i['subject_name'],
                'assessment_date' => $i['assessment_date'],
                'percentage' => $i['percentage'],
                'obtained_marks' => $i['obtained_marks'],
                'max_marks' => $i['max_marks'],
            ])
            ->values();

        return [
            'success' => true,
            'summary' => [
                'overall_percentage' => $overallPercentage,
                'raw_percentage' => $rawPercentage,
                'calculation_mode' => $calculationMode,
                'raw_obtained_marks' => $sumObtained,
                'raw_max_marks' => $sumMax,
                'completed_assessments_count' => $totalCompleted,
                'pending_assessments_count' => $totalPending,
                'released_tests_count' => $totalReleasedTests,
                'evaluated_assignments_count' => $totalEvaluatedAssignments,
            ],
            'classes' => $classesPayload,
            'study_sessions' => $studySessions->all(),
            'subjects' => collect($subjectBuckets)->map(fn ($s) => [
                'subject_id' => $s['subject_id'],
                'subject_name' => $s['subject_name'],
                'completed_count' => $s['completed_count'],
                'obtained_marks' => round($s['obtained'], 2),
                'max_marks' => round($s['max'], 2),
                'percentage' => $s['max'] > 0 ? round(($s['obtained'] / $s['max']) * 100, 2) : null,
            ])->values()->all(),
            'categories' => collect($categoryBuckets)->map(fn ($c) => [
                'category_key' => $c['category_key'],
                'label' => $c['label'],
                'completed_count' => $c['completed_count'],
                'obtained_marks' => round($c['obtained'], 2),
                'max_marks' => round($c['max'], 2),
                'percentage' => $c['max'] > 0 ? round(($c['obtained'] / $c['max']) * 100, 2) : null,
            ])->values()->all(),
            'trend' => [
                'has_enough_data' => $trendPoints->count() >= 2,
                'points' => $trendPoints->all(),
            ],
            'assessments' => $allItems->sortByDesc('assessment_date')->values()->all(),
        ];
    }

    public function getStudentAssessmentDetail(WebUser $student, string $type, int $assessmentId): array
    {
        if (!in_array($type, ['mcq_test', 'assignment'], true)) {
            $this->resourceService->abortJson(404, 'assessment_not_found', 'Assessment type not found.');
        }

        if ($type === 'assignment') {
            $assignment = TeacherClassAssignment::query()
                ->whereIn('status', [TeacherClassAssignment::STATUS_PUBLISHED, TeacherClassAssignment::STATUS_CLOSED])
                ->with(['teacherClass.subject', 'teacherClass.academicClass'])
                ->find($assessmentId);

            if (!$assignment) {
                $this->resourceService->abortJson(404, 'assignment_not_found', 'Assignment not found.');
            }

            $enrollment = TeacherClassEnrollment::query()
                ->where('teacher_class_id', $assignment->teacher_class_id)
                ->where('student_web_user_id', $student->id)
                ->first();

            $releasedEval = TeacherClassAssignmentEvaluation::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $student->id)
                ->where('status', TeacherClassAssignmentEvaluation::STATUS_RELEASED)
                ->first();

            $hasActiveSub = $assignment->teacherClass ? $this->classAccess->hasActiveSubscription((int) $student->id, $assignment->teacherClass) : false;
            $isActiveEnrolled = $enrollment && $enrollment->status === TeacherClassEnrollment::STATUS_ACTIVE && $assignment->teacherClass?->is_active && $hasActiveSub;
            if (!$isActiveEnrolled && !$releasedEval) {
                $this->resourceService->abortJson(403, 'forbidden_assessment_access', 'You are not authorized to view this assignment record.');
            }

            if ($assignment->assignment_mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS) {
                $targeted = $assignment->recipients()->where('student_web_user_id', $student->id)->exists();
                if (!$targeted && !$releasedEval) {
                    $this->resourceService->abortJson(403, 'forbidden_assessment_access', 'You are not assigned to this assignment.');
                }
            }

            $submission = TeacherClassAssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_web_user_id', $student->id)
                ->with(['activeAttachments', 'versions'])
                ->first();

            return [
                'success' => true,
                'assessment_type' => 'assignment',
                'assessment' => [
                    'id' => (int) $assignment->id,
                    'title' => $assignment->title,
                    'instructions' => $assignment->instructions,
                    'class_name' => $assignment->teacherClass?->name,
                    'subject_name' => $assignment->teacherClass?->subject?->subject_name,
                    'max_marks' => $assignment->max_marks !== null ? round((float) $assignment->max_marks, 2) : null,
                    'due_at' => $assignment->due_at?->timezone('Asia/Karachi')->toIso8601String(),
                    'published_at' => $assignment->published_at?->timezone('Asia/Karachi')->toIso8601String(),
                    'computed_status' => $this->assignmentService->computeStudentStatus($assignment, $submission),
                    'enrollment_status' => $enrollment?->status ?? 'unknown',
                ],
                'submission' => $submission ? [
                    'status' => $submission->status,
                    'submission_version' => (int) $submission->submission_version,
                    'text_response' => $submission->text_response,
                    'is_late' => (bool) $submission->is_late,
                    'submitted_at' => $submission->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
                ] : null,
                // Strictly released evaluations only (null if draft/reopened/none)
                'evaluation' => $this->evaluationService->presentEvaluationForStudent($releasedEval),
            ];
        }

        // MCQ Test Assignment detail
        $testAssignment = TeacherClassTestAssignment::query()
            ->where('status', '!=', TeacherClassTestAssignment::STATUS_CANCELLED)
            ->with(['teacherClass.subject', 'test'])
            ->find($assessmentId);

        if (!$testAssignment || !$testAssignment->teacherClass) {
            $this->resourceService->abortJson(404, 'test_assignment_not_found', 'Examination assignment not found.');
        }

        $testAssignment = $this->testLifecycle->syncAssignment($testAssignment);

        $recipient = $testAssignment->recipients()
            ->where('student_web_user_id', $student->id)
            ->where('assignment_status', '!=', 'revoked')
            ->with(['attempts' => fn ($q) => $q->orderByDesc('attempt_number')])
            ->first();

        if (!$recipient) {
            $this->resourceService->abortJson(403, 'forbidden_assessment_access', 'You are not assigned to this examination.');
        }

        $enrollment = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $testAssignment->teacher_class_id)
            ->where('student_web_user_id', $student->id)
            ->first();

        $finalizedAttempts = $recipient->attempts->filter(fn (TeacherClassTestAttempt $a) => $a->isFinalized())->values();
        $hasActiveSub = $testAssignment->teacherClass ? $this->classAccess->hasActiveSubscription((int) $student->id, $testAssignment->teacherClass) : false;
        $isActiveEnrolled = $enrollment && $enrollment->status === TeacherClassEnrollment::STATUS_ACTIVE && $testAssignment->teacherClass?->is_active && $hasActiveSub;
        if (!$isActiveEnrolled && $finalizedAttempts->isEmpty()) {
            $this->resourceService->abortJson(403, 'forbidden_assessment_access', 'Active enrollment is required to view unattempted examinations.');
        }

        $config = $this->loadClassConfig($testAssignment->teacherClass);
        $selected = $this->selectTestAttemptScore($testAssignment, $finalizedAttempts, $config['retake_score_policy']);

        return [
            'success' => true,
            'assessment_type' => 'mcq_test',
            'assessment' => [
                'id' => (int) $testAssignment->id,
                'title' => $testAssignment->title_snapshot,
                'instructions' => $testAssignment->instructions_snapshot,
                'class_name' => $testAssignment->teacherClass?->name,
                'subject_name' => $testAssignment->teacherClass?->subject?->subject_name,
                'starts_at' => $testAssignment->starts_at?->timezone('Asia/Karachi')->toIso8601String(),
                'ends_at' => $testAssignment->ends_at?->timezone('Asia/Karachi')->toIso8601String(),
                'max_attempts' => (int) $testAssignment->max_attempts,
                'retake_score_policy' => $config['retake_score_policy'],
                'result_visibility' => $testAssignment->result_visibility,
                'max_marks' => $selected['max_marks'],
                'obtained_marks' => $selected['result_released'] ? $selected['obtained_marks'] : null,
                'percentage' => $selected['result_released'] ? $selected['percentage'] : null,
                'result_status' => $selected['result_status'],
            ],
            'attempts' => $finalizedAttempts->map(function (TeacherClassTestAttempt $att) use ($testAssignment) {
                $visible = $this->testLifecycle->canStudentViewMarks($testAssignment, $att);
                return [
                    'id' => (int) $att->id,
                    'attempt_number' => (int) $att->attempt_number,
                    'status' => $att->status,
                    'submitted_at' => $att->submitted_at?->timezone('Asia/Karachi')->toIso8601String(),
                    'result_released' => $visible,
                    'max_marks' => round((float) $att->max_marks, 2),
                    'obtained_marks' => $visible ? round((float) $att->obtained_marks, 2) : null,
                    'percentage' => $visible ? round((float) $att->percentage, 2) : null,
                ];
            })->all(),
        ];
    }

    /**
     * Core unified matrix builder across MCQ test assignments and class assignments.
     *
     * @return array{config:array, assessments:Collection, rows:Collection, filter_options:array}
     */
    public function buildClassModel(
        TeacherClass $class,
        array $filters = [],
        string $viewer = 'teacher',
        ?int $restrictStudentId = null,
        bool $includeHistoricalTargetStudent = false
    ): array {
        $config = $this->loadClassConfig($class);
        $rulesByCategory = collect($config['categories'])->keyBy('category_key');

        $exclusions = TeacherClassGradebookAssessmentExclusion::query()
            ->where('teacher_class_id', $class->id)
            ->where('is_excluded', true)
            ->get()
            ->mapWithKeys(fn ($ex) => ["{$ex->assessment_type}:{$ex->source_id}" => true]);

        [$rangeStart, $rangeEnd] = $this->resolveEffectiveDateWindow($class, $filters, $config);

        $typeFilter = (string) ($filters['assessment_type'] ?? 'all');
        $keyFilter = trim((string) ($filters['assessment_key'] ?? ''));
        $unitFilter = !empty($filters['book_unit_id']) ? (int) $filters['book_unit_id'] : null;
        $topicFilter = !empty($filters['book_unit_topic_id']) ? (int) $filters['book_unit_topic_id'] : null;
        $releasedOnly = filter_var($filters['released_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $studentSearch = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $studentIdFilter = $restrictStudentId ?: (!empty($filters['student_id']) ? (int) $filters['student_id'] : null);

        // 1. Resolve student rows scope
        $rosterQuery = TeacherClassEnrollment::query()
            ->where('teacher_class_id', $class->id)
            ->with(['student:id,name,email']);

        if ($restrictStudentId !== null) {
            $rosterQuery->where('student_web_user_id', $restrictStudentId);
            if (!$includeHistoricalTargetStudent) {
                $rosterQuery->where('status', TeacherClassEnrollment::STATUS_ACTIVE);
            }
        } else {
            $rosterQuery->where('status', TeacherClassEnrollment::STATUS_ACTIVE);
            if ($studentIdFilter) {
                $rosterQuery->where('student_web_user_id', $studentIdFilter);
            }
        }

        $enrollments = $rosterQuery->orderBy('id')->get();
        if ($studentSearch !== '') {
            $enrollments = $enrollments->filter(function (TeacherClassEnrollment $e) use ($studentSearch) {
                $hay = mb_strtolower(($e->student?->name ?? '') . ' ' . ($e->student?->email ?? ''));
                return str_contains($hay, $studentSearch);
            })->values();
        }

        // 2. Gather MCQ test assignments & Phase 7F assignments
        $assessments = collect();

        $testAssignments = TeacherClassTestAssignment::forClass($class)
            ->where('status', '!=', TeacherClassTestAssignment::STATUS_CANCELLED)
            ->with([
                'test.questions',
                'recipients.attempts' => fn ($q) => $q->orderBy('attempt_number'),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $classAssignments = TeacherClassAssignment::query()
            ->where('teacher_class_id', $class->id)
            ->whereIn('status', [TeacherClassAssignment::STATUS_PUBLISHED, TeacherClassAssignment::STATUS_CLOSED])
            ->with([
                'recipients',
                'submissions',
                'evaluations',
            ])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $unitIds = [];
        $topicIds = [];
        foreach ($classAssignments as $ca) {
            if ($ca->book_unit_id) $unitIds[] = (int) $ca->book_unit_id;
            if ($ca->book_unit_topic_id) $topicIds[] = (int) $ca->book_unit_topic_id;
        }
        $testUnitTopicMap = [];
        foreach ($testAssignments as $ta) {
            $metaFirst = $ta->test?->questions?->first()?->metadata_snapshot_json;
            $uId = isset($metaFirst['unit']['id']) ? (int) $metaFirst['unit']['id'] : null;
            $tId = isset($metaFirst['topic']['id']) ? (int) $metaFirst['topic']['id'] : null;
            if ($uId) $unitIds[] = $uId;
            if ($tId) $topicIds[] = $tId;
            $testUnitTopicMap[$ta->id] = [
                'unit' => $metaFirst['unit'] ?? null,
                'topic' => $metaFirst['topic'] ?? null,
            ];
        }

        $unitNames = $this->loadUnitMap($unitIds);
        $topicNames = $this->loadTopicMap($topicIds);

        // Normalize MCQ Test columns
        foreach ($testAssignments as $ta) {
            $ta = $this->testLifecycle->syncAssignment($ta);
            $colKey = "mcq_test:{$ta->id}";
            $assessDate = $ta->starts_at ?? $ta->assigned_at ?? $ta->created_at;
            $mapped = $testUnitTopicMap[$ta->id] ?? ['unit' => null, 'topic' => null];
            $uId = isset($mapped['unit']['id']) ? (int) $mapped['unit']['id'] : null;
            $tId = isset($mapped['topic']['id']) ? (int) $mapped['topic']['id'] : null;

            $isReleasedCol = $ta->results_released_at !== null
                || $ta->result_visibility === TeacherClassTest::VISIBILITY_AFTER_SUBMISSION
                || ($ta->result_visibility === TeacherClassTest::VISIBILITY_AFTER_CLOSE && $ta->status === TeacherClassTestAssignment::STATUS_CLOSED);

            $assessments->push([
                'key' => $colKey,
                'assessment_type' => 'mcq_test',
                'source_id' => (int) $ta->id,
                'title' => $ta->title_snapshot,
                'max_marks' => $ta->test ? round((float) $ta->test->total_marks, 2) : 0.0,
                'assessment_date' => $assessDate?->timezone('Asia/Karachi')->toIso8601String(),
                '_sort_ts' => $assessDate?->getTimestamp() ?? 0,
                'status' => $ta->status,
                'result_released' => $isReleasedCol,
                'is_excluded' => (bool) ($exclusions->get($colKey, false)),
                'unit' => $uId ? ['id' => $uId, 'name' => $unitNames[$uId] ?? ($mapped['unit']['name'] ?? null)] : null,
                'topic' => $tId ? ['id' => $tId, 'name' => $topicNames[$tId] ?? ($mapped['topic']['name'] ?? null)] : null,
                '_model' => $ta,
            ]);
        }

        // Normalize Phase 7F Assignment columns
        foreach ($classAssignments as $ca) {
            $colKey = "assignment:{$ca->id}";
            $assessDate = $ca->due_at ?? $ca->published_at ?? $ca->created_at;
            $hasAnyReleasedEval = $ca->evaluations->contains(fn ($e) => $e->status === TeacherClassAssignmentEvaluation::STATUS_RELEASED);

            $assessments->push([
                'key' => $colKey,
                'assessment_type' => 'assignment',
                'source_id' => (int) $ca->id,
                'title' => $ca->title,
                'max_marks' => $ca->max_marks !== null ? round((float) $ca->max_marks, 2) : null,
                'assessment_date' => $assessDate?->timezone('Asia/Karachi')->toIso8601String(),
                '_sort_ts' => $assessDate?->getTimestamp() ?? 0,
                'status' => $ca->status,
                'result_released' => $hasAnyReleasedEval,
                'is_excluded' => (bool) ($exclusions->get($colKey, false)),
                'unit' => $ca->book_unit_id ? ['id' => (int) $ca->book_unit_id, 'name' => $unitNames[(int) $ca->book_unit_id] ?? null] : null,
                'topic' => $ca->book_unit_topic_id ? ['id' => (int) $ca->book_unit_topic_id, 'name' => $topicNames[(int) $ca->book_unit_topic_id] ?? null] : null,
                '_model' => $ca,
            ]);
        }

        // Filter options before applying column filters
        $filterOptions = [
            'assessments' => $assessments->map(fn ($a) => [
                'key' => $a['key'],
                'assessment_type' => $a['assessment_type'],
                'source_id' => $a['source_id'],
                'title' => $a['title'],
            ])->values()->all(),
            'units' => $assessments->pluck('unit')->filter()->unique('id')->values()->all(),
            'topics' => $assessments->pluck('topic')->filter()->unique('id')->values()->all(),
        ];

        // Apply filters cleanly
        $filteredAssessments = $assessments->filter(function (array $col) use ($typeFilter, $keyFilter, $unitFilter, $topicFilter, $releasedOnly, $rangeStart, $rangeEnd) {
            if ($typeFilter !== '' && $typeFilter !== 'all' && $col['assessment_type'] !== $typeFilter) {
                return false;
            }
            if ($keyFilter !== '' && $col['key'] !== $keyFilter) {
                return false;
            }
            if ($unitFilter && (int) ($col['unit']['id'] ?? 0) !== $unitFilter) {
                return false;
            }
            if ($topicFilter && (int) ($col['topic']['id'] ?? 0) !== $topicFilter) {
                return false;
            }
            if ($releasedOnly && !$col['result_released']) {
                return false;
            }
            if ($rangeStart && $col['_sort_ts'] < $rangeStart->getTimestamp()) {
                return false;
            }
            if ($rangeEnd && $col['_sort_ts'] > $rangeEnd->getTimestamp()) {
                return false;
            }
            return true;
        })->sortBy('_sort_ts')->values();

        // 3. Build authoritative cells & totals per student row
        $now = CarbonImmutable::now('Asia/Karachi');
        $retakePolicy = $config['retake_score_policy'];
        $weightingEnabled = (bool) $config['weighting_enabled'];

        $rows = $enrollments->map(function (TeacherClassEnrollment $enrollment) use (
            $filteredAssessments,
            $rulesByCategory,
            $retakePolicy,
            $weightingEnabled,
            $viewer,
            $now
        ) {
            $studentId = (int) $enrollment->student_web_user_id;
            $cells = [];
            $completedCount = 0;
            $pendingCount = 0;
            $excludedCount = 0;

            $catTotals = [
                'mcq_test' => ['obtained' => 0.0, 'max' => 0.0, 'count' => 0],
                'assignment' => ['obtained' => 0.0, 'max' => 0.0, 'count' => 0],
            ];

            foreach ($filteredAssessments as $col) {
                $colKey = $col['key'];
                $catKey = $col['assessment_type'];
                $catRule = $rulesByCategory->get($catKey);
                $catIncluded = $catRule ? (bool) $catRule['is_included'] : true;

                if ($catKey === 'mcq_test') {
                    /** @var TeacherClassTestAssignment $ta */
                    $ta = $col['_model'];
                    $recipient = $ta->recipients->firstWhere('student_web_user_id', $studentId);

                    if (!$recipient || $recipient->assignment_status === 'revoked') {
                        $cells[$colKey] = [
                            'cell_state' => 'not_assigned',
                            'result_status' => 'not_assigned',
                            'obtained_marks' => null,
                            'max_marks' => $col['max_marks'],
                            'percentage' => null,
                            'attempts_used' => 0,
                            'max_attempts' => (int) $ta->max_attempts,
                            'feedback_text' => null,
                            'evaluated_at' => null,
                            'category_included' => $catIncluded,
                        ];
                        continue;
                    }

                    $finalizedAttempts = $recipient->attempts->filter(fn (TeacherClassTestAttempt $a) => $a->isFinalized())->values();
                    $scoreData = $this->selectTestAttemptScore($ta, $finalizedAttempts, $retakePolicy);

                    if ($finalizedAttempts->isEmpty()) {
                        $cellState = ($ta->status === TeacherClassTestAssignment::STATUS_CLOSED || $recipient->assignment_status === 'expired')
                            ? 'missing'
                            : 'pending';
                        $pendingCount++;
                    } elseif ($viewer === 'student' && !$scoreData['result_released']) {
                        $cellState = 'unreleased_completed';
                    } else {
                        $cellState = $scoreData['result_released'] ? 'released' : 'unreleased_completed';
                    }

                    $visibleObtained = ($viewer === 'teacher' || $scoreData['result_released']) ? $scoreData['obtained_marks'] : null;
                    $visiblePct = ($viewer === 'teacher' || $scoreData['result_released']) ? $scoreData['percentage'] : null;

                    if ($scoreData['result_released'] && $scoreData['obtained_marks'] !== null && $scoreData['max_marks'] > 0) {
                        if ($col['is_excluded'] || !$catIncluded) {
                            $excludedCount++;
                        } else {
                            $completedCount++;
                            $catTotals['mcq_test']['obtained'] += (float) $scoreData['obtained_marks'];
                            $catTotals['mcq_test']['max'] += (float) $scoreData['max_marks'];
                            $catTotals['mcq_test']['count']++;
                        }
                    }

                    $cells[$colKey] = [
                        'cell_state' => $cellState,
                        'result_status' => $scoreData['result_status'],
                        'obtained_marks' => $visibleObtained,
                        'max_marks' => $scoreData['max_marks'] ?? $col['max_marks'],
                        'percentage' => $visiblePct,
                        'attempts_used' => $recipient->attempts->count(),
                        'max_attempts' => (int) $ta->max_attempts,
                        'selected_attempt_id' => $scoreData['selected_attempt_id'],
                        'has_submitted' => $finalizedAttempts->isNotEmpty(),
                        'feedback_text' => null,
                        'evaluated_at' => $finalizedAttempts->max('submitted_at')?->timezone('Asia/Karachi')->toIso8601String(),
                        'category_included' => $catIncluded,
                    ];
                } else {
                    /** @var TeacherClassAssignment $ca */
                    $ca = $col['_model'];
                    if ($ca->assignment_mode === TeacherClassAssignment::MODE_SELECTED_STUDENTS
                        && !$ca->recipients->contains('student_web_user_id', $studentId)
                        && !$ca->submissions->contains('student_web_user_id', $studentId)
                        && !$ca->evaluations->contains('student_web_user_id', $studentId)) {
                        $cells[$colKey] = [
                            'cell_state' => 'not_assigned',
                            'result_status' => 'not_assigned',
                            'obtained_marks' => null,
                            'max_marks' => $col['max_marks'],
                            'percentage' => null,
                            'has_submitted' => false,
                            'attempts_used' => 0,
                            'max_attempts' => 1,
                            'feedback_text' => null,
                            'evaluated_at' => null,
                            'category_included' => $catIncluded,
                        ];
                        continue;
                    }

                    /** @var TeacherClassAssignmentSubmission|null $sub */
                    $sub = $ca->submissions->firstWhere('student_web_user_id', $studentId);
                    /** @var TeacherClassAssignmentEvaluation|null $eval */
                    $eval = $ca->evaluations->firstWhere('student_web_user_id', $studentId);
                    $hasSubmitted = $sub && $sub->status === TeacherClassAssignmentSubmission::STATUS_SUBMITTED;
                    $isPastDue = $ca->due_at && $ca->due_at->lessThan($now);

                    $evalStatus = $eval?->status ?? 'not_evaluated';
                    $isReleased = $eval && $eval->status === TeacherClassAssignmentEvaluation::STATUS_RELEASED;

                    if (!$hasSubmitted && !$isReleased) {
                        $cellState = ($isPastDue || $ca->status === TeacherClassAssignment::STATUS_CLOSED) ? 'overdue' : 'pending';
                        $pendingCount++;
                    } elseif ($isReleased) {
                        $cellState = 'released';
                    } else {
                        $cellState = 'awaiting_evaluation';
                    }

                    $visibleToViewer = $viewer === 'teacher' || $isReleased;
                    $max = ($eval && $eval->max_marks_snapshot !== null)
                        ? round((float) $eval->max_marks_snapshot, 2)
                        : ($ca->max_marks !== null ? round((float) $ca->max_marks, 2) : null);
                    $obt = ($visibleToViewer && $eval && $eval->obtained_marks !== null)
                        ? round((float) $eval->obtained_marks, 2)
                        : null;
                    $pct = ($obt !== null && $max !== null && $max > 0)
                        ? round(($obt / $max) * 100, 2)
                        : null;

                    // Only RELEASED evaluations contribute to numeric academic totals (never draft/reopened/unsubmitted)!
                    if ($isReleased && $eval->obtained_marks !== null && $max !== null && $max > 0) {
                        if ($col['is_excluded'] || !$catIncluded) {
                            $excludedCount++;
                        } else {
                            $completedCount++;
                            $catTotals['assignment']['obtained'] += (float) $eval->obtained_marks;
                            $catTotals['assignment']['max'] += (float) $max;
                            $catTotals['assignment']['count']++;
                        }
                    }

                    $cells[$colKey] = [
                        'cell_state' => $cellState,
                        'result_status' => $viewer === 'student'
                            ? ($isReleased ? 'released' : ($hasSubmitted ? 'pending_evaluation' : 'not_evaluated'))
                            : $evalStatus,
                        'evaluation_type' => $visibleToViewer ? $eval?->evaluation_type : null,
                        'obtained_marks' => $obt,
                        'max_marks' => $max,
                        'percentage' => $pct,
                        'has_submitted' => $hasSubmitted,
                        'submission_version' => $sub ? (int) $sub->submission_version : 0,
                        'attempts_used' => $hasSubmitted ? 1 : 0,
                        'max_attempts' => 1,
                        'feedback_text' => ($viewer === 'teacher' || $isReleased) ? $eval?->feedback_text : null,
                        'evaluated_at' => $isReleased
                            ? $eval->released_at?->timezone('Asia/Karachi')->toIso8601String()
                            : ($viewer === 'teacher' ? $eval?->updated_at?->timezone('Asia/Karachi')->toIso8601String() : null),
                        'category_included' => $catIncluded,
                    ];
                }
            }

            $rawObtained = round($catTotals['mcq_test']['obtained'] + $catTotals['assignment']['obtained'], 2);
            $rawMax = round($catTotals['mcq_test']['max'] + $catTotals['assignment']['max'], 2);
            $rawPct = $rawMax > 0 ? round(($rawObtained / $rawMax) * 100, 2) : null;

            $weightedPct = null;
            if ($weightingEnabled) {
                $weightedSum = 0.0;
                $activeWeightSum = 0.0;
                foreach ($catTotals as $k => $bucket) {
                    $rule = $rulesByCategory->get($k);
                    if (!$rule || !$rule['is_included'] || $bucket['max'] <= 0 || (float) $rule['weight_percent'] <= 0) {
                        continue;
                    }
                    $catPct = ($bucket['obtained'] / $bucket['max']) * 100;
                    $w = (float) $rule['weight_percent'];
                    $weightedSum += $catPct * $w;
                    $activeWeightSum += $w;
                }
                if ($activeWeightSum > 0) {
                    $weightedPct = round($weightedSum / $activeWeightSum, 2);
                }
            }

            return [
                'student' => [
                    'id' => $studentId,
                    'name' => $enrollment->student?->name ?? 'Student',
                    'email' => $enrollment->student?->email,
                    'enrollment_status' => $enrollment->status,
                ],
                'completed_count' => $completedCount,
                'pending_count' => $pendingCount,
                'excluded_count' => $excludedCount,
                'total_obtained_marks' => $rawObtained,
                'total_available_marks' => $rawMax,
                'raw_percentage' => $rawPct,
                'weighted_percentage' => $weightedPct,
                'overall_percentage' => $weightingEnabled ? $weightedPct : $rawPct,
                'cells' => $cells,
            ];
        });

        $cleanColumns = $filteredAssessments->map(function (array $col) {
            unset($col['_model'], $col['_sort_ts']);
            return $col;
        })->values();

        return [
            'config' => $config,
            'assessments' => $cleanColumns,
            'rows' => $rows->values(),
            'filter_options' => $filterOptions,
        ];
    }

    /**
     * Authoritative retake attempt selector implementing configured policy (highest | latest | average).
     */
    private function selectTestAttemptScore(
        TeacherClassTestAssignment $assignment,
        Collection $finalizedAttempts,
        string $policy
    ): array {
        if ($finalizedAttempts->isEmpty()) {
            return [
                'selected_attempt_id' => null,
                'obtained_marks' => null,
                'max_marks' => $assignment->test ? round((float) $assignment->test->total_marks, 2) : null,
                'percentage' => null,
                'result_released' => false,
                'result_status' => 'not_attempted',
            ];
        }

        /** @var TeacherClassTestAttempt $latestAttempt */
        $latestAttempt = $finalizedAttempts->sortByDesc('attempt_number')->first();
        $isReleased = $this->testLifecycle->canStudentViewMarks($assignment, $latestAttempt);
        $maxMarks = round((float) $latestAttempt->max_marks, 2);

        if ($policy === TeacherClassGradebookConfig::RETAKE_AVERAGE) {
            $avgObtained = round((float) $finalizedAttempts->avg('obtained_marks'), 2);
            $pct = $maxMarks > 0 ? round(($avgObtained / $maxMarks) * 100, 2) : 0.0;

            return [
                'selected_attempt_id' => (int) $latestAttempt->id,
                'obtained_marks' => $avgObtained,
                'max_marks' => $maxMarks,
                'percentage' => $pct,
                'result_released' => $isReleased,
                'result_status' => $isReleased ? 'released' : 'unreleased',
            ];
        }

        /** @var TeacherClassTestAttempt $chosen */
        $chosen = $policy === TeacherClassGradebookConfig::RETAKE_LATEST
            ? $latestAttempt
            : $finalizedAttempts->sortByDesc(fn (TeacherClassTestAttempt $a) => [(float) $a->obtained_marks, (int) $a->attempt_number])->first();

        return [
            'selected_attempt_id' => (int) $chosen->id,
            'obtained_marks' => round((float) $chosen->obtained_marks, 2),
            'max_marks' => round((float) $chosen->max_marks, 2),
            'percentage' => round((float) $chosen->percentage, 2),
            'result_released' => $this->testLifecycle->canStudentViewMarks($assignment, $chosen),
            'result_status' => $this->testLifecycle->canStudentViewMarks($assignment, $chosen) ? 'released' : 'unreleased',
        ];
    }

    private function loadClassConfig(TeacherClass $class): array
    {
        $config = TeacherClassGradebookConfig::query()
            ->where('teacher_class_id', $class->id)
            ->with(['categoryRules', 'studySession'])
            ->first();

        $defaults = [
            TeacherClassGradebookCategoryRule::CAT_MCQ_TEST => ['label' => 'MCQ Examinations', 'weight' => 50.00],
            TeacherClassGradebookCategoryRule::CAT_ASSIGNMENT => ['label' => 'Assignments & Homework', 'weight' => 50.00],
        ];

        $rules = collect(TeacherClassGradebookCategoryRule::CATEGORIES)->map(function (string $key) use ($config, $defaults) {
            $stored = $config?->categoryRules?->firstWhere('category_key', $key);
            return [
                'category_key' => $key,
                'label' => $stored?->label ?? $defaults[$key]['label'],
                'weight_percent' => $stored ? round((float) $stored->weight_percent, 2) : $defaults[$key]['weight'],
                'is_included' => $stored ? (bool) $stored->is_included : true,
                'exclude_ungraded' => $stored ? (bool) $stored->exclude_ungraded : true,
            ];
        })->values()->all();

        return [
            'retake_score_policy' => $config?->retake_score_policy ?? TeacherClassGradebookConfig::RETAKE_HIGHEST,
            'weighting_enabled' => $config ? (bool) $config->weighting_enabled : false,
            'study_session_id' => $config?->study_session_id ? (int) $config->study_session_id : null,
            'study_session_name' => $config?->studySession?->name,
            'effective_from' => $config?->effective_from?->format('Y-m-d'),
            'effective_to' => $config?->effective_to?->format('Y-m-d'),
            'categories' => $rules,
        ];
    }

    private function loadAvailableStudySessions(TeacherClass $class): array
    {
        if (!Schema::hasTable('study_session_tbl')) {
            return [];
        }

        return DB::table('study_session_tbl')
            ->where('class_id', $class->class_id)
            ->where('curriculum_board_id', $class->curriculum_board_id)
            ->where('activate', 1)
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date'])
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'start_date' => $s->start_date,
                'end_date' => $s->end_date,
            ])
            ->values()
            ->all();
    }

    private function resolveEffectiveDateWindow(TeacherClass $class, array $filters, array $config = []): array
    {
        $start = null;
        $end = null;

        $effectiveSessionId = !empty($filters['study_session_id'])
            ? (int) $filters['study_session_id']
            : (!empty($config['study_session_id']) ? (int) $config['study_session_id'] : null);

        if ($effectiveSessionId && Schema::hasTable('study_session_tbl')) {
            $session = DB::table('study_session_tbl')
                ->where('id', $effectiveSessionId)
                ->where('class_id', $class->class_id)
                ->where('curriculum_board_id', $class->curriculum_board_id)
                ->first(['start_date', 'end_date']);
            if ($session) {
                $start = CarbonImmutable::parse($session->start_date, 'Asia/Karachi')->startOfDay();
                $end = CarbonImmutable::parse($session->end_date, 'Asia/Karachi')->endOfDay();
            }
        }

        $dateFrom = $filters['date_from'] ?? ($config['effective_from'] ?? null);
        if (!empty($dateFrom)) {
            try {
                $df = CarbonImmutable::parse((string) $dateFrom, 'Asia/Karachi')->startOfDay();
                $start = ($start && $start->greaterThan($df)) ? $start : $df;
            } catch (\Throwable) {
                // ignore malformed filter string validated at controller
            }
        }

        $dateTo = $filters['date_to'] ?? ($config['effective_to'] ?? null);
        if (!empty($dateTo)) {
            try {
                $dt = CarbonImmutable::parse((string) $dateTo, 'Asia/Karachi')->endOfDay();
                $end = ($end && $end->lessThan($dt)) ? $end : $dt;
            } catch (\Throwable) {
                // ignore
            }
        }

        return [$start, $end];
    }

    private function describeCalculationPolicy(array $config): array
    {
        return [
            'calculation_mode' => $config['weighting_enabled'] ? 'weighted_categories' : 'unweighted_raw_points',
            'weighting_enabled' => (bool) $config['weighting_enabled'],
            'retake_score_policy' => $config['retake_score_policy'],
            'retake_policy' => $config['retake_score_policy'],
            'missing_assessment_rule' => 'Unattempted tests and unsubmitted assignments are excluded from numerators and denominators unless explicitly finalized as zero with an audit reason.',
            'unreleased_result_rule' => 'Draft, reopened, and unreleased evaluations or hidden test results are excluded from student views and published averages.',
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function loadUnitMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids) || !Schema::hasTable('book_unit_tbl')) {
            return [];
        }

        return DB::table('book_unit_tbl')
            ->whereIn('id', $ids)
            ->pluck('unit_name', 'id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (string) $v])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function loadTopicMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids) || !Schema::hasTable('book_unit_topic_tbl')) {
            return [];
        }

        return DB::table('book_unit_topic_tbl')
            ->whereIn('id', $ids)
            ->pluck('topic_name', 'id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (string) $v])
            ->all();
    }
}
