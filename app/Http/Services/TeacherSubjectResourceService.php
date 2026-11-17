<?php

namespace App\Http\Services;

use App\Models\TeacherClass;
use App\Models\TeacherProfile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 7B read-only service providing strictly curriculum-scoped educational resources
 * for an owned, active TeacherClass.
 *
 * Security & Domain Rules:
 * - Curriculum resources (textbooks, units, topics, topic content, SLOs, MCQs, past papers,
 *   model papers) are authorized strictly by the class's (curriculum_board_id, class_id, subject_id) tuple.
 *   Unresolved past papers (curriculum_board_id IS NULL) are never exposed to teachers.
 * - Examination boards (board_tbl) remain strictly separated from curriculum boards (curriculum_board_tbl)
 *   and are surfaced only as historical examination metadata on MCQs and past papers.
 * - MCQ answer keys (is_answer) and explanations (explanation, explanation_um, explanation_video_url)
 *   are NEVER exposed by Stage 7B endpoints.
 * - Raw S3/storage paths (image_path, thumbnail_path, paper_path, scenario_image) are NEVER exposed
 *   in Stage 7B JSON payloads; past paper files are served through authorized proxy endpoints.
 */
class TeacherSubjectResourceService
{
    public const PAST_PAPER_SOURCE_STRUCTURED = 'structured';
    public const PAST_PAPER_SOURCE_CATALOG = 'catalog';

    public const DIFFICULTY_LABELS = [
        1 => 'Easy',
        2 => 'Medium',
        3 => 'Standard',
        4 => 'Challenging',
    ];

    public function __construct(private AwsUploadService $awsUploadService) {}

    /**
     * Resolve and authorize a teacher's class with full curriculum scope.
     */
    public function resolveOwnedActiveClass(TeacherProfile $profile, int $classId): TeacherClass
    {
        $class = TeacherClass::with([
            'academicClass:id,class_name,class_name_um',
            'subject:id,subject_name,subject_name_um',
            'curriculumBoard:id,name',
        ])->find($classId);

        if (!$class) {
            $this->abortJson(404, 'teacher_class_not_found', 'Class not found.');
        }

        if ((int) $class->teacher_profile_id !== (int) $profile->id) {
            $this->abortJson(403, 'forbidden_teacher_class', 'You do not have permission to access this class.');
        }

        if (!$class->is_active) {
            $this->abortJson(403, 'teacher_class_inactive', 'Resources are unavailable because this class is archived.');
        }

        if (
            (int) $class->curriculum_board_id <= 0
            || (int) $class->class_id <= 0
            || (int) $class->subject_id <= 0
        ) {
            $this->abortJson(403, 'class_curriculum_incomplete', 'Class curriculum scope is incomplete.');
        }

        return $class;
    }

    public function presentClassSummary(TeacherClass $class): array
    {
        return [
            'id' => (int) $class->id,
            'name' => $class->name,
            'section' => $class->section !== '' ? $class->section : null,
            'is_active' => (bool) $class->is_active,
            'class' => $class->academicClass ? [
                'id' => (int) $class->academicClass->id,
                'name' => $class->academicClass->class_name,
                'name_um' => $class->academicClass->class_name_um,
            ] : null,
            'subject' => $class->subject ? [
                'id' => (int) $class->subject->id,
                'name' => $class->subject->subject_name,
                'name_um' => $class->subject->subject_name_um,
            ] : null,
            'curriculum_board' => $class->curriculumBoard ? [
                'id' => (int) $class->curriculumBoard->id,
                'name' => $class->curriculumBoard->name,
            ] : null,
        ];
    }

    /**
     * Summary counts and scoped filter options for the class Resource Library.
     */
    public function overview(TeacherClass $class): array
    {
        $books = $this->scopedBooksQuery($class)
            ->select(['b.id', 'b.book_name'])
            ->orderBy('b.id')
            ->get();

        $bookIds = $books->pluck('id')->map(fn ($id) => (int) $id)->all();

        $units = $this->scopedUnitsQuery($class)
            ->select(['u.id', 'u.book_id', 'u.unit_no', 'u.unit_name', 'u.is_alp'])
            ->orderBy('u.book_id')
            ->orderBy('u.unit_no')
            ->orderBy('u.id')
            ->get();

        $topicsCount = $this->scopedTopicsQuery($class)->count('t.id');

        $sloDomains = DB::table('slo_domain_tbl as d')
            ->where('d.activate', 1)
            ->where('d.curriculum_board_id', $class->curriculum_board_id)
            ->where('d.subject_id', $class->subject_id)
            ->select(['d.id', 'd.domain_code', 'd.domain_name', 'd.domain_name_um', 'd.sort_order'])
            ->orderBy('d.sort_order')
            ->orderBy('d.id')
            ->get();

        $slosCount = $this->scopedSloTopicsQuery($class)->count('st.id');
        $mcqCount = $this->scopedMcqQuestionsQuery($class)->count('q.id');
        $modelPapersCount = $this->scopedModelPapersQuery($class)->count('mp.id');

        $structuredPapersCount = $this->scopedStructuredPastPapersQuery($class)->count('tp.id');
        $catalogPapersCount = (int) $this->scopedCatalogPastPapersQuery($class)
            ->selectRaw('COUNT(DISTINCT pp.paper_slug) as total')
            ->value('total');

        $cognitiveDomains = DB::table('cognitive_domain_tbl')
            ->select(['id', 'name', 'short'])
            ->orderBy('id')
            ->get();

        $presentationTypes = DB::table('question_presentation_type_tbl')
            ->where('activate', 1)
            ->select(['id', 'type_name', 'code', 'allows_multiple_mcqs', 'sort_order'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Surface examination metadata filters relevant to this class's past papers and MCQ history.
        $examBoards = DB::table('board_tbl')
            ->where('activate', 1)
            ->select(['id', 'board_name', 'short_name'])
            ->orderBy('board_name')
            ->get();

        $examSessions = DB::table('exam_session_tbl')
            ->where('activate', 1)
            ->select(['id', 'session_name'])
            ->orderBy('id')
            ->get();

        $studyGroups = DB::table('study_group_tbl')
            ->where('activate', 1)
            ->where(function ($q) use ($class) {
                $q->whereNull('class_id')->orWhere('class_id', $class->class_id);
            })
            ->where(function ($q) use ($class) {
                $q->whereNull('curriculum_board_id')->orWhere('curriculum_board_id', $class->curriculum_board_id);
            })
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();

        $examYears = collect()
            ->merge(
                $this->scopedStructuredPastPapersQuery($class)
                    ->whereNotNull('tp.year')
                    ->distinct()
                    ->pluck('tp.year')
            )
            ->merge(
                $this->scopedCatalogPastPapersQuery($class)
                    ->whereNotNull('pp.year')
                    ->distinct()
                    ->pluck('pp.year')
            )
            ->merge(
                $this->scopedMcqQuestionsQuery($class)
                    ->join('exam_question_board_tbl as eqb', function ($join) {
                        $join->on('eqb.question_id', '=', 'q.id')->where('eqb.activate', 1);
                    })
                    ->whereNotNull('eqb.year')
                    ->distinct()
                    ->pluck('eqb.year')
            )
            ->filter(fn ($year) => (int) $year > 0)
            ->map(fn ($year) => (int) $year)
            ->unique()
            ->sortDesc()
            ->values();

        return [
            'class' => $this->presentClassSummary($class),
            'counts' => [
                'textbooks' => count($bookIds),
                'units' => $units->count(),
                'topics' => (int) $topicsCount,
                'slo_domains' => $sloDomains->count(),
                'slos' => (int) $slosCount,
                'mcq_questions' => (int) $mcqCount,
                'model_papers' => (int) $modelPapersCount,
                'past_papers_structured' => (int) $structuredPapersCount,
                'past_papers_catalog' => $catalogPapersCount,
                'past_papers_total' => (int) $structuredPapersCount + $catalogPapersCount,
            ],
            'filters' => [
                'books' => $books->map(fn ($b) => [
                    'id' => (int) $b->id,
                    'book_name' => $b->book_name,
                ])->values(),
                'units' => $units->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'book_id' => (int) $u->book_id,
                    'unit_no' => $u->unit_no !== null ? (int) $u->unit_no : null,
                    'unit_name' => $u->unit_name,
                    'is_alp' => (bool) $u->is_alp,
                ])->values(),
                'slo_domains' => $sloDomains->map(fn ($d) => [
                    'id' => (int) $d->id,
                    'domain_code' => $d->domain_code,
                    'domain_name' => $d->domain_name,
                    'domain_name_um' => $d->domain_name_um,
                ])->values(),
                'cognitive_domains' => $cognitiveDomains->map(fn ($cd) => [
                    'id' => (int) $cd->id,
                    'name' => $cd->name,
                    'short' => $cd->short,
                ])->values(),
                'presentation_types' => $presentationTypes->map(fn ($pt) => [
                    'id' => (int) $pt->id,
                    'name' => $pt->type_name,
                    'code' => $pt->code,
                ])->values(),
                'difficulties' => collect(self::DIFFICULTY_LABELS)->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                ])->values(),
                'exam_boards' => $examBoards->map(fn ($b) => [
                    'id' => (int) $b->id,
                    'name' => $b->board_name,
                    'short_name' => $b->short_name,
                ])->values(),
                'exam_sessions' => $examSessions->map(fn ($s) => [
                    'id' => (int) $s->id,
                    'name' => $s->session_name,
                ])->values(),
                'study_groups' => $studyGroups->map(fn ($g) => [
                    'id' => (int) $g->id,
                    'name' => $g->name,
                ])->values(),
                'exam_years' => $examYears,
            ],
        ];
    }

    /**
     * List textbooks matching the class's (curriculum_board_id, class_id, subject_id).
     */
    public function textbooks(TeacherClass $class, array $filters = []): array
    {
        $query = $this->scopedBooksQuery($class)
            ->leftJoin('book_unit_tbl as u', function ($join) {
                $join->on('u.book_id', '=', 'b.id')->where('u.activate', 1);
            })
            ->select([
                'b.id',
                'b.book_name',
                'b.curriculum_board_id',
                'b.class_id',
                'b.subject_id',
                DB::raw('COUNT(DISTINCT u.id) as units_count'),
            ])
            ->groupBy('b.id', 'b.book_name', 'b.curriculum_board_id', 'b.class_id', 'b.subject_id')
            ->orderBy('b.id');

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where('b.book_name', 'like', $term);
        }

        $books = $query->get();
        $bookIds = $books->pluck('id')->map(fn ($id) => (int) $id)->all();

        $topicCountsByBook = $this->countByBook($bookIds, 'book_unit_topic_tbl', 't', 'u.book_id');
        $mcqCountsByBook = $this->countMcqByBook($class, $bookIds);

        return [
            'class' => $this->presentClassSummary($class),
            'data' => $books->map(fn ($book) => [
                'id' => (int) $book->id,
                'book_name' => $book->book_name,
                'curriculum_board_id' => (int) $book->curriculum_board_id,
                'class_id' => (int) $book->class_id,
                'subject_id' => (int) $book->subject_id,
                'units_count' => (int) $book->units_count,
                'topics_count' => (int) ($topicCountsByBook[(int) $book->id] ?? 0),
                'mcq_count' => (int) ($mcqCountsByBook[(int) $book->id] ?? 0),
            ])->values(),
        ];
    }

    /**
     * Detailed textbook outline with ordered units.
     */
    public function textbookDetail(TeacherClass $class, int $bookId): array
    {
        $book = $this->assertScopedBook($class, $bookId);
        $units = $this->loadUnitsTree($class, $bookId);

        return [
            'class' => $this->presentClassSummary($class),
            'book' => [
                'id' => (int) $book->id,
                'book_name' => $book->book_name,
                'curriculum_board_id' => (int) $book->curriculum_board_id,
                'class_id' => (int) $book->class_id,
                'subject_id' => (int) $book->subject_id,
            ],
            'units' => $units,
        ];
    }

    /**
     * Units & topics tree (with unit/topic SLOs and content-type badges) scoped to class curriculum.
     */
    public function units(TeacherClass $class, array $filters = []): array
    {
        $bookId = isset($filters['book_id']) ? (int) $filters['book_id'] : null;
        if ($bookId) {
            $this->assertScopedBook($class, $bookId);
        }

        $search = !empty($filters['search']) ? trim((string) $filters['search']) : null;
        $units = $this->loadUnitsTree($class, $bookId, $search);

        return [
            'class' => $this->presentClassSummary($class),
            'data' => $units,
        ];
    }

    /**
     * Topic content structures & mapped SLOs for a single topic inside the class curriculum.
     */
    public function topicContent(TeacherClass $class, int $topicId): array
    {
        $topic = $this->assertScopedTopic($class, $topicId);

        $structures = DB::table('topic_content_structure_tbl as tcs')
            ->join('topic_content_type_tbl as tct', 'tct.id', '=', 'tcs.topic_content_type_id')
            ->where('tcs.topic_id', $topicId)
            ->where('tct.activate', 1)
            ->select([
                'tcs.id',
                'tcs.topic_content_type_id',
                'tct.name',
                'tct.name_um',
                'tct.has_child',
                'tct.is_mcq',
            ])
            ->orderBy('tcs.id')
            ->get();

        $topicSlos = DB::table('topic_slo_topic_tbl as tst')
            ->join('slo_topic_tbl as st', 'st.id', '=', 'tst.slo_topic_id')
            ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
            ->where('tst.topic_id', $topicId)
            ->where('st.activate', 1)
            ->where('sd.activate', 1)
            ->where('sd.curriculum_board_id', $class->curriculum_board_id)
            ->where('sd.subject_id', $class->subject_id)
            ->where('st.class_id', $class->class_id)
            ->select($this->sloSelectColumns())
            ->orderBy('sd.sort_order')
            ->orderBy('st.sort_order')
            ->orderBy('st.id')
            ->get();

        $unitSlos = DB::table('unit_slo_topic_tbl as ust')
            ->join('slo_topic_tbl as st', 'st.id', '=', 'ust.slo_topic_id')
            ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
            ->where('ust.unit_id', $topic->unit_id)
            ->where('st.activate', 1)
            ->where('sd.activate', 1)
            ->where('sd.curriculum_board_id', $class->curriculum_board_id)
            ->where('sd.subject_id', $class->subject_id)
            ->where('st.class_id', $class->class_id)
            ->select($this->sloSelectColumns())
            ->orderBy('sd.sort_order')
            ->orderBy('st.sort_order')
            ->orderBy('st.id')
            ->get();

        $mcqCount = (int) $this->scopedMcqQuestionsQuery($class)
            ->where('q.topic_id', $topicId)
            ->count('q.id');

        return [
            'class' => $this->presentClassSummary($class),
            'topic' => [
                'id' => (int) $topic->id,
                'topic_no' => $topic->topic_no,
                'topic_name' => $topic->topic_name,
                'topic_name_um' => $topic->topic_name_um,
                'slo_no' => $topic->slo_no,
                'is_alp' => (bool) $topic->is_alp,
                'unit' => [
                    'id' => (int) $topic->unit_id,
                    'unit_no' => $topic->unit_no !== null ? (int) $topic->unit_no : null,
                    'unit_name' => $topic->unit_name,
                ],
                'book' => [
                    'id' => (int) $topic->book_id,
                    'book_name' => $topic->book_name,
                ],
                'mcq_count' => $mcqCount,
            ],
            'content_structures' => $structures->map(fn ($s) => [
                'id' => (int) $s->id,
                'content_type_id' => (int) $s->topic_content_type_id,
                'name' => $s->name,
                'name_um' => $s->name_um,
                'has_child' => (bool) $s->has_child,
                'is_mcq' => (bool) $s->is_mcq,
            ])->values(),
            'topic_slos' => $topicSlos->map(fn ($s) => $this->presentSloRow($s))->values(),
            'unit_slos' => $unitSlos->map(fn ($s) => $this->presentSloRow($s))->values(),
        ];
    }

    /**
     * Paginated SLO list strictly scoped to the class's (curriculum_board_id, class_id, subject_id).
     */
    public function slos(TeacherClass $class, array $filters = []): array
    {
        if (!empty($filters['slo_domain_id'])) {
            $this->assertScopedSloDomain($class, (int) $filters['slo_domain_id']);
        }
        if (!empty($filters['unit_id'])) {
            $this->assertScopedUnit($class, (int) $filters['unit_id']);
        }
        if (!empty($filters['topic_id'])) {
            $this->assertScopedTopic($class, (int) $filters['topic_id']);
        }

        $query = $this->scopedSloTopicsQuery($class)
            ->select($this->sloSelectColumns());

        if (!empty($filters['slo_domain_id'])) {
            $query->where('st.slo_domain_id', (int) $filters['slo_domain_id']);
        }

        if (!empty($filters['unit_id'])) {
            $unitId = (int) $filters['unit_id'];
            $query->whereExists(function ($sub) use ($unitId) {
                $sub->select(DB::raw(1))
                    ->from('unit_slo_topic_tbl as ust')
                    ->whereColumn('ust.slo_topic_id', 'st.id')
                    ->where('ust.unit_id', $unitId);
            });
        }

        if (!empty($filters['topic_id'])) {
            $topicId = (int) $filters['topic_id'];
            $query->where(function ($outer) use ($topicId) {
                $outer->whereExists(function ($sub) use ($topicId) {
                    $sub->select(DB::raw(1))
                        ->from('topic_slo_topic_tbl as tst')
                        ->whereColumn('tst.slo_topic_id', 'st.id')
                        ->where('tst.topic_id', $topicId);
                })->orWhereExists(function ($sub) use ($topicId) {
                    $sub->select(DB::raw(1))
                        ->from('unit_slo_topic_tbl as ust')
                        ->join('book_unit_topic_tbl as t2', 't2.unit_id', '=', 'ust.unit_id')
                        ->whereColumn('ust.slo_topic_id', 'st.id')
                        ->where('t2.id', $topicId);
                });
            });
        }

        if (!empty($filters['cognitive_level'])) {
            $query->where('st.cognitive_level', trim((string) $filters['cognitive_level']));
        }

        if (isset($filters['is_alp']) && $filters['is_alp'] !== '') {
            $query->where('st.is_alp', filter_var($filters['is_alp'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('st.slo_code', 'like', $term)
                    ->orWhere('st.slo_title', 'like', $term)
                    ->orWhere('st.slo_title_um', 'like', $term)
                    ->orWhere('st.benchmark_text', 'like', $term)
                    ->orWhere('sd.domain_name', 'like', $term);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $paginator = $query
            ->orderBy('sd.sort_order')
            ->orderBy('st.sort_order')
            ->orderBy('st.id')
            ->paginate($perPage);

        $items = collect($paginator->items());
        $sloIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        $unitsBySlo = $this->loadUnitsMappedToSlos($class, $sloIds);
        $topicsBySlo = $this->loadTopicsMappedToSlos($class, $sloIds);

        return [
            'class' => $this->presentClassSummary($class),
            'data' => $items->map(function ($row) use ($unitsBySlo, $topicsBySlo) {
                $presented = $this->presentSloRow($row);
                $presented['units'] = $unitsBySlo[(int) $row->id] ?? [];
                $presented['topics'] = $topicsBySlo[(int) $row->id] ?? [];

                return $presented;
            })->values(),
            'pagination' => $this->presentPagination($paginator),
        ];
    }

    /**
     * Read-only MCQ question bank strictly scoped to class curriculum.
     * NEVER exposes answer keys (is_answer) or explanations (explanation, explanation_um, explanation_video_url).
     */
    public function questions(TeacherClass $class, array $filters = []): array
    {
        if (!empty($filters['book_id'])) {
            $this->assertScopedBook($class, (int) $filters['book_id']);
        }
        if (!empty($filters['unit_id'])) {
            $this->assertScopedUnit($class, (int) $filters['unit_id']);
        }
        if (!empty($filters['topic_id'])) {
            $this->assertScopedTopic($class, (int) $filters['topic_id']);
        }
        if (!empty($filters['slo_topic_id'])) {
            $this->assertScopedSloTopic($class, (int) $filters['slo_topic_id']);
        }

        $query = $this->scopedMcqQuestionsQuery($class)
            ->leftJoin('cognitive_domain_tbl as cd', 'cd.id', '=', 'q.cognitive_domain')
            ->leftJoin('question_presentation_type_tbl as qpt', 'qpt.id', '=', 'q.question_presentation_type_id')
            ->select([
                'q.id',
                'q.question',
                'q.question_um',
                'q.question_lang',
                'q.question_um_lang',
                'q.marks',
                'q.difficulty',
                'q.explanation',
                'q.explanation_um',
                'q.is_alp_question',
                'q.exercise_question',
                'q.has_diagram',
                'q.topic_content',
                'q.topic_id',
                'q.cognitive_domain as cognitive_domain_id',
                'cd.name as cognitive_domain_name',
                'cd.short as cognitive_domain_short',
                'q.question_presentation_type_id',
                'qpt.type_name as presentation_type_name',
                'qpt.code as presentation_type_code',
                'q.scenario_group_id',
                'q.scenario_question_order',
                'q.slo_topic_id',
                't.topic_no',
                't.topic_name',
                't.topic_name_um',
                'u.id as resolved_unit_id',
                'u.unit_no',
                'u.unit_name',
                'b.id as resolved_book_id',
                'b.book_name',
            ]);

        if (!empty($filters['book_id'])) {
            $query->where('b.id', (int) $filters['book_id']);
        }
        if (!empty($filters['unit_id'])) {
            $query->where('u.id', (int) $filters['unit_id']);
        }
        if (!empty($filters['topic_id'])) {
            $query->where('t.id', (int) $filters['topic_id']);
        }
        if (!empty($filters['slo_topic_id'])) {
            $sloTopicId = (int) $filters['slo_topic_id'];
            $query->where(function ($outer) use ($sloTopicId) {
                $outer->where('q.slo_topic_id', $sloTopicId)
                    ->orWhereExists(function ($sub) use ($sloTopicId) {
                        $sub->select(DB::raw(1))
                            ->from('topic_slo_topic_tbl as tst')
                            ->whereColumn('tst.topic_id', 't.id')
                            ->where('tst.slo_topic_id', $sloTopicId);
                    })
                    ->orWhereExists(function ($sub) use ($sloTopicId) {
                        $sub->select(DB::raw(1))
                            ->from('unit_slo_topic_tbl as ust')
                            ->whereColumn('ust.unit_id', 'u.id')
                            ->where('ust.slo_topic_id', $sloTopicId);
                    });
            });
        }
        if (!empty($filters['difficulty'])) {
            $query->where('q.difficulty', (int) $filters['difficulty']);
        }
        if (!empty($filters['cognitive_domain'])) {
            $cog = trim((string) $filters['cognitive_domain']);
            if (is_numeric($cog)) {
                $query->where('q.cognitive_domain', (int) $cog);
            } else {
                $query->where(function ($cq) use ($cog) {
                    $cq->where('cd.short', $cog)->orWhere('cd.name', $cog);
                });
            }
        }
        if (!empty($filters['mcq_format'])) {
            $fmt = strtolower(trim((string) $filters['mcq_format']));
            if (is_numeric($fmt)) {
                $query->where('q.question_presentation_type_id', (int) $fmt);
            } elseif ($fmt === 'straight') {
                $query->where(function ($fq) {
                    $fq->where('qpt.code', 'st')
                        ->orWhereNull('q.scenario_group_id');
                });
            } elseif ($fmt === 'stimulus' || $fmt === 'scenario') {
                $query->where(function ($fq) {
                    $fq->where('qpt.code', 'stimulus')
                        ->orWhereNotNull('q.scenario_group_id');
                });
            }
        }

        if (!empty($filters['board_id']) || !empty($filters['session_id']) || !empty($filters['year']) || !empty($filters['group_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('exam_question_board_tbl as eqb')
                    ->whereColumn('eqb.question_id', 'q.id')
                    ->where('eqb.activate', 1);

                if (!empty($filters['board_id'])) {
                    $sub->where('eqb.board_id', (int) $filters['board_id']);
                }
                if (!empty($filters['session_id'])) {
                    $sub->where('eqb.session_id', (int) $filters['session_id']);
                }
                if (!empty($filters['year'])) {
                    $sub->where('eqb.year', (int) $filters['year']);
                }
                if (!empty($filters['group_id'])) {
                    $sub->where('eqb.group_id', (int) $filters['group_id']);
                }
            });
        }

        if (isset($filters['is_alp']) && $filters['is_alp'] !== '') {
            $query->where('q.is_alp_question', filter_var($filters['is_alp'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($sq) use ($term) {
                $sq->where('q.question', 'like', $term)
                    ->orWhere('q.question_um', 'like', $term)
                    ->orWhere('t.topic_name', 'like', $term)
                    ->orWhere('u.unit_name', 'like', $term);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $matchedRows = (clone $query)
            ->select([
                'q.id',
                'q.scenario_group_id',
                'q.scenario_question_order',
                'u.unit_no',
                't.topic_no',
            ])
            ->distinct()
            ->orderBy('u.unit_no')
            ->orderBy('t.topic_no')
            ->orderBy('q.id')
            ->get();

        if ($matchedRows->isEmpty()) {
            return [
                'class' => $this->presentClassSummary($class),
                'policy' => [
                    'read_only' => true,
                    'answer_keys_exposed' => true,
                    'explanations_exposed' => true,
                ],
                'summary' => [
                    'total_units' => 0,
                    'standalone_count' => 0,
                    'stimulus_group_count' => 0,
                    'total_eligible_mcqs' => 0,
                ],
                'units' => [],
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'total_units' => 0,
                    'total_mcqs' => 0,
                    'per_page' => $perPage,
                    'current_page' => 1,
                    'last_page' => 1,
                    'from' => 0,
                    'to' => 0,
                ],
            ];
        }

        $activeGroupIds = DB::table('question_scenario_groups_tbl')
            ->where('activate', 1)
            ->whereIn('id', $matchedRows->pluck('scenario_group_id')->filter()->unique())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $activeGroupLookup = array_flip($activeGroupIds);

        $standaloneUnits = [];
        $groupOrder = [];
        $seenGroupIds = [];

        foreach ($matchedRows as $row) {
            $gid = (int) $row->scenario_group_id;
            if ($gid > 0 && isset($activeGroupLookup[$gid])) {
                if (!isset($seenGroupIds[$gid])) {
                    $seenGroupIds[$gid] = true;
                    $groupOrder[] = [
                        'gid' => $gid,
                        'unit_no' => $row->unit_no ?? 0,
                        'topic_no' => $row->topic_no ?? 0,
                    ];
                }
            } else {
                $qid = (int) $row->id;
                $standaloneUnits[] = [
                    'unit_key' => 'q:' . $qid,
                    'item_type' => 'standalone',
                    'question_ids' => [$qid],
                    'scenario_group_id' => null,
                ];
            }
        }

        $groupUnits = [];
        foreach ($groupOrder as $gInfo) {
            $gid = $gInfo['gid'];
            $allGroupMembers = $this->scopedMcqQuestionsQuery($class)
                ->where('q.scenario_group_id', $gid)
                ->select(['q.id', 'q.scenario_question_order'])
                ->distinct()
                ->orderByRaw('COALESCE(q.scenario_question_order, 0) ASC')
                ->orderBy('q.id')
                ->get();

            $memberIds = $allGroupMembers->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($memberIds === []) {
                continue;
            }

            // Apply 4-MCQ rule:
            // More than 4: select 4 questions using deterministic seed based on gid so selection is stable across refreshes
            if (count($memberIds) > 4) {
                mt_srand($gid);
                $shuffled = $memberIds;
                for ($i = count($shuffled) - 1; $i > 0; $i--) {
                    $j = mt_rand(0, $i);
                    [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
                }
                mt_srand();
                $selectedKeys = array_slice($shuffled, 0, 4);
                $selectedMap = array_flip($selectedKeys);
                $memberIds = array_values(array_filter($memberIds, fn ($id) => isset($selectedMap[$id])));
            }

            $groupUnits[] = [
                'unit_key' => 'g:' . $gid,
                'item_type' => 'stimulus_group',
                'scenario_group_id' => $gid,
                'question_ids' => $memberIds,
            ];
        }

        $allUnits = array_merge($standaloneUnits, $groupUnits);
        $totalUnits = count($allUnits);
        $standaloneCount = count($standaloneUnits);
        $stimulusGroupCount = count($groupUnits);
        $totalEligibleMcqs = array_sum(array_map(fn ($u) => count($u['question_ids']), $allUnits));

        // Assign cumulative starting MCQ numbers across all units so numbering is continuous and stable
        $runningMcqNumber = 1;
        foreach ($allUnits as $idx => $u) {
            $allUnits[$idx]['start_question_number'] = $runningMcqNumber;
            $runningMcqNumber += count($u['question_ids']);
        }

        $offset = ($page - 1) * $perPage;
        $pageUnits = array_slice($allUnits, $offset, $perPage);

        $pageQuestionIds = [];
        foreach ($pageUnits as $pu) {
            foreach ($pu['question_ids'] as $qid) {
                $pageQuestionIds[] = $qid;
            }
        }

        $rows = (clone $query)
            ->whereIn('q.id', $pageQuestionIds)
            ->get();

        $questionIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $scenarioGroupIds = $rows->pluck('scenario_group_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $directSloIds = $rows->pluck('slo_topic_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $topicIds = $rows->pluck('topic_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $unitIds = $rows->pluck('resolved_unit_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();

        $optionsByQuestion = $this->loadSafeOptionsForQuestions($questionIds, true);
        $scenariosById = $this->loadSafeScenarioGroups($scenarioGroupIds);
        $boardLinksByQuestion = $this->loadBoardLinksForQuestions($questionIds);
        $sloIndex = $this->loadQuestionSloLookup($class, $directSloIds, $topicIds, $unitIds);

        $presentedMap = $rows->mapWithKeys(function ($q) use ($optionsByQuestion, $scenariosById, $boardLinksByQuestion, $sloIndex) {
            $qid = (int) $q->id;
            $directSlo = $q->slo_topic_id ? ($sloIndex['by_id'][(int) $q->slo_topic_id] ?? null) : null;
            $topicSlos = $sloIndex['by_topic'][(int) $q->topic_id] ?? [];
            $unitSlos = $sloIndex['by_unit'][(int) $q->resolved_unit_id] ?? [];

            $mergedSlos = collect(array_filter([$directSlo]))
                ->merge($topicSlos)
                ->merge($unitSlos)
                ->unique('id')
                ->values()
                ->all();

            return [$qid => [
                'id' => $qid,
                'question' => $q->question,
                'question_um' => $q->question_um,
                'question_lang' => (int) $q->question_lang,
                'question_um_lang' => (int) $q->question_um_lang,
                'marks' => $q->marks !== null ? (int) $q->marks : 1,
                'difficulty' => $q->difficulty !== null ? (int) $q->difficulty : null,
                'difficulty_label' => self::DIFFICULTY_LABELS[(int) $q->difficulty] ?? null,
                'is_alp_question' => (bool) $q->is_alp_question,
                'exercise_question' => (bool) $q->exercise_question,
                'has_diagram' => (bool) $q->has_diagram,
                'explanation' => $q->explanation,
                'explanation_um' => $q->explanation_um,
                'has_explanation' => !empty($q->explanation) || !empty($q->explanation_um),
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
                'scenario_group' => $q->scenario_group_id
                    ? ($scenariosById[(int) $q->scenario_group_id] ?? null)
                    : null,
                'scenario_question_order' => $q->scenario_question_order !== null ? (int) $q->scenario_question_order : null,
                'book' => [
                    'id' => (int) $q->resolved_book_id,
                    'book_name' => $q->book_name,
                ],
                'unit' => [
                    'id' => (int) $q->resolved_unit_id,
                    'unit_no' => $q->unit_no !== null ? (int) $q->unit_no : null,
                    'unit_name' => $q->unit_name,
                ],
                'topic' => [
                    'id' => (int) $q->topic_id,
                    'topic_no' => $q->topic_no,
                    'topic_name' => $q->topic_name,
                    'topic_name_um' => $q->topic_name_um,
                ],
                'slos' => $mergedSlos,
                'options' => $optionsByQuestion[$qid] ?? [],
                'board_links' => $boardLinksByQuestion[$qid] ?? [],
            ]];
        });

        $hydratedUnits = [];
        $flatQuestions = [];

        foreach ($pageUnits as $pu) {
            if ($pu['item_type'] === 'standalone') {
                $q = $presentedMap->get($pu['question_ids'][0]);
                if ($q) {
                    $hydratedUnits[] = [
                        'unit_key' => $pu['unit_key'],
                        'item_type' => 'standalone',
                        'question_count' => 1,
                        'start_question_number' => $pu['start_question_number'] ?? 1,
                        'question' => $q,
                    ];
                    $flatQuestions[] = $q;
                }
                continue;
            }

            $gid = $pu['scenario_group_id'];
            $scenarioQuestions = collect($pu['question_ids'])
                ->map(fn ($qid) => $presentedMap->get($qid))
                ->filter()
                ->values();

            if ($scenarioQuestions->isEmpty()) {
                continue;
            }

            $firstQ = $scenarioQuestions->first();
            $scenario = $scenariosById[$gid] ?? ($firstQ['scenario_group'] ?? null);

            $hydratedUnits[] = [
                'unit_key' => $pu['unit_key'],
                'item_type' => 'stimulus_group',
                'scenario_group_id' => $gid,
                'scenario_group' => $scenario,
                'question_count' => $scenarioQuestions->count(),
                'start_question_number' => $pu['start_question_number'] ?? 1,
                'unit' => $firstQ['unit'] ?? null,
                'topic' => $firstQ['topic'] ?? null,
                'questions' => $scenarioQuestions->all(),
            ];

            foreach ($scenarioQuestions as $sq) {
                $flatQuestions[] = $sq;
            }
        }

        $pageMcqsCount = count($flatQuestions);
        $fromMcq = !empty($pageUnits) ? ($pageUnits[0]['start_question_number'] ?? 1) : 0;
        $toMcq = !empty($pageUnits) ? ($fromMcq + $pageMcqsCount - 1) : 0;

        return [
            'class' => $this->presentClassSummary($class),
            'policy' => [
                'read_only' => true,
                'answer_keys_exposed' => true,
                'explanations_exposed' => true,
            ],
            'summary' => [
                'total_units' => $totalUnits,
                'standalone_count' => $standaloneCount,
                'stimulus_group_count' => $stimulusGroupCount,
                'total_eligible_mcqs' => $totalEligibleMcqs,
            ],
            'units' => $hydratedUnits,
            'data' => $flatQuestions,
            'pagination' => [
                'total' => $totalUnits,
                'total_units' => $totalUnits,
                'total_mcqs' => $totalEligibleMcqs,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($totalUnits / $perPage)),
                'from' => $totalUnits > 0 ? $offset + 1 : 0,
                'to' => min($offset + count($pageUnits), $totalUnits),
                'from_mcq' => $fromMcq,
                'to_mcq' => $toMcq,
                'page_mcqs_count' => $pageMcqsCount,
            ],
        ];
    }

    /**
     * Unified list of past papers (both structured tst_past_papers and catalog past_paper_tbl)
     * strictly scoped to the class's (curriculum_board_id, class_id, subject_id),
     * preserving examination-board metadata without conflating it with curriculum ownership.
     */
    public function pastPapers(TeacherClass $class, array $filters = []): array
    {
        $source = $filters['source'] ?? 'all';
        $boardId = !empty($filters['board_id']) ? (int) $filters['board_id'] : null;
        $sessionId = !empty($filters['session_id']) ? (int) $filters['session_id'] : null;
        $year = !empty($filters['year']) ? (int) $filters['year'] : null;
        $group = isset($filters['group']) && $filters['group'] !== '' ? (string) $filters['group'] : null;
        $search = !empty($filters['search']) ? trim((string) $filters['search']) : null;

        $items = collect();

        if ($source === 'all' || $source === self::PAST_PAPER_SOURCE_STRUCTURED) {
            $sq = $this->scopedStructuredPastPapersQuery($class)
                ->leftJoin('board_tbl as eb', 'eb.id', '=', 'tp.board_id')
                ->leftJoin('curriculum_board_tbl as cb', 'cb.id', '=', 'tp.curriculum_board_id')
                ->leftJoin('exam_session_tbl as es', 'es.id', '=', 'tp.session_id')
                ->leftJoin('tst_past_paper_pages as tpp', function ($join) {
                    $join->on('tpp.past_paper_id', '=', 'tp.id')->where('tpp.is_active', 1);
                })
                ->select([
                    'tp.id',
                    'tp.paper_title',
                    'tp.paper_slug',
                    'tp.curriculum_board_id',
                    'cb.name as curriculum_board_name',
                    'tp.board_id',
                    'eb.board_name',
                    'eb.short_name as board_short_name',
                    'tp.session_id',
                    'es.session_name',
                    'tp.group',
                    'tp.year',
                    DB::raw('COUNT(tpp.id) as pages_count'),
                ])
                ->groupBy(
                    'tp.id',
                    'tp.paper_title',
                    'tp.paper_slug',
                    'tp.curriculum_board_id',
                    'cb.name',
                    'tp.board_id',
                    'eb.board_name',
                    'eb.short_name',
                    'tp.session_id',
                    'es.session_name',
                    'tp.group',
                    'tp.year'
                );

            if ($boardId) {
                $sq->where('tp.board_id', $boardId);
            }
            if ($sessionId) {
                $sq->where('tp.session_id', $sessionId);
            }
            if ($year) {
                $sq->where('tp.year', $year);
            }
            if ($group !== null) {
                $sq->where('tp.group', $group);
            }
            if ($search) {
                $term = '%' . $search . '%';
                $sq->where(function ($q) use ($term) {
                    $q->where('tp.paper_title', 'like', $term)
                        ->orWhere('tp.paper_slug', 'like', $term)
                        ->orWhere('eb.board_name', 'like', $term);
                });
            }

            foreach ($sq->get() as $row) {
                $items->push([
                    'source' => self::PAST_PAPER_SOURCE_STRUCTURED,
                    'identifier' => (string) $row->id,
                    'id' => (int) $row->id,
                    'title' => $row->paper_title,
                    'slug' => $row->paper_slug,
                    'year' => $row->year !== null ? (int) $row->year : null,
                    'group' => $row->group,
                    'pages_count' => (int) $row->pages_count,
                    'curriculum_board' => $row->curriculum_board_id ? [
                        'id' => (int) $row->curriculum_board_id,
                        'name' => $row->curriculum_board_name,
                    ] : null,
                    'exam_board' => $row->board_id ? [
                        'id' => (int) $row->board_id,
                        'name' => $row->board_name,
                        'short_name' => $row->board_short_name,
                    ] : null,
                    'exam_session' => $row->session_id ? [
                        'id' => (int) $row->session_id,
                        'name' => $row->session_name,
                    ] : null,
                ]);
            }
        }

        if ($source === 'all' || $source === self::PAST_PAPER_SOURCE_CATALOG) {
            $cq = $this->scopedCatalogPastPapersQuery($class)
                ->leftJoin('board_tbl as eb', 'eb.id', '=', 'pp.board_id')
                ->leftJoin('curriculum_board_tbl as cb', 'cb.id', '=', 'pp.curriculum_board_id')
                ->leftJoin('exam_session_tbl as es', 'es.id', '=', 'pp.session_id')
                ->select([
                    'pp.paper_slug',
                    DB::raw('MIN(pp.id) as first_id'),
                    DB::raw('MAX(pp.paper_name) as paper_name'),
                    DB::raw('MAX(pp.curriculum_board_id) as curriculum_board_id'),
                    DB::raw('MAX(cb.name) as curriculum_board_name'),
                    DB::raw('MAX(pp.board_id) as board_id'),
                    DB::raw('MAX(eb.board_name) as board_name'),
                    DB::raw('MAX(eb.short_name) as board_short_name'),
                    DB::raw('MAX(pp.session_id) as session_id'),
                    DB::raw('MAX(es.session_name) as session_name'),
                    DB::raw('MAX(pp.`group`) as paper_group'),
                    DB::raw('MAX(pp.year) as year'),
                    DB::raw('COUNT(pp.id) as pages_count'),
                ])
                ->groupBy('pp.paper_slug');

            if ($boardId) {
                $cq->where('pp.board_id', $boardId);
            }
            if ($sessionId) {
                $cq->where('pp.session_id', $sessionId);
            }
            if ($year) {
                $cq->where('pp.year', $year);
            }
            if ($group !== null) {
                $cq->where('pp.group', $group);
            }
            if ($search) {
                $term = '%' . $search . '%';
                $cq->where(function ($q) use ($term) {
                    $q->where('pp.paper_name', 'like', $term)
                        ->orWhere('pp.paper_slug', 'like', $term)
                        ->orWhere('eb.board_name', 'like', $term);
                });
            }

            foreach ($cq->get() as $row) {
                $items->push([
                    'source' => self::PAST_PAPER_SOURCE_CATALOG,
                    'identifier' => (string) $row->paper_slug,
                    'id' => (int) $row->first_id,
                    'title' => $row->paper_name,
                    'slug' => $row->paper_slug,
                    'year' => $row->year !== null ? (int) $row->year : null,
                    'group' => $row->paper_group,
                    'pages_count' => (int) $row->pages_count,
                    'curriculum_board' => $row->curriculum_board_id ? [
                        'id' => (int) $row->curriculum_board_id,
                        'name' => $row->curriculum_board_name,
                    ] : null,
                    'exam_board' => $row->board_id ? [
                        'id' => (int) $row->board_id,
                        'name' => $row->board_name,
                        'short_name' => $row->board_short_name,
                    ] : null,
                    'exam_session' => $row->session_id ? [
                        'id' => (int) $row->session_id,
                        'name' => $row->session_name,
                    ] : null,
                ]);
            }
        }

        $sorted = $items->sort(function (array $a, array $b) {
            $yearCmp = ((int) ($b['year'] ?? 0)) <=> ((int) ($a['year'] ?? 0));
            if ($yearCmp !== 0) {
                return $yearCmp;
            }
            if ($a['source'] !== $b['source']) {
                return $a['source'] === self::PAST_PAPER_SOURCE_STRUCTURED ? -1 : 1;
            }

            return strcmp((string) $a['title'], (string) $b['title']);
        })->values();

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'class' => $this->presentClassSummary($class),
            'data' => $slice,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Detail + proxied page descriptors for a single past paper within the class's (curriculum_board_id, class_id, subject_id).
     */
    public function pastPaperDetail(TeacherClass $class, string $source, string $identifier): array
    {
        if ($source === self::PAST_PAPER_SOURCE_STRUCTURED) {
            $paperId = (int) $identifier;
            $paper = DB::table('tst_past_papers as tp')
                ->leftJoin('board_tbl as eb', 'eb.id', '=', 'tp.board_id')
                ->leftJoin('curriculum_board_tbl as cb', 'cb.id', '=', 'tp.curriculum_board_id')
                ->leftJoin('exam_session_tbl as es', 'es.id', '=', 'tp.session_id')
                ->where('tp.id', $paperId)
                ->where('tp.is_active', 1)
                ->select([
                    'tp.id',
                    'tp.paper_title',
                    'tp.paper_slug',
                    'tp.curriculum_board_id',
                    'cb.name as curriculum_board_name',
                    'tp.class_id',
                    'tp.subject_id',
                    'tp.board_id',
                    'eb.board_name',
                    'eb.short_name as board_short_name',
                    'tp.session_id',
                    'es.session_name',
                    'tp.group',
                    'tp.year',
                ])
                ->first();

            if (!$paper) {
                $this->abortJson(404, 'past_paper_not_found', 'Past paper not found.');
            }
            if (!$this->matchesClassCurriculumScope($paper, $class)) {
                $this->abortJson(403, 'resource_out_of_class_scope', 'This past paper does not belong to your class curriculum scope.');
            }

            $pages = DB::table('tst_past_paper_pages')
                ->where('past_paper_id', $paperId)
                ->where('is_active', 1)
                ->select(['id', 'page_no', 'paper_type', 'page_type', 'image_width', 'image_height'])
                ->orderBy('page_no')
                ->orderBy('id')
                ->get()
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'page_no' => (int) $p->page_no,
                    'paper_type' => $p->paper_type,
                    'page_type' => $p->page_type,
                    'image_width' => $p->image_width !== null ? (int) $p->image_width : null,
                    'image_height' => $p->image_height !== null ? (int) $p->image_height : null,
                    'file_endpoint' => "/auth/teacher/classes/{$class->id}/resources/past-papers/structured/{$paper->id}/pages/{$p->id}/file",
                    'thumbnail_endpoint' => "/auth/teacher/classes/{$class->id}/resources/past-papers/structured/{$paper->id}/pages/{$p->id}/file?variant=thumb",
                ])
                ->values();

            return [
                'class' => $this->presentClassSummary($class),
                'paper' => [
                    'source' => self::PAST_PAPER_SOURCE_STRUCTURED,
                    'identifier' => (string) $paper->id,
                    'id' => (int) $paper->id,
                    'title' => $paper->paper_title,
                    'slug' => $paper->paper_slug,
                    'year' => $paper->year !== null ? (int) $paper->year : null,
                    'group' => $paper->group,
                    'curriculum_board' => $paper->curriculum_board_id ? [
                        'id' => (int) $paper->curriculum_board_id,
                        'name' => $paper->curriculum_board_name,
                    ] : null,
                    'exam_board' => $paper->board_id ? [
                        'id' => (int) $paper->board_id,
                        'name' => $paper->board_name,
                        'short_name' => $paper->board_short_name,
                    ] : null,
                    'exam_session' => $paper->session_id ? [
                        'id' => (int) $paper->session_id,
                        'name' => $paper->session_name,
                    ] : null,
                ],
                'pages' => $pages,
            ];
        }

        if ($source === self::PAST_PAPER_SOURCE_CATALOG) {
            $slugRows = DB::table('past_paper_tbl as pp')
                ->leftJoin('board_tbl as eb', 'eb.id', '=', 'pp.board_id')
                ->leftJoin('curriculum_board_tbl as cb', 'cb.id', '=', 'pp.curriculum_board_id')
                ->leftJoin('exam_session_tbl as es', 'es.id', '=', 'pp.session_id')
                ->where('pp.paper_slug', $identifier)
                ->where('pp.activate', 1)
                ->select([
                    'pp.id',
                    'pp.paper_name',
                    'pp.paper_slug',
                    'pp.curriculum_board_id',
                    'cb.name as curriculum_board_name',
                    'pp.class_id',
                    'pp.subject_id',
                    'pp.board_id',
                    'eb.board_name',
                    'eb.short_name as board_short_name',
                    'pp.session_id',
                    'es.session_name',
                    'pp.group',
                    'pp.year',
                ])
                ->orderBy('pp.id')
                ->get();

            if ($slugRows->isEmpty()) {
                $this->abortJson(404, 'past_paper_not_found', 'Past paper not found.');
            }

            $matchingRows = $slugRows->filter(
                fn ($r) => $this->matchesClassCurriculumScope($r, $class)
            )->values();

            if ($matchingRows->isEmpty()) {
                $this->abortJson(403, 'resource_out_of_class_scope', 'This past paper does not belong to your class curriculum scope.');
            }

            $head = $matchingRows->first();
            $pages = $matchingRows->values()->map(fn ($row, $idx) => [
                'id' => (int) $row->id,
                'page_no' => $idx + 1,
                'paper_type' => null,
                'page_type' => null,
                'image_width' => null,
                'image_height' => null,
                'file_endpoint' => "/auth/teacher/classes/{$class->id}/resources/past-papers/catalog/{$head->paper_slug}/pages/{$row->id}/file",
                'thumbnail_endpoint' => "/auth/teacher/classes/{$class->id}/resources/past-papers/catalog/{$head->paper_slug}/pages/{$row->id}/file?variant=thumb",
            ]);

            return [
                'class' => $this->presentClassSummary($class),
                'paper' => [
                    'source' => self::PAST_PAPER_SOURCE_CATALOG,
                    'identifier' => (string) $head->paper_slug,
                    'id' => (int) $head->id,
                    'title' => $head->paper_name,
                    'slug' => $head->paper_slug,
                    'year' => $head->year !== null ? (int) $head->year : null,
                    'group' => $head->group,
                    'curriculum_board' => $head->curriculum_board_id ? [
                        'id' => (int) $head->curriculum_board_id,
                        'name' => $head->curriculum_board_name,
                    ] : null,
                    'exam_board' => $head->board_id ? [
                        'id' => (int) $head->board_id,
                        'name' => $head->board_name,
                        'short_name' => $head->board_short_name,
                    ] : null,
                    'exam_session' => $head->session_id ? [
                        'id' => (int) $head->session_id,
                        'name' => $head->session_name,
                    ] : null,
                ],
                'pages' => $pages,
            ];
        }

        $this->abortJson(404, 'past_paper_source_invalid', 'Past paper source not found.');
    }

    /**
     * Authorize and stream a past-paper page file through the backend without leaking raw S3 keys.
     */
    public function streamPastPaperPage(
        TeacherClass $class,
        string $source,
        string $identifier,
        int $pageId,
        string $variant = 'image'
    ): Response {
        $candidateKeys = [];

        if ($source === self::PAST_PAPER_SOURCE_STRUCTURED) {
            $paper = DB::table('tst_past_papers')
                ->where('id', (int) $identifier)
                ->where('is_active', 1)
                ->first();

            if (!$paper) {
                $this->abortJson(404, 'past_paper_not_found', 'Past paper not found.');
            }
            if (!$this->matchesClassCurriculumScope($paper, $class)) {
                $this->abortJson(403, 'resource_out_of_class_scope', 'You do not have access to this past paper.');
            }

            $page = DB::table('tst_past_paper_pages')
                ->where('id', $pageId)
                ->where('is_active', 1)
                ->first();

            if (!$page) {
                $this->abortJson(404, 'past_paper_page_not_found', 'Past paper page not found.');
            }
            if ((int) $page->past_paper_id !== (int) $paper->id) {
                $this->abortJson(403, 'resource_out_of_class_scope', 'Page does not belong to the requested past paper.');
            }

            $primaryKey = ($variant === 'thumb' && !empty($page->thumbnail_path))
                ? $page->thumbnail_path
                : $page->image_path;

            if (!empty($primaryKey)) {
                $candidateKeys[] = ltrim((string) $primaryKey, '/');
            }
            if ($variant === 'thumb' && !empty($page->image_path) && $page->image_path !== $primaryKey) {
                $candidateKeys[] = ltrim((string) $page->image_path, '/');
            }
        } elseif ($source === self::PAST_PAPER_SOURCE_CATALOG) {
            $row = DB::table('past_paper_tbl')
                ->where('id', $pageId)
                ->where('activate', 1)
                ->first();

            if (!$row) {
                $this->abortJson(404, 'past_paper_page_not_found', 'Past paper page not found.');
            }
            if (
                $row->paper_slug !== $identifier
                || !$this->matchesClassCurriculumScope($row, $class)
            ) {
                $this->abortJson(403, 'resource_out_of_class_scope', 'You do not have access to this past paper.');
            }

            $raw = ltrim((string) $row->paper_path, '/');
            if ($raw !== '') {
                $candidateKeys[] = $raw;
                $candidateKeys[] = "past-papers/{$raw}";
                $candidateKeys[] = "pastpapers/{$raw}";
            }
        } else {
            $this->abortJson(404, 'past_paper_source_invalid', 'Invalid past paper source.');
        }

        if (empty($candidateKeys)) {
            $this->abortJson(404, 'past_paper_file_missing', 'Past paper file is unavailable.');
        }

        foreach ($candidateKeys as $key) {
            $stream = $this->readStoredFileBytes($key);
            if ($stream !== null) {
                return response($stream['bytes'], 200, [
                    'Content-Type' => $stream['mime'],
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }

        $this->abortJson(404, 'past_paper_file_missing', 'Past paper file could not be loaded.');
    }

    /**
     * List Model Papers strictly scoped to the class's (curriculum_board_id, class_id, subject_id).
     */
    public function modelPapers(TeacherClass $class, array $filters = []): array
    {
        $query = $this->scopedModelPapersQuery($class)
            ->leftJoin('paper_parts as ppart', function ($join) {
                $join->on('ppart.model_paper_id', '=', 'mp.id')->where('ppart.activate', 1);
            })
            ->select([
                'mp.id',
                'mp.title',
                'mp.total_marks',
                'mp.time_allowed',
                'mp.instructions',
                'mp.urdu_lang',
                'mp.total_questions',
                'mp.curriculum_board_id',
                'mp.class_id',
                'mp.subject_id',
                DB::raw('COUNT(DISTINCT ppart.id) as parts_count'),
            ])
            ->groupBy(
                'mp.id',
                'mp.title',
                'mp.total_marks',
                'mp.time_allowed',
                'mp.instructions',
                'mp.urdu_lang',
                'mp.total_questions',
                'mp.curriculum_board_id',
                'mp.class_id',
                'mp.subject_id'
            );

        if (!empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where('mp.title', 'like', $term);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);
        $paginator = $query->orderBy('mp.id')->paginate($perPage);

        return [
            'class' => $this->presentClassSummary($class),
            'data' => collect($paginator->items())->map(fn ($mp) => [
                'id' => (int) $mp->id,
                'title' => $mp->title,
                'total_marks' => $mp->total_marks !== null ? (int) $mp->total_marks : null,
                'time_allowed' => $mp->time_allowed,
                'instructions' => $mp->instructions,
                'urdu_lang' => (bool) $mp->urdu_lang,
                'total_questions' => $mp->total_questions !== null ? (int) $mp->total_questions : null,
                'parts_count' => (int) $mp->parts_count,
                'curriculum_board_id' => (int) $mp->curriculum_board_id,
                'class_id' => (int) $mp->class_id,
                'subject_id' => (int) $mp->subject_id,
            ])->values(),
            'pagination' => $this->presentPagination($paginator),
        ];
    }

    /**
     * Read-only Model Paper blueprint detail strictly scoped to the class's curriculum tuple.
     */
    public function modelPaperDetail(TeacherClass $class, int $paperId): array
    {
        $paper = DB::table('model_papers')
            ->where('id', $paperId)
            ->where('activate', 1)
            ->first();

        if (!$paper) {
            $this->abortJson(404, 'model_paper_not_found', 'Model paper not found.');
        }

        if (
            (int) $paper->curriculum_board_id !== (int) $class->curriculum_board_id
            || (int) $paper->class_id !== (int) $class->class_id
            || (int) $paper->subject_id !== (int) $class->subject_id
        ) {
            $this->abortJson(403, 'resource_out_of_class_scope', 'This model paper does not match your class curriculum scope.');
        }

        $parts = DB::table('paper_parts')
            ->where('model_paper_id', $paperId)
            ->where('activate', 1)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $partIds = $parts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sectionsByPart = collect();
        $questionsBySection = collect();
        $qSectionsByQuestion = collect();
        $subSectionsByQSection = collect();
        $pairingBySubSection = collect();

        if (!empty($partIds)) {
            $sections = DB::table('paper_part_sections')
                ->whereIn('paper_part_id', $partIds)
                ->where('activate', 1)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get();
            $sectionsByPart = $sections->groupBy('paper_part_id');

            $sectionIds = $sections->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (!empty($sectionIds)) {
                $questions = DB::table('paper_part_section_questions')
                    ->whereIn('section_id', $sectionIds)
                    ->where('activate', 1)
                    ->orderBy('sequence')
                    ->orderBy('id')
                    ->get();
                $questionsBySection = $questions->groupBy('section_id');

                $qIds = $questions->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (!empty($qIds)) {
                    $qSections = DB::table('paper_question_sections')
                        ->whereIn('question_id', $qIds)
                        ->where('activate', 1)
                        ->orderBy('id')
                        ->get();
                    $qSectionsByQuestion = $qSections->groupBy('question_id');

                    $qsIds = $qSections->pluck('id')->map(fn ($id) => (int) $id)->all();
                    if (!empty($qsIds)) {
                        $subSections = DB::table('paper_question_section_sub_sections as sub')
                            ->leftJoin('question_type_tbl as qt', 'qt.id', '=', 'sub.question_type_id')
                            ->whereIn('sub.section_id', $qsIds)
                            ->where('sub.activate', 1)
                            ->select([
                                'sub.id',
                                'sub.section_id',
                                'sub.sub_section_name',
                                'sub.total_questions',
                                'sub.question_type_id',
                                'qt.type_name as question_type_name',
                                'qt.is_mcq',
                                'sub.is_random_units',
                                'sub.no_of_random_units',
                            ])
                            ->orderBy('sub.id')
                            ->get();
                        $subSectionsByQSection = $subSections->groupBy('section_id');

                        $subIds = $subSections->pluck('id')->map(fn ($id) => (int) $id)->all();
                        if (!empty($subIds)) {
                            $pairingBySubSection = DB::table('question_pairing_schemes as qps')
                                ->leftJoin('book_unit_tbl as u', 'u.id', '=', 'qps.unit_id')
                                ->whereIn('qps.sub_section_id', $subIds)
                                ->select([
                                    'qps.sub_section_id',
                                    'qps.unit_id',
                                    'u.unit_no',
                                    'u.unit_name',
                                    'qps.no_of_questions',
                                ])
                                ->orderBy('u.unit_no')
                                ->orderBy('qps.id')
                                ->get()
                                ->groupBy('sub_section_id');
                        }
                    }
                }
            }
        }

        $presentedParts = $parts->map(function ($part) use (
            $sectionsByPart,
            $questionsBySection,
            $qSectionsByQuestion,
            $subSectionsByQSection,
            $pairingBySubSection
        ) {
            $partSections = ($sectionsByPart[$part->id] ?? collect())->map(function ($section) use (
                $questionsBySection,
                $qSectionsByQuestion,
                $subSectionsByQSection,
                $pairingBySubSection
            ) {
                $questions = ($questionsBySection[$section->id] ?? collect())->map(function ($q) use (
                    $qSectionsByQuestion,
                    $subSectionsByQSection,
                    $pairingBySubSection
                ) {
                    $qSections = ($qSectionsByQuestion[$q->id] ?? collect())->map(function ($qs) use (
                        $subSectionsByQSection,
                        $pairingBySubSection
                    ) {
                        $subSections = ($subSectionsByQSection[$qs->id] ?? collect())->map(function ($sub) use ($pairingBySubSection) {
                            $pairings = ($pairingBySubSection[$sub->id] ?? collect())->map(fn ($p) => [
                                'unit_id' => $p->unit_id !== null ? (int) $p->unit_id : null,
                                'unit_no' => $p->unit_no !== null ? (int) $p->unit_no : null,
                                'unit_name' => $p->unit_name,
                                'no_of_questions' => (int) $p->no_of_questions,
                            ])->values();

                            return [
                                'id' => (int) $sub->id,
                                'sub_section_name' => $sub->sub_section_name,
                                'total_questions' => (int) $sub->total_questions,
                                'question_type' => $sub->question_type_id ? [
                                    'id' => (int) $sub->question_type_id,
                                    'name' => $sub->question_type_name,
                                    'is_mcq' => (bool) $sub->is_mcq,
                                ] : null,
                                'is_random_units' => (bool) $sub->is_random_units,
                                'no_of_random_units' => $sub->no_of_random_units !== null ? (int) $sub->no_of_random_units : null,
                                'pairing_schemes' => $pairings,
                            ];
                        })->values();

                        return [
                            'id' => (int) $qs->id,
                            'section_name' => $qs->section_name,
                            'no_of_sub_sections' => (int) $qs->no_of_sub_sections,
                            'sub_sections' => $subSections,
                        ];
                    })->values();

                    return [
                        'id' => (int) $q->id,
                        'question_no' => $q->question_no,
                        'question_statement' => $q->question_statement,
                        'marks' => $q->marks !== null ? (int) $q->marks : null,
                        'urdu_lang' => (bool) $q->urdu_lang,
                        'has_parts' => (bool) $q->has_parts,
                        'question_sections' => $qSections,
                    ];
                })->values();

                return [
                    'id' => (int) $section->id,
                    'name' => $section->name,
                    'show_name' => (bool) $section->show_name,
                    'no_of_questions' => $section->no_of_questions !== null ? (int) $section->no_of_questions : null,
                    'urdu_lang' => (bool) $section->urdu_lang,
                    'questions' => $questions,
                ];
            })->values();

            return [
                'id' => (int) $part->id,
                'name' => $part->name,
                'name_um' => $part->name_um,
                'max_marks' => $part->max_marks !== null ? (int) $part->max_marks : null,
                'time_allowed' => $part->time_allowed,
                'no_of_sections' => $part->no_of_sections !== null ? (int) $part->no_of_sections : null,
                'sections' => $partSections,
            ];
        })->values();

        return [
            'class' => $this->presentClassSummary($class),
            'model_paper' => [
                'id' => (int) $paper->id,
                'title' => $paper->title,
                'total_marks' => $paper->total_marks !== null ? (int) $paper->total_marks : null,
                'time_allowed' => $paper->time_allowed,
                'instructions' => $paper->instructions,
                'urdu_lang' => (bool) $paper->urdu_lang,
                'total_questions' => $paper->total_questions !== null ? (int) $paper->total_questions : null,
                'curriculum_board_id' => (int) $paper->curriculum_board_id,
                'class_id' => (int) $paper->class_id,
                'subject_id' => (int) $paper->subject_id,
            ],
            'parts' => $presentedParts,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal Scoped Query & Batch Hydration Helpers
    // -------------------------------------------------------------------------

    private function scopedBooksQuery(TeacherClass $class)
    {
        return DB::table('book_tbl as b')
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id);
    }

    private function scopedUnitsQuery(TeacherClass $class)
    {
        return DB::table('book_unit_tbl as u')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id);
    }

    private function scopedTopicsQuery(TeacherClass $class)
    {
        return DB::table('book_unit_topic_tbl as t')
            ->join('book_unit_tbl as u', 'u.id', '=', 't.unit_id')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('t.activate', 1)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id);
    }

    private function scopedSloTopicsQuery(TeacherClass $class)
    {
        return DB::table('slo_topic_tbl as st')
            ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
            ->where('st.activate', 1)
            ->where('sd.activate', 1)
            ->where('sd.curriculum_board_id', $class->curriculum_board_id)
            ->where('sd.subject_id', $class->subject_id)
            ->where('st.class_id', $class->class_id);
    }

    public function scopedMcqQuestionsQuery(TeacherClass $class)
    {
        // Canonical curriculum chain: exam_question_tbl.topic_id -> book_unit_topic_tbl -> book_unit_tbl -> book_tbl
        return DB::table('exam_question_tbl as q')
            ->join('book_unit_topic_tbl as t', 't.id', '=', 'q.topic_id')
            ->join('book_unit_tbl as u', 'u.id', '=', 't.unit_id')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('q.activate', 1)
            ->where('q.is_mcq', 1)
            ->where('t.activate', 1)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id);
    }

    private function scopedModelPapersQuery(TeacherClass $class)
    {
        return DB::table('model_papers as mp')
            ->where('mp.activate', 1)
            ->where('mp.curriculum_board_id', $class->curriculum_board_id)
            ->where('mp.class_id', $class->class_id)
            ->where('mp.subject_id', $class->subject_id);
    }

    private function scopedStructuredPastPapersQuery(TeacherClass $class)
    {
        return DB::table('tst_past_papers as tp')
            ->where('tp.is_active', 1)
            ->whereNotNull('tp.curriculum_board_id')
            ->where('tp.curriculum_board_id', $class->curriculum_board_id)
            ->where('tp.class_id', $class->class_id)
            ->where('tp.subject_id', $class->subject_id);
    }

    private function scopedCatalogPastPapersQuery(TeacherClass $class)
    {
        return DB::table('past_paper_tbl as pp')
            ->where('pp.activate', 1)
            ->whereNotNull('pp.curriculum_board_id')
            ->where('pp.curriculum_board_id', $class->curriculum_board_id)
            ->where('pp.class_id', $class->class_id)
            ->where('pp.subject_id', $class->subject_id);
    }

    private function matchesClassCurriculumScope(object $row, TeacherClass $class): bool
    {
        return $row->curriculum_board_id !== null
            && (int) $row->curriculum_board_id === (int) $class->curriculum_board_id
            && (int) $row->class_id === (int) $class->class_id
            && (int) $row->subject_id === (int) $class->subject_id;
    }

    public function assertScopedBook(TeacherClass $class, int $bookId): object
    {
        $book = DB::table('book_tbl')->where('id', $bookId)->where('activate', 1)->first();
        if (!$book) {
            $this->abortJson(404, 'book_not_found', 'Textbook not found.');
        }
        if (
            (int) $book->curriculum_board_id !== (int) $class->curriculum_board_id
            || (int) $book->class_id !== (int) $class->class_id
            || (int) $book->subject_id !== (int) $class->subject_id
        ) {
            $this->abortJson(403, 'resource_out_of_class_scope', 'This textbook does not belong to your class curriculum scope.');
        }

        return $book;
    }

    public function assertScopedUnit(TeacherClass $class, int $unitId): object
    {
        $unit = DB::table('book_unit_tbl as u')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('u.id', $unitId)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->select([
                'u.*',
                'b.curriculum_board_id',
                'b.class_id',
                'b.subject_id',
                'b.book_name',
            ])
            ->first();

        if (!$unit) {
            $this->abortJson(404, 'unit_not_found', 'Unit not found.');
        }
        if (
            (int) $unit->curriculum_board_id !== (int) $class->curriculum_board_id
            || (int) $unit->class_id !== (int) $class->class_id
            || (int) $unit->subject_id !== (int) $class->subject_id
        ) {
            $this->abortJson(403, 'resource_out_of_class_scope', 'This unit does not belong to your class curriculum scope.');
        }

        return $unit;
    }

    public function assertScopedTopic(TeacherClass $class, int $topicId): object
    {
        $topic = DB::table('book_unit_topic_tbl as t')
            ->join('book_unit_tbl as u', 'u.id', '=', 't.unit_id')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->where('t.id', $topicId)
            ->where('t.activate', 1)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->select([
                't.*',
                'u.unit_no',
                'u.unit_name',
                'b.id as book_id',
                'b.book_name',
                'b.curriculum_board_id',
                'b.class_id',
                'b.subject_id',
            ])
            ->first();

        if (!$topic) {
            $this->abortJson(404, 'topic_not_found', 'Topic not found.');
        }
        if (
            (int) $topic->curriculum_board_id !== (int) $class->curriculum_board_id
            || (int) $topic->class_id !== (int) $class->class_id
            || (int) $topic->subject_id !== (int) $class->subject_id
        ) {
            $this->abortJson(403, 'resource_out_of_class_scope', 'This topic does not belong to your class curriculum scope.');
        }

        return $topic;
    }

    private function assertScopedSloDomain(TeacherClass $class, int $domainId): object
    {
        $domain = DB::table('slo_domain_tbl')->where('id', $domainId)->where('activate', 1)->first();
        if (!$domain) {
            $this->abortJson(404, 'slo_domain_not_found', 'SLO domain not found.');
        }
        if (
            (int) $domain->curriculum_board_id !== (int) $class->curriculum_board_id
            || (int) $domain->subject_id !== (int) $class->subject_id
        ) {
            $this->abortJson(403, 'resource_out_of_class_scope', 'This SLO domain does not belong to your class curriculum scope.');
        }

        return $domain;
    }

    public function assertScopedSloTopic(TeacherClass $class, int $sloTopicId): object
    {
        $slo = DB::table('slo_topic_tbl as st')
            ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
            ->where('st.id', $sloTopicId)
            ->where('st.activate', 1)
            ->where('sd.activate', 1)
            ->select([
                'st.*',
                'sd.curriculum_board_id',
                'sd.subject_id',
            ])
            ->first();

        if (!$slo) {
            $this->abortJson(404, 'slo_not_found', 'SLO not found.');
        }
        if (
            (int) $slo->curriculum_board_id !== (int) $class->curriculum_board_id
            || (int) $slo->class_id !== (int) $class->class_id
            || (int) $slo->subject_id !== (int) $class->subject_id
        ) {
            $this->abortJson(403, 'resource_out_of_class_scope', 'This SLO does not belong to your class curriculum scope.');
        }

        return $slo;
    }

    private function loadUnitsTree(TeacherClass $class, ?int $bookId = null, ?string $search = null): Collection
    {
        $unitsQuery = $this->scopedUnitsQuery($class)
            ->select([
                'u.id',
                'u.book_id',
                'b.book_name',
                'u.unit_no',
                'u.unit_name',
                'u.is_alp',
            ])
            ->orderBy('u.book_id')
            ->orderBy('u.unit_no')
            ->orderBy('u.id');

        if ($bookId) {
            $unitsQuery->where('u.book_id', $bookId);
        }

        $units = $unitsQuery->get();
        $unitIds = $units->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (empty($unitIds)) {
            return collect();
        }

        $topics = DB::table('book_unit_topic_tbl as t')
            ->whereIn('t.unit_id', $unitIds)
            ->where('t.activate', 1)
            ->select([
                't.id',
                't.unit_id',
                't.topic_no',
                't.topic_name',
                't.topic_name_um',
                't.slo_no',
                't.is_alp',
            ])
            ->orderBy('t.topic_no')
            ->orderBy('t.id')
            ->get();

        $topicIds = $topics->pluck('id')->map(fn ($id) => (int) $id)->all();

        $structuresByTopic = collect();
        $topicSlosByTopic = collect();
        $mcqCountByTopic = collect();

        if (!empty($topicIds)) {
            $structuresByTopic = DB::table('topic_content_structure_tbl as tcs')
                ->join('topic_content_type_tbl as tct', 'tct.id', '=', 'tcs.topic_content_type_id')
                ->whereIn('tcs.topic_id', $topicIds)
                ->where('tct.activate', 1)
                ->select([
                    'tcs.topic_id',
                    'tcs.topic_content_type_id',
                    'tct.name',
                    'tct.name_um',
                    'tct.is_mcq',
                ])
                ->orderBy('tcs.id')
                ->get()
                ->groupBy('topic_id');

            $topicSlosByTopic = DB::table('topic_slo_topic_tbl as tst')
                ->join('slo_topic_tbl as st', 'st.id', '=', 'tst.slo_topic_id')
                ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
                ->whereIn('tst.topic_id', $topicIds)
                ->where('st.activate', 1)
                ->where('sd.activate', 1)
                ->where('sd.curriculum_board_id', $class->curriculum_board_id)
                ->where('sd.subject_id', $class->subject_id)
                ->where('st.class_id', $class->class_id)
                ->select(array_merge(['tst.topic_id as mapped_topic_id'], $this->sloSelectColumns()))
                ->orderBy('st.sort_order')
                ->get()
                ->groupBy('mapped_topic_id');

            $mcqCountByTopic = DB::table('exam_question_tbl')
                ->whereIn('topic_id', $topicIds)
                ->where('activate', 1)
                ->where('is_mcq', 1)
                ->select(['topic_id', DB::raw('COUNT(*) as total')])
                ->groupBy('topic_id')
                ->pluck('total', 'topic_id');
        }

        $unitSlosByUnit = DB::table('unit_slo_topic_tbl as ust')
            ->join('slo_topic_tbl as st', 'st.id', '=', 'ust.slo_topic_id')
            ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
            ->whereIn('ust.unit_id', $unitIds)
            ->where('st.activate', 1)
            ->where('sd.activate', 1)
            ->where('sd.curriculum_board_id', $class->curriculum_board_id)
            ->where('sd.subject_id', $class->subject_id)
            ->where('st.class_id', $class->class_id)
            ->select(array_merge(['ust.unit_id as mapped_unit_id'], $this->sloSelectColumns()))
            ->orderBy('st.sort_order')
            ->get()
            ->groupBy('mapped_unit_id');

        $topicsByUnit = $topics->groupBy('unit_id');
        $needle = $search ? mb_strtolower($search) : null;

        return $units->map(function ($unit) use ($topicsByUnit, $structuresByTopic, $topicSlosByTopic, $mcqCountByTopic, $unitSlosByUnit, $needle) {
            $unitTopics = ($topicsByUnit[$unit->id] ?? collect())->map(function ($topic) use ($structuresByTopic, $topicSlosByTopic, $mcqCountByTopic) {
                return [
                    'id' => (int) $topic->id,
                    'topic_no' => $topic->topic_no,
                    'topic_name' => $topic->topic_name,
                    'topic_name_um' => $topic->topic_name_um,
                    'slo_no' => $topic->slo_no,
                    'is_alp' => (bool) $topic->is_alp,
                    'mcq_count' => (int) ($mcqCountByTopic[$topic->id] ?? 0),
                    'content_types' => ($structuresByTopic[$topic->id] ?? collect())->map(fn ($ct) => [
                        'id' => (int) $ct->topic_content_type_id,
                        'name' => $ct->name,
                        'name_um' => $ct->name_um,
                        'is_mcq' => (bool) $ct->is_mcq,
                    ])->values(),
                    'slos' => ($topicSlosByTopic[$topic->id] ?? collect())->map(fn ($s) => $this->presentSloRow($s))->values(),
                ];
            });

            if ($needle !== null) {
                $unitMatches = str_contains(mb_strtolower((string) $unit->unit_name), $needle)
                    || str_contains(mb_strtolower((string) $unit->unit_no), $needle);
                if (!$unitMatches) {
                    $unitTopics = $unitTopics->filter(function ($t) use ($needle) {
                        return str_contains(mb_strtolower((string) $t['topic_name']), $needle)
                            || str_contains(mb_strtolower((string) $t['topic_name_um']), $needle)
                            || str_contains(mb_strtolower((string) $t['slo_no']), $needle);
                    })->values();
                } else {
                    $unitTopics = $unitTopics->values();
                }

                if (!$unitMatches && $unitTopics->isEmpty()) {
                    return null;
                }
            } else {
                $unitTopics = $unitTopics->values();
            }

            return [
                'id' => (int) $unit->id,
                'book_id' => (int) $unit->book_id,
                'book_name' => $unit->book_name,
                'unit_no' => $unit->unit_no !== null ? (int) $unit->unit_no : null,
                'unit_name' => $unit->unit_name,
                'is_alp' => (bool) $unit->is_alp,
                'topics_count' => $unitTopics->count(),
                'slos' => ($unitSlosByUnit[$unit->id] ?? collect())->map(fn ($s) => $this->presentSloRow($s))->values(),
                'topics' => $unitTopics,
            ];
        })->filter()->values();
    }

    private function countByBook(array $bookIds, string $table, string $alias, string $groupCol): array
    {
        if (empty($bookIds)) {
            return [];
        }

        return DB::table("{$table} as {$alias}")
            ->join('book_unit_tbl as u', 'u.id', '=', "{$alias}.unit_id")
            ->whereIn('u.book_id', $bookIds)
            ->where('u.activate', 1)
            ->where("{$alias}.activate", 1)
            ->select([$groupCol . ' as book_id', DB::raw("COUNT({$alias}.id) as total")])
            ->groupBy('u.book_id')
            ->pluck('total', 'book_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function countMcqByBook(TeacherClass $class, array $bookIds): array
    {
        if (empty($bookIds)) {
            return [];
        }

        return $this->scopedMcqQuestionsQuery($class)
            ->whereIn('b.id', $bookIds)
            ->select(['b.id as book_id', DB::raw('COUNT(q.id) as total')])
            ->groupBy('b.id')
            ->pluck('total', 'book_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function sloSelectColumns(): array
    {
        return [
            'st.id',
            'st.slo_domain_id',
            'sd.domain_code',
            'sd.domain_name',
            'sd.domain_name_um',
            'st.class_id',
            'st.slo_code',
            'st.slo_title',
            'st.slo_title_um',
            'st.benchmark_text',
            'st.description',
            'st.assessment_type',
            'st.cognitive_level',
            'st.is_practical',
            'st.is_optional',
            'st.is_alp',
            'st.remarks',
            'st.sort_order',
        ];
    }

    private function presentSloRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'slo_code' => $row->slo_code,
            'slo_title' => $row->slo_title,
            'slo_title_um' => $row->slo_title_um,
            'benchmark_text' => $row->benchmark_text,
            'description' => $row->description,
            'assessment_type' => $row->assessment_type,
            'cognitive_level' => $row->cognitive_level,
            'is_practical' => (bool) $row->is_practical,
            'is_optional' => (bool) $row->is_optional,
            'is_alp' => (bool) $row->is_alp,
            'remarks' => $row->remarks,
            'domain' => [
                'id' => (int) $row->slo_domain_id,
                'code' => $row->domain_code,
                'name' => $row->domain_name,
                'name_um' => $row->domain_name_um,
            ],
        ];
    }

    private function loadUnitsMappedToSlos(TeacherClass $class, array $sloIds): array
    {
        if (empty($sloIds)) {
            return [];
        }

        return DB::table('unit_slo_topic_tbl as ust')
            ->join('book_unit_tbl as u', 'u.id', '=', 'ust.unit_id')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->whereIn('ust.slo_topic_id', $sloIds)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id)
            ->select(['ust.slo_topic_id', 'u.id as unit_id', 'u.unit_no', 'u.unit_name'])
            ->orderBy('u.unit_no')
            ->get()
            ->groupBy('slo_topic_id')
            ->map(fn ($group) => $group->map(fn ($u) => [
                'id' => (int) $u->unit_id,
                'unit_no' => $u->unit_no !== null ? (int) $u->unit_no : null,
                'unit_name' => $u->unit_name,
            ])->values()->all())
            ->all();
    }

    private function loadTopicsMappedToSlos(TeacherClass $class, array $sloIds): array
    {
        if (empty($sloIds)) {
            return [];
        }

        return DB::table('topic_slo_topic_tbl as tst')
            ->join('book_unit_topic_tbl as t', 't.id', '=', 'tst.topic_id')
            ->join('book_unit_tbl as u', 'u.id', '=', 't.unit_id')
            ->join('book_tbl as b', 'b.id', '=', 'u.book_id')
            ->whereIn('tst.slo_topic_id', $sloIds)
            ->where('t.activate', 1)
            ->where('u.activate', 1)
            ->where('b.activate', 1)
            ->where('b.curriculum_board_id', $class->curriculum_board_id)
            ->where('b.class_id', $class->class_id)
            ->where('b.subject_id', $class->subject_id)
            ->select(['tst.slo_topic_id', 't.id as topic_id', 't.topic_no', 't.topic_name'])
            ->orderBy('t.topic_no')
            ->get()
            ->groupBy('slo_topic_id')
            ->map(fn ($group) => $group->map(fn ($t) => [
                'id' => (int) $t->topic_id,
                'topic_no' => $t->topic_no,
                'topic_name' => $t->topic_name,
            ])->values()->all())
            ->all();
    }

    /**
     * Load MCQ answer options with optional is_answer flag for authorized teachers.
     */
    public function loadSafeOptionsForQuestions(array $questionIds, bool $includeAnswers = false): array
    {
        if (empty($questionIds)) {
            return [];
        }

        $select = ['id', 'question_id', 'option', 'option_um', 'option_lang', 'option_um_lang'];
        if ($includeAnswers) {
            $select[] = 'is_answer';
        }

        return DB::table('exam_question_options_tbl')
            ->whereIn('question_id', $questionIds)
            ->select($select)
            ->orderBy('id')
            ->get()
            ->groupBy('question_id')
            ->map(fn ($opts) => $opts->map(function ($opt) use ($includeAnswers) {
                $item = [
                    'id' => (int) $opt->id,
                    'option' => $opt->option,
                    'option_um' => $opt->option_um,
                    'option_lang' => (int) $opt->option_lang,
                    'option_um_lang' => (int) $opt->option_um_lang,
                ];
                if ($includeAnswers) {
                    $item['is_answer'] = (bool) $opt->is_answer;
                }
                return $item;
            })->values()->all())
            ->all();
    }

    /**
     * Load stimulus/scenario passages WITHOUT raw scenario_image S3 paths.
     */
    public function loadSafeScenarioGroups(array $scenarioGroupIds): array
    {
        if (empty($scenarioGroupIds)) {
            return [];
        }

        return DB::table('question_scenario_groups_tbl')
            ->whereIn('id', $scenarioGroupIds)
            ->where('activate', 1)
            ->select(['id', 'title', 'scenario_text', 'scenario_text_um'])
            ->get()
            ->keyBy('id')
            ->map(fn ($sg) => [
                'id' => (int) $sg->id,
                'title' => $sg->title,
                'scenario_text' => $sg->scenario_text,
                'scenario_text_um' => $sg->scenario_text_um,
            ])
            ->all();
    }

    public function loadBoardLinksForQuestions(array $questionIds): array
    {
        if (empty($questionIds)) {
            return [];
        }

        return DB::table('exam_question_board_tbl as eqb')
            ->leftJoin('board_tbl as eb', 'eb.id', '=', 'eqb.board_id')
            ->leftJoin('exam_session_tbl as es', 'es.id', '=', 'eqb.session_id')
            ->leftJoin('study_group_tbl as sg', 'sg.id', '=', 'eqb.group_id')
            ->whereIn('eqb.question_id', $questionIds)
            ->where('eqb.activate', 1)
            ->select([
                'eqb.question_id',
                'eqb.board_id',
                'eb.board_name',
                'eb.short_name as board_short_name',
                'eqb.session_id',
                'es.session_name',
                'eqb.group_id',
                'sg.name as group_name',
                'eqb.year',
            ])
            ->orderByDesc('eqb.year')
            ->orderBy('eqb.id')
            ->get()
            ->groupBy('question_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'board_id' => $r->board_id !== null ? (int) $r->board_id : null,
                'board_name' => $r->board_name,
                'board_short_name' => $r->board_short_name,
                'session_id' => $r->session_id !== null ? (int) $r->session_id : null,
                'session_name' => $r->session_name,
                'group_id' => $r->group_id !== null ? (int) $r->group_id : null,
                'group_name' => $r->group_name,
                'year' => $r->year !== null ? (int) $r->year : null,
            ])->values()->all())
            ->all();
    }

    public function loadQuestionSloLookup(TeacherClass $class, array $directSloIds, array $topicIds, array $unitIds): array
    {
        $byId = [];
        if (!empty($directSloIds)) {
            $directRows = $this->scopedSloTopicsQuery($class)
                ->whereIn('st.id', $directSloIds)
                ->select(['st.id', 'st.slo_code', 'st.slo_title', 'st.cognitive_level'])
                ->get();
            foreach ($directRows as $row) {
                $byId[(int) $row->id] = [
                    'id' => (int) $row->id,
                    'slo_code' => $row->slo_code,
                    'slo_title' => $row->slo_title,
                    'cognitive_level' => $row->cognitive_level,
                ];
            }
        }

        $byTopic = [];
        if (!empty($topicIds)) {
            $topicRows = DB::table('topic_slo_topic_tbl as tst')
                ->join('slo_topic_tbl as st', 'st.id', '=', 'tst.slo_topic_id')
                ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
                ->whereIn('tst.topic_id', $topicIds)
                ->where('st.activate', 1)
                ->where('sd.activate', 1)
                ->where('sd.curriculum_board_id', $class->curriculum_board_id)
                ->where('sd.subject_id', $class->subject_id)
                ->where('st.class_id', $class->class_id)
                ->select(['tst.topic_id', 'st.id', 'st.slo_code', 'st.slo_title', 'st.cognitive_level'])
                ->get();

            foreach ($topicRows as $row) {
                $byTopic[(int) $row->topic_id][] = [
                    'id' => (int) $row->id,
                    'slo_code' => $row->slo_code,
                    'slo_title' => $row->slo_title,
                    'cognitive_level' => $row->cognitive_level,
                ];
            }
        }

        $byUnit = [];
        if (!empty($unitIds)) {
            $unitRows = DB::table('unit_slo_topic_tbl as ust')
                ->join('slo_topic_tbl as st', 'st.id', '=', 'ust.slo_topic_id')
                ->join('slo_domain_tbl as sd', 'sd.id', '=', 'st.slo_domain_id')
                ->whereIn('ust.unit_id', $unitIds)
                ->where('st.activate', 1)
                ->where('sd.activate', 1)
                ->where('sd.curriculum_board_id', $class->curriculum_board_id)
                ->where('sd.subject_id', $class->subject_id)
                ->where('st.class_id', $class->class_id)
                ->select(['ust.unit_id', 'st.id', 'st.slo_code', 'st.slo_title', 'st.cognitive_level'])
                ->get();

            foreach ($unitRows as $row) {
                $byUnit[(int) $row->unit_id][] = [
                    'id' => (int) $row->id,
                    'slo_code' => $row->slo_code,
                    'slo_title' => $row->slo_title,
                    'cognitive_level' => $row->cognitive_level,
                ];
            }
        }

        return [
            'by_id' => $byId,
            'by_topic' => $byTopic,
            'by_unit' => $byUnit,
        ];
    }

    private function readStoredFileBytes(string $key): ?array
    {
        try {
            $s3 = Storage::disk('s3');
            if ($s3->exists($key)) {
                $bytes = $s3->get($key);
                if (is_string($bytes) && $bytes !== '') {
                    return [
                        'bytes' => $bytes,
                        'mime' => $s3->mimeType($key) ?: $this->guessMime($key),
                    ];
                }
            }
        } catch (\Throwable) {
            // Fall through when local S3 credentials are absent or disk is unavailable.
        }

        try {
            $local = Storage::disk('local');
            if ($local->exists($key)) {
                $bytes = $local->get($key);
                if (is_string($bytes) && $bytes !== '') {
                    return [
                        'bytes' => $bytes,
                        'mime' => $this->guessMime($key),
                    ];
                }
            }
        } catch (\Throwable) {
            // Ignore local disk read errors and try configured CDN proxy if outside tests.
        }

        if (!app()->runningUnitTests()) {
            $cdnUrl = $this->awsUploadService->getS3Url($key);
            if ($cdnUrl && filter_var($cdnUrl, FILTER_VALIDATE_URL)) {
                try {
                    $response = Http::timeout(10)->get($cdnUrl);
                    if ($response->successful() && $response->body() !== '') {
                        return [
                            'bytes' => $response->body(),
                            'mime' => $response->header('Content-Type') ?: $this->guessMime($key),
                        ];
                    }
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    public function presentPagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function abortJson(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
