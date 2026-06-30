<?php

namespace App\Http\Controllers;

use App\Models\ExamQuestion;
use App\Models\StudentActivity;
use App\Http\Services\AwsUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class QuestionsController extends Controller
{
    //
    public function getAllQuestions(Request $request){
        try{
            $questions = DB::table('exam_questions_view')->get();

            return response()->json([
                'success' => 1,
                'questions' => $questions
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'questions' => 'Failed to retrieve questions'], 500);

        }
    }

    public function getQuestionsByTopic(Request $request){
        try{
            $questions = DB::table('exam_question_tbl')
    ->join('book_unit_topic_tbl', 'exam_question_tbl.topic_id', '=', 'book_unit_topic_tbl.id')
    ->join('question_type_tbl', 'exam_question_tbl.question_type', '=', 'question_type_tbl.id')
    ->leftJoinSub(
        DB::table('exam_question_options_tbl')
            ->select('question_id')
            ->distinct(),
        'eo',
        'exam_question_tbl.id',
        '=',
        'eo.question_id'
    )
    ->leftJoinSub(
        DB::table('exam_answer_tbl')
            ->select('question_id')
            ->distinct(),
        'ea',
        'exam_question_tbl.id',
        '=',
        'ea.question_id'
    )
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id', 'exam_question_tbl.has_diagram',
    'exam_question_tbl.question_presentation_type_id',
    DB::raw("
            CASE
            WHEN exam_question_tbl.question_type = 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_question_options_tbl eo1 
                    WHERE eo1.question_id = exam_question_tbl.id 
                    AND eo1.is_answer = 1
                ) THEN 0
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea1 
                    WHERE ea1.question_id = exam_question_tbl.id 
                    AND ea1.answer IS NOT NULL 
                    AND ea1.answer != ''
                    AND (ea1.answer_um IS NULL
                    OR ea1.answer_um = '')
                ) THEN 1
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea2 
                    WHERE ea2.question_id = exam_question_tbl.id                     
                    AND ea2.answer_um IS NOT NULL 
                    AND ea2.answer_um != ''
                    AND (ea2.answer IS NULL
                    OR ea2.answer = '')
                ) THEN 2
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea3 
                    WHERE ea3.question_id = exam_question_tbl.id 
                    AND ea3.answer IS NOT NULL 
                    AND ea3.answer != '' 
                    AND ea3.answer_um IS NOT NULL 
                    AND ea3.answer_um != ''
                ) THEN 3
            ELSE 4
        END AS color
        ")
    )
    ->where('exam_question_tbl.topic_id', $request -> topic_id)
    ->get();

            return response()->json([
                'success' => 1,
                'questions' => $questions
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'questions' => $e -> getMessage()], 500);

        }
    }

    public function getQuestionsByUnit(Request $request){
        try{
            $questions = DB::table('exam_question_tbl')
    ->join('book_unit_topic_tbl', 'exam_question_tbl.topic_id', '=', 'book_unit_topic_tbl.id')
    ->join('question_type_tbl', 'exam_question_tbl.question_type', '=', 'question_type_tbl.id')
    ->join('book_unit_tbl', 'book_unit_topic_tbl.unit_id', '=', 'book_unit_tbl.id')
    ->leftJoinSub(
        DB::table('exam_question_options_tbl')
            ->select('question_id')
            ->distinct(),
        'eo',
        'exam_question_tbl.id',
        '=',
        'eo.question_id'
    )
    ->leftJoinSub(
        DB::table('exam_answer_tbl')
            ->select('question_id')
            ->distinct(),
        'ea',
        'exam_question_tbl.id',
        '=',
        'ea.question_id'
    )
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id', 'exam_question_tbl.has_diagram',
    'exam_question_tbl.question_presentation_type_id',
    DB::raw("
            CASE
            WHEN exam_question_tbl.question_type = 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_question_options_tbl eo1 
                    WHERE eo1.question_id = exam_question_tbl.id 
                    AND eo1.is_answer = 1
                ) THEN 0
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea1 
                    WHERE ea1.question_id = exam_question_tbl.id 
                    AND ea1.answer IS NOT NULL 
                    AND ea1.answer != ''
                    AND (ea1.answer_um IS NULL
                    OR ea1.answer_um = '')
                ) THEN 1
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea2 
                    WHERE ea2.question_id = exam_question_tbl.id                     
                    AND ea2.answer_um IS NOT NULL 
                    AND ea2.answer_um != ''
                    AND (ea2.answer IS NULL
                    OR ea2.answer = '')
                ) THEN 2
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea3 
                    WHERE ea3.question_id = exam_question_tbl.id 
                    AND ea3.answer IS NOT NULL 
                    AND ea3.answer != '' 
                    AND ea3.answer_um IS NOT NULL 
                    AND ea3.answer_um != ''
                ) THEN 3
            ELSE 4
        END AS color
        ")
    )
    ->where('book_unit_tbl.id', $request -> unit_id)
    ->get();

            return response()->json([
                'success' => 1,
                'questions' => $questions
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'questions' => $e -> getMessage()], 500);

        }
    }

    public function getQuestionsByBook(Request $request){
        try{
            $questions = DB::table('exam_question_tbl')
    ->join('book_unit_topic_tbl', 'exam_question_tbl.topic_id', '=', 'book_unit_topic_tbl.id')
    ->join('question_type_tbl', 'exam_question_tbl.question_type', '=', 'question_type_tbl.id')
    ->join('book_unit_tbl', 'book_unit_topic_tbl.unit_id', '=', 'book_unit_tbl.id')
    ->join('book_tbl', 'book_unit_tbl.book_id', '=', 'book_tbl.id')
    ->leftJoinSub(
        DB::table('exam_question_options_tbl')
            ->select('question_id')
            ->distinct(),
        'eo',
        'exam_question_tbl.id',
        '=',
        'eo.question_id'
    )
    ->leftJoinSub(
        DB::table('exam_answer_tbl')
            ->select('question_id')
            ->distinct(),
        'ea',
        'exam_question_tbl.id',
        '=',
        'ea.question_id'
    )
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id', 'exam_question_tbl.has_diagram',
    'exam_question_tbl.question_presentation_type_id',
    DB::raw("
            CASE
            WHEN exam_question_tbl.question_type = 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_question_options_tbl eo1 
                    WHERE eo1.question_id = exam_question_tbl.id 
                    AND eo1.is_answer = 1
                ) THEN 0
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea1 
                    WHERE ea1.question_id = exam_question_tbl.id 
                    AND ea1.answer IS NOT NULL 
                    AND ea1.answer != ''
                    AND (ea1.answer_um IS NULL
                    OR ea1.answer_um = '')
                ) THEN 1
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea2 
                    WHERE ea2.question_id = exam_question_tbl.id                     
                    AND ea2.answer_um IS NOT NULL 
                    AND ea2.answer_um != ''
                    AND (ea2.answer IS NULL
                    OR ea2.answer = '')
                ) THEN 2
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea3 
                    WHERE ea3.question_id = exam_question_tbl.id 
                    AND ea3.answer IS NOT NULL 
                    AND ea3.answer != '' 
                    AND ea3.answer_um IS NOT NULL 
                    AND ea3.answer_um != ''
                ) THEN 3
            ELSE 4
        END AS color
        ")
    )
    ->where('book_tbl.id', $request -> book_id)
    ->get();

            return response()->json([
                'success' => 1,
                'questions' => $questions
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'questions' => $e -> getMessage()], 500);

        }
    }

    public function getQuestionsByBoard(Request $request){
        try{
            /*
            $questions = DB::table('exam_question_tbl')
    ->join('book_unit_topic_tbl', 'exam_question_tbl.topic_id', '=', 'book_unit_topic_tbl.id')
    ->join('question_type_tbl', 'exam_question_tbl.question_type', '=', 'question_type_tbl.id')
    ->join('book_unit_tbl', 'book_unit_topic_tbl.unit_id', '=', 'book_unit_tbl.id')
    ->join('book_tbl', 'book_unit_tbl.book_id', '=', 'book_tbl.id')
    ->join('exam_question_board_tbl', 'exam_question_tbl.id', '=', 'exam_question_board_tbl.question_id')
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id', 'exam_question_tbl.has_diagram',
    'exam_question_tbl.question_presentation_type_id')
    ->where('book_tbl.subject_id', $request -> subject_id)
    ->where('book_tbl.class_id', $request -> class_id)
    ->where('exam_question_board_tbl.board_id', $request -> board_id)
    ->where('exam_question_board_tbl.session_id', $request -> session_id)
    ->where('exam_question_board_tbl.year', $request -> year)
    ->where('exam_question_board_tbl.group_id', $request -> group_id)
    ->get();
    */
    $questions = DB::table('exam_question_tbl')
    ->join('book_unit_topic_tbl', 'exam_question_tbl.topic_id', '=', 'book_unit_topic_tbl.id')
    ->join('question_type_tbl', 'exam_question_tbl.question_type', '=', 'question_type_tbl.id')
    ->join('book_unit_tbl', 'book_unit_topic_tbl.unit_id', '=', 'book_unit_tbl.id')
    ->join('book_tbl', 'book_unit_tbl.book_id', '=', 'book_tbl.id')
    ->join('exam_question_board_tbl', 'exam_question_tbl.id', '=', 'exam_question_board_tbl.question_id')
    ->leftJoinSub(
        DB::table('exam_question_options_tbl')
            ->select('question_id')
            ->distinct(),
        'eo',
        'exam_question_tbl.id',
        '=',
        'eo.question_id'
    )
    ->leftJoinSub(
        DB::table('exam_answer_tbl')
            ->select('question_id')
            ->distinct(),
        'ea',
        'exam_question_tbl.id',
        '=',
        'ea.question_id'
    )
    ->select(
        'exam_question_tbl.id', 
        'exam_question_tbl.question', 
        'book_unit_topic_tbl.topic_name', 
        'question_type_tbl.type_name', 
        'exam_question_tbl.activate', 
        'exam_question_tbl.topic_id',
        'exam_question_tbl.has_diagram',
        'exam_question_tbl.question_presentation_type_id',
        DB::raw("
             CASE
            WHEN exam_question_tbl.question_type = 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_question_options_tbl eo1 
                    WHERE eo1.question_id = exam_question_tbl.id 
                    AND eo1.is_answer = 1
                ) THEN 0
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea1 
                    WHERE ea1.question_id = exam_question_tbl.id 
                    AND ea1.answer IS NOT NULL 
                    AND ea1.answer != ''
                    AND (ea1.answer_um IS NULL
                    OR ea1.answer_um = '')
                ) THEN 1
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea2 
                    WHERE ea2.question_id = exam_question_tbl.id                     
                    AND ea2.answer_um IS NOT NULL 
                    AND ea2.answer_um != ''
                    AND (ea2.answer IS NULL
                    OR ea2.answer = '')
                ) THEN 2
            WHEN exam_question_tbl.question_type != 1 
                AND EXISTS (
                    SELECT 1 
                    FROM exam_answer_tbl ea3 
                    WHERE ea3.question_id = exam_question_tbl.id 
                    AND ea3.answer IS NOT NULL 
                    AND ea3.answer != '' 
                    AND ea3.answer_um IS NOT NULL 
                    AND ea3.answer_um != ''
                ) THEN 3
            ELSE 4
        END AS color
        ")
    )
    ->where('book_tbl.subject_id', $request->subject_id)
    ->where('book_tbl.class_id', $request->class_id)
    ->where('exam_question_board_tbl.board_id', $request->board_id)
    ->where('exam_question_board_tbl.session_id', $request->session_id)
    ->where('exam_question_board_tbl.year', $request->year)
    ->where('exam_question_board_tbl.group_id', $request->group_id)
    ->groupBy(
        'exam_question_tbl.id', 
        'exam_question_tbl.question', 
        'book_unit_topic_tbl.topic_name', 
        'question_type_tbl.type_name', 
        'exam_question_tbl.activate', 
        'exam_question_tbl.topic_id',
        'exam_question_tbl.has_diagram',
        'exam_question_tbl.question_presentation_type_id'
        
    )
    ->get();


            return response()->json([
                'success' => 1,
                'questions' => $questions
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'questions' => $e -> getMessage()], 500);

        }
    }



    public function getQuestionDataById(Request $request){
        try{

            $questionData = DB::table('exam_question_tbl as questions')
            ->leftJoin('question_presentation_type_tbl as presentation_types', 'questions.question_presentation_type_id', '=', 'presentation_types.id')
            ->where('questions.id', '=', $request -> id)
            ->select(
                'questions.*',
                'presentation_types.type_name as question_presentation_type_name',
                'presentation_types.code as question_presentation_type_code'
            )
            ->get();

            $question = $questionData -> first();

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Question not found.',
                ], 404);
            }

            if (!$this->canAccessQuestionScope($request, 'questions.view', $this->questionContextById($question->id))) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to view this question.',
                ], 403);
            }

            $answers = DB::table('exam_answer_tbl')
            ->where('question_id', '=', $request ->id)
            ->get();

            $topicId = $request->topic_id ?? $question->topic_id;

            $unit = DB::table('book_unit_topic_tbl')
            ->where('id', '=', $topicId)
            ->select('unit_id')
            ->get();

            if ($unit->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Question topic not found.',
                ], 404);
            }

            $book = DB::table('book_unit_tbl')
            ->where('id', '=', $unit -> first() -> unit_id)
            ->select('book_id')
            ->get();

            if ($book->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Question book not found.',
                ], 404);
            }

            $sublass = DB::table('book_tbl')
            ->where('id', '=', $book -> first() -> book_id)
            ->select('subject_id', 'class_id', 'curriculum_board_id')
            ->get();

            $options = [];

            if ($question -> question_type == 1 || $question -> is_mcq == 1){

                $options = DB::table('exam_question_options_tbl')
                ->where('question_id', '=', $question -> id)
                ->select('id', 'option as text', 'option_um as text_um', 'is_answer as is_correct')
                ->get();

            }

            $boardLinks = DB::table('exam_question_board_tbl as question_boards')
                ->leftJoin('board_tbl as boards', 'question_boards.board_id', '=', 'boards.id')
                ->leftJoin('exam_session_tbl as sessions', 'question_boards.session_id', '=', 'sessions.id')
                ->leftJoin('study_group_tbl as groups', 'question_boards.group_id', '=', 'groups.id')
                ->where('question_boards.question_id', '=', $question->id)
                ->select(
                    'question_boards.id',
                    'question_boards.board_id',
                    'boards.board_name',
                    'question_boards.session_id',
                    'sessions.session_name',
                    'question_boards.year',
                    'question_boards.group_id',
                    'groups.name as group_name',
                    'question_boards.activate'
                )
                ->orderByDesc('question_boards.year')
                ->orderBy('boards.board_name')
                ->orderBy('sessions.session_name')
                ->get();

            $firstBoardLink = $boardLinks->first();

            $question->board_links = $boardLinks;
            $question->board_id = $firstBoardLink?->board_id;
            $question->board_name = $firstBoardLink?->board_name;
            $question->session_id = $firstBoardLink?->session_id;
            $question->session_name = $firstBoardLink?->session_name;
            $question->year = $firstBoardLink?->year;
            $question->group_id = $firstBoardLink?->group_id;
            $question->group_name = $firstBoardLink?->group_name;

            return response() -> json([
                'success' => true,
                'question_data' => $questionData,
                'unit' => $unit,
                'book' => $book,
                'sublass' => $sublass,
                'options' => $options,
                'answers' => $answers,
                'board_links' => $boardLinks,

            ]);



        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e -> getMessage()], 500);

        }

        
    }

    public function getQuestionsByFilters(Request $request)
    {
        try {
            $query = DB::table('exam_question_tbl as q')
                ->leftJoin('book_unit_topic_tbl as topics', 'q.topic_id', '=', 'topics.id')
                ->leftJoin('book_unit_tbl as units', function ($join) {
                    $join->on('units.id', '=', DB::raw('COALESCE(q.unit_id, topics.unit_id)'));
                })
                ->leftJoin('book_tbl as books', function ($join) {
                    $join->on('books.id', '=', DB::raw('COALESCE(q.book_id, units.book_id)'));
                })
                ->leftJoin('question_type_tbl as question_types', 'q.question_type', '=', 'question_types.id')
                ->leftJoin('question_presentation_type_tbl as presentation_types', 'q.question_presentation_type_id', '=', 'presentation_types.id')
                ->select(
                    'q.id',
                    'q.question',
                    'q.question_um',
                    'q.topic_id',
                    'topics.topic_name',
                    'topics.topic_name_um',
                    DB::raw('COALESCE(q.unit_id, topics.unit_id) as unit_id'),
                    'units.unit_name',
                    DB::raw('COALESCE(q.book_id, units.book_id) as book_id'),
                    'books.book_name',
                    'books.class_id',
                    'books.subject_id',
                    'books.curriculum_board_id',
                    'q.question_type',
                    'question_types.type_name',
                    'q.cognitive_domain',
                    'q.topic_content',
                    'q.difficulty',
                    'q.marks',
                    'q.status',
                    'q.reviewed_by',
                    'q.reviewed_at',
                    'q.explanation',
                    'q.explanation_um',
                    'q.explanation_video_url',
                    'q.activate',
                    'q.is_mcq',
                    'q.has_diagram',
                    'q.question_presentation_type_id',
                    'presentation_types.type_name as question_presentation_type_name',
                    'presentation_types.code as question_presentation_type_code',
                    'q.is_alp_question',
                    'q.created_at',
                    'q.updated_at'
                );

            $applyFilter = function ($column, $value) use ($query) {
                if (is_array($value)) {
                    $values = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));

                    if (!empty($values)) {
                        $query->whereIn($column, $values);
                    }

                    return;
                }

                if ($value !== null && $value !== '') {
                    $query->where($column, $value);
                }
            };

            $applyFilter('q.topic_id', $request->input('topic_id'));
            $applyFilter('q.status', $request->input('status'));
            $applyFilter('q.question_type', $request->input('question_type'));
            $applyFilter('q.cognitive_domain', $request->input('cognitive_domain'));
            $applyFilter('q.topic_content', $request->input('topic_content'));
            $applyFilter('q.difficulty', $request->input('difficulty'));
            $applyFilter('q.activate', $request->input('activate'));
            $applyFilter('q.is_mcq', $request->input('is_mcq'));
            $applyFilter('q.has_diagram', $request->input('has_diagram'));
            $applyFilter('q.question_presentation_type_id', $request->input('question_presentation_type_id'));
            $applyFilter('q.is_alp_question', $request->input('is_alp_question'));
            $applyFilter('books.class_id', $request->input('class_id'));
            $applyFilter('books.subject_id', $request->input('subject_id'));
            $applyFilter('books.curriculum_board_id', $request->input('curriculum_board_id'));

            $boardFilters = [];

            foreach (['board_id', 'session_id', 'year', 'group_id'] as $boardFilter) {
                if ($request->filled($boardFilter)) {
                    $value = $request->input($boardFilter);
                    $boardFilters[$boardFilter] = is_array($value) ? $value : [$value];
                }
            }

            $applyBoardFilters = function ($boardQuery) use ($boardFilters) {
                foreach ($boardFilters as $column => $values) {
                    $values = array_values(array_filter($values, fn ($item) => $item !== null && $item !== ''));

                    if (!empty($values)) {
                        $boardQuery->whereIn('question_boards.' . $column, $values);
                    }
                }
            };

            if ($request->filled('search')) {
                $search = '%' . $request->input('search') . '%';

                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('q.question', 'like', $search)
                        ->orWhere('q.question_um', 'like', $search)
                        ->orWhere('topics.topic_name', 'like', $search)
                        ->orWhere('topics.topic_name_um', 'like', $search)
                        ->orWhere('units.unit_name', 'like', $search)
                        ->orWhere('books.book_name', 'like', $search)
                        ->orWhere('question_types.type_name', 'like', $search)
                        ->orWhere('presentation_types.type_name', 'like', $search);
                });
            }

            if ($request->filled('unit_id')) {
                $unitIds = is_array($request->unit_id) ? $request->unit_id : [$request->unit_id];

                $query->where(function ($unitQuery) use ($unitIds) {
                    $unitQuery->whereIn('q.unit_id', $unitIds)
                        ->orWhereIn('topics.unit_id', $unitIds);
                });
            }

            if ($request->filled('book_id')) {
                $bookIds = is_array($request->book_id) ? $request->book_id : [$request->book_id];

                $query->where(function ($bookQuery) use ($bookIds) {
                    $bookQuery->whereIn('q.book_id', $bookIds)
                        ->orWhereIn('units.book_id', $bookIds);
                });
            }

            if (!empty($boardFilters)) {
                $query->whereExists(function ($boardQuery) use ($applyBoardFilters) {
                    $boardQuery->select(DB::raw(1))
                        ->from('exam_question_board_tbl as question_boards')
                        ->whereColumn('question_boards.question_id', 'q.id');

                    $applyBoardFilters($boardQuery);
                });
            }

            $this->applyQuestionScopeFilter($query, $request, 'questions.view');

            $perPage = min((int) $request->input('per_page', 50), 200);

            $questions = $query
                ->orderByDesc('q.id')
                ->paginate($perPage);

            $questionIds = collect($questions->items())->pluck('id')->filter()->values();

            if ($questionIds->isNotEmpty()) {
                $boardLinks = DB::table('exam_question_board_tbl as question_boards')
                    ->leftJoin('board_tbl as boards', 'question_boards.board_id', '=', 'boards.id')
                    ->leftJoin('exam_session_tbl as sessions', 'question_boards.session_id', '=', 'sessions.id')
                    ->leftJoin('study_group_tbl as groups', 'question_boards.group_id', '=', 'groups.id')
                    ->whereIn('question_boards.question_id', $questionIds->all())
                    ->select(
                        'question_boards.question_id',
                        'question_boards.board_id',
                        'boards.board_name',
                        'question_boards.session_id',
                        'sessions.session_name',
                        'question_boards.year',
                        'question_boards.group_id',
                        'groups.name as group_name',
                        'question_boards.activate'
                    );

                $applyBoardFilters($boardLinks);

                $boardLinksByQuestion = $boardLinks
                    ->orderByDesc('question_boards.year')
                    ->orderBy('boards.board_name')
                    ->orderBy('sessions.session_name')
                    ->get()
                    ->groupBy('question_id');

                $questions->getCollection()->transform(function ($question) use ($boardLinksByQuestion) {
                    $links = $boardLinksByQuestion->get($question->id, collect())->values();
                    $firstLink = $links->first();

                    $question->board_links = $links;
                    $question->board_id = $firstLink?->board_id;
                    $question->board_name = $firstLink?->board_name;
                    $question->session_id = $firstLink?->session_id;
                    $question->session_name = $firstLink?->session_name;
                    $question->year = $firstLink?->year;
                    $question->group_id = $firstLink?->group_id;
                    $question->group_name = $firstLink?->group_name;

                    return $question;
                });
            }

            return response()->json([
                'success' => 1,
                'questions' => $questions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveQuestion(Request $request){
        try{
            $context = $this->questionContextFromRequest($request);
            if (!$this->canAccessQuestionScope($request, 'questions.create', $context)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to create questions for the selected scope.',
                ], 403);
            }

            DB::beginTransaction();

            $userId = $this->authenticatedUserId($request);
            $status = $request->status ?? 'draft';
            $reviewedBy = $request->filled('reviewed_by')
                ? $request->reviewed_by
                : ($status === 'published' ? $userId : null);
            $reviewedAt = $request->filled('reviewed_at')
                ? $request->reviewed_at
                : ($status === 'published' ? now() : null);

            $question = DB::table('exam_question_tbl')
            ->insertGetId($this->withAuditColumns('exam_question_tbl', [
                'question' => $request -> question,
                'question_um' => $request -> question_um,
                'topic_id' => $request -> topic_id,
                'question_type' => $request -> question_type,
                'exercise_question' => $request -> exercise_question,
                'marks' => $request -> marks,
                'difficulty' => $request->difficulty ?? 3,
                'question_lang' => $request->question_lang,
                'question_um_lang' => $request->question_um_lang,
                'cognitive_domain' => $request->cognitive_domain,
                'topic_content' => $request -> topic_content,
                'status' => $status,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedAt,
                'explanation' => $request->explanation,
                'explanation_um' => $request->explanation_um,
                'explanation_video_url' => $request->explanation_video_url,
                'book_id' => $request->book_id,
                'unit_id' => $request->unit_id,
                'activate' => $request->has('activate')
                    ? $request->activate
                    : ($status !== 'archived'),
                'is_mcq' => $request -> is_mcq,
                'has_diagram' => $request->has('has_diagram') ? $request->has_diagram : 0,
                'question_presentation_type_id' => $request->filled('question_presentation_type_id')
                    ? $request->question_presentation_type_id
                    : null,
                'is_alp_question' => $request->is_alp_question,
                'created_at' => now(),
                'updated_at' => now(), 

            ], $userId));

            if ($this->hasQuestionStatementImage($request)) {
                $questionStatement = $this->resolveQuestionStatement($request, null, $question);

                DB::table('exam_question_tbl')
                    ->where('id', '=', $question)
                    ->update($this->withAuditColumns('exam_question_tbl', [
                        'question' => $questionStatement,
                        'updated_at' => now(),
                    ], $userId, false));
            }

            if ($request -> answer != "" || $request ->answer_um != ""){
                $answer = DB::table('exam_answer_tbl')
                ->insert($this->withAuditColumns('exam_answer_tbl', [
                    'question_id' => $question,
                    'answer' => $request -> answer,
                    'answer_um' => $request -> answer_um,                    
                    'answer_lang' => $request->answer_lang,
                    'answer_um_lang' => $request->answer_um_lang,
                    'created_at' => now(),
                    'updated_at' => now(), 
                ], $userId));
            }
            $boardLinks = $request->board_links;

            if ($boardLinks != null && is_array($boardLinks)) {
                foreach ($boardLinks as $boardLink) {
                    if (!empty($boardLink['board_id'])) {
                        DB::table('exam_question_board_tbl')
                            ->insert($this->withAuditColumns('exam_question_board_tbl', [
                                'question_id' => $question,
                                'board_id' => $boardLink['board_id'],
                                'session_id' => $boardLink['session_id'] ?? null,
                                'group_id' => $boardLink['group_id'] ?? null,
                                'year' => $boardLink['year'] ?? null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ], $userId));
                    }
                }
            } elseif ($request->board_id) {
                DB::table('exam_question_board_tbl')
                    ->insert($this->withAuditColumns('exam_question_board_tbl', [
                        'question_id' => $question,
                        'board_id' => $request->board_id,
                        'session_id' => $request->session_id,
                        'group_id' => $request->group_id,
                        'year' => $request->year,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $userId));
            }
           

            $options = $request -> options;

            if ($options != null && is_array($options)) {
                foreach ($options as $option) {
                    DB::table('exam_question_options_tbl')->insert($this->withAuditColumns('exam_question_options_tbl', [
                        'question_id' => $question,
                        'option' => $option['text'], // Assuming 'text' is a key in each option array
                        'option_um' => $option['text_um'],
                        'is_answer' => $option['is_correct'], // Assuming 'is_correct' is a boolean key in each option array
                        'option_lang' => $request->option_lang,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $userId));
                }
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Question and options saved successfully.']);



        }
        catch(\Exception $e){

            DB::rollBack();

            

            return response()->json(['success' => false, 'message' => 'Error saving question.', 'error' => $e->getMessage()]);

        }
    }

    public function updateQuestion(Request $request){
        try {
            $existingContext = $this->questionContextById($request->id);
            $newContext = $this->questionContextFromRequest($request);

            if (
                !$this->canAccessQuestionScope($request, 'questions.update', $existingContext)
                || !$this->canAccessQuestionScope($request, 'questions.update', $newContext)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to update questions for the selected scope.',
                ], 403);
            }

            DB::beginTransaction();

            $userId = $this->authenticatedUserId($request);
            $status = $request->status ?? 'draft';
            $reviewedBy = $request->filled('reviewed_by')
                ? $request->reviewed_by
                : ($status === 'published' ? $userId : null);
            $reviewedAt = $request->filled('reviewed_at')
                ? $request->reviewed_at
                : ($status === 'published' ? now() : null);

            $existingQuestion = DB::table('exam_question_tbl')
                ->where('id', '=', $request->id)
                ->select('question')
                ->first();

            $questionStatement = $this->resolveQuestionStatement($request, $existingQuestion?->question, $request->id);
    
            // Update the main question
            DB::table('exam_question_tbl')
                ->where('id', '=', $request->id) // Apply the where condition first
                ->update($this->withAuditColumns('exam_question_tbl', [
                    'question' => $questionStatement,
                    'question_um' => $request->question_um,
                    'topic_id' => $request->topic_id,
                    'question_type' => $request->question_type,
                    'exercise_question' => $request->exercise_question,
                    'marks' => $request -> marks,
                    'difficulty' => $request->difficulty ?? 3,
                    'question_lang' => $request->question_lang,
                    'question_um_lang' => $request->question_um_lang,
                    'cognitive_domain' => $request->cognitive_domain,
                    'topic_content' => $request -> topic_content,
                    'status' => $status,
                    'reviewed_by' => $reviewedBy,
                    'reviewed_at' => $reviewedAt,
                    'explanation' => $request->explanation,
                    'explanation_um' => $request->explanation_um,
                    'explanation_video_url' => $request->explanation_video_url,
                    'book_id' => $request->book_id,
                    'unit_id' => $request->unit_id,
                    'activate' => $request->has('activate')
                        ? $request->activate
                        : ($status !== 'archived'),
                    'is_mcq' => $request -> is_mcq,
                    'has_diagram' => $request->has('has_diagram') ? $request->has_diagram : 0,
                    'question_presentation_type_id' => $request->filled('question_presentation_type_id')
                        ? $request->question_presentation_type_id
                        : null,
                    'updated_at' => now(),
                    'is_alp_question' => $request->is_alp_question,
                ], $userId, false));

               
                    $existingRecord = DB::table('exam_answer_tbl')
    ->where('question_id', $request->id)
    ->first();

if ($existingRecord) {
    // Update the existing record
    DB::table('exam_answer_tbl')
        ->where('question_id', $request->id)
        ->update($this->withAuditColumns('exam_answer_tbl', [
            'answer' => $request->answer,
            'answer_um' => $request->answer_um,           
            'answer_lang' => $request->answer_lang,
            'answer_um_lang' => $request->answer_um_lang,
            'updated_at' => now(),
        ], $userId, false));
} else {
    // Insert a new record
    DB::table('exam_answer_tbl')
        ->insert($this->withAuditColumns('exam_answer_tbl', [
            'question_id' => $request->id,
            'answer' => $request->answer,
            'answer_um' => $request->answer_um,            
            'answer_lang' => $request->answer_lang,
            'answer_um_lang' => $request->answer_um_lang,
            'created_at' => now(),
            'updated_at' => now(),
        ], $userId));

                }
    
            // Handle options update
            $options = $request->options;
            if ($options != null && is_array($options)) {
                foreach ($options as $option) {
                    if (!empty($option['id'])) {
                        DB::table('exam_question_options_tbl')
                            ->where('id', '=', $option['id']) // Correctly reference the option ID
                            ->update($this->withAuditColumns('exam_question_options_tbl', [
                                'option' => $option['text'], // Assuming 'text' is a key in each option array
                                'option_um' => $option['text_um'],
                                'is_answer' => $option['is_correct'], // Assuming 'is_correct' is a boolean key in each option array
                                'option_lang' => $request->option_lang,
                                'updated_at' => now(),
                            ], $userId, false));
                    } else {
                        DB::table('exam_question_options_tbl')->insert($this->withAuditColumns('exam_question_options_tbl', [
                            'question_id' => $request->id,
                            'option' => $option['text'], // Assuming 'text' is a key in each option array
                            'option_um' => $option['text_um'],
                            'is_answer' => $option['is_correct'], // Assuming 'is_correct' is a boolean key in each option array
                            'option_lang' => $request->option_lang,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ], $userId));
                    }
                }
            }

            if ($request->has('board_links') && is_array($request->board_links)) {
                DB::table('exam_question_board_tbl')
                    ->where('question_id', '=', $request->id)
                    ->delete();

                foreach ($request->board_links as $boardLink) {
                    if (!empty($boardLink['board_id'])) {
                        DB::table('exam_question_board_tbl')
                            ->insert($this->withAuditColumns('exam_question_board_tbl', [
                                'question_id' => $request->id,
                                'board_id' => $boardLink['board_id'],
                                'session_id' => $boardLink['session_id'] ?? null,
                                'group_id' => $boardLink['group_id'] ?? null,
                                'year' => $boardLink['year'] ?? null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ], $userId));
                    }
                }
            } elseif ($request->filled('board_id')) {
                DB::table('exam_question_board_tbl')
                    ->where('question_id', '=', $request->id)
                    ->delete();

                DB::table('exam_question_board_tbl')
                    ->insert($this->withAuditColumns('exam_question_board_tbl', [
                        'question_id' => $request->id,
                        'board_id' => $request->board_id,
                        'session_id' => $request->session_id,
                        'group_id' => $request->group_id,
                        'year' => $request->year,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $userId));
            }

            DB::commit();

            if ($this->hasQuestionStatementImage($request)) {
                $this->deleteManagedQuestionStatementImage($existingQuestion?->question);
            }
    
            return response()->json(['success' => true, 'message' => 'Question and options updated successfully.']);
        } catch(\Exception $e) {
            DB::rollBack();
    /*
            \Log::error('Error saving question:', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
    */
            return response()->json(['success' => false, 'message' => 'Error updating question.', 'error' => $e->getMessage()]);
        }
    }
    

    public function repeatQuestion(Request $request){

        try{

            $userId = $this->authenticatedUserId($request);

            $exists = DB::table('exam_question_board_tbl')
            ->where('question_id', $request->id)
            ->where('board_id', $request->board_id)
            ->where('session_id', $request->session_id)
            ->where('group_id', $request->group_id)
            ->where('year', $request->year)
            ->exists();

        if ($exists) {
            return response()->json(['success' => 0, 'message' => 'Duplicate record found.']);
        }

            $board_question = DB::table('exam_question_board_tbl')
            ->insert($this->withAuditColumns('exam_question_board_tbl', [
                'question_id' => $request -> id,
                'board_id' => $request -> board_id,
                'session_id' => $request -> session_id,
                'group_id' => $request -> group_id,
                'year' => $request -> year,
                'created_at' => now(),
                'updated_at' => now(), 
            ], $userId));

            return response()->json(['success' => 1, 'message' => 'Question repeated successfully.']);


        }
        catch(\Exception $e){

            return response()->json(['success' => 2, 'message' => 'Error repeating question.', 'error' => $e->getMessage()]);


        }
    }

    public function activateQuestion(Request $request){

        try{
            $userId = $this->authenticatedUserId($request);

            if (!$this->canAccessQuestionScope($request, 'questions.activate', $this->questionContextById($request->id))) {
                return response()->json([
                    'success' => 0,
                    'message' => 'You are not allowed to activate questions for this scope.',
                ], 403);
            }

            $activate = DB::table('exam_question_tbl')
         ->where('id', '=', $request -> id)
        ->update($this->withAuditColumns('exam_question_tbl', [
            'activate' => $request -> activate,
            'updated_at' => now(),
        ], $userId, false));

        if ($activate) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }

        }
        catch(\Exception $e){
            return response()->json(['success' => 2, 'message' => 'Error activating question.', 'error' => $e->getMessage()]);


        }
        
    }

    public function getBoardQuestionsByQuestion(Request $request){
        try{
            $question = DB::table('exam_question_board_view')
            ->where('question_id', '=', $request -> question_id)
            ->get();
            return response()->json([
                'success' => 1,
                'questions' => $question,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'message' => 'Error activating question.', 
                'error' => $e->getMessage()]);


        }
    }

    public function activateBoardQuestion(Request $request){

        try{
            $userId = $this->authenticatedUserId($request);

            $activate = DB::table('exam_question_board_tbl')
         ->where('id', '=', $request -> id)
        ->update($this->withAuditColumns('exam_question_board_tbl', [
            'activate' => $request -> activate,
            'updated_at' => now(),
        ], $userId, false));

        if ($activate) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }

        }
        catch(\Exception $e){
            return response()->json(['success' => 2, 'message' => 'Error activating question.', 'error' => $e->getMessage()]);


        }
        
    }
    public function updateBoardQuestion(Request $request){
        try{
            $userId = $this->authenticatedUserId($request);

            $question = DB::table('exam_question_board_tbl')
            ->where('id', '=', $request -> id)
            ->update($this->withAuditColumns('exam_question_board_tbl', [
                'board_id' => $request -> board_id,
                'year' => $request -> year,
                'session_id' => $request -> session_id,
                'group_id' => $request -> group_id,
                'updated_at' => now(),
            ], $userId, false));

            if ($question) {
                return response()->json(['success' => 1], 200);
            } else {
                return response()->json(['success' => 0], 400);
            }


        }
        catch(\Exception $e){
            return response()->json(['success' => 2, 'message' => 'Error updating board question.', 'error' => $e->getMessage()]);

        }
    }

    public function getTest(Request $request){
        try{

            $question = DB::table('exam_question_tbl')
            ->where('id', '=', 26)
            ->select('question_um')
            ->get();

            return response()->json(['success' => true, 'question' => $question]);


        }
        catch(\Exception $e){

            return response()->json(['success' => false, 'message' => 'Error saving question.', 'error' => $e->getMessage()]);


        }
    }

    public function saveTest(Request $request){
        try{
            $userId = $this->authenticatedUserId($request);

            $question = DB::table('exam_question_tbl')
            ->insertGetId($this->withAuditColumns('exam_question_tbl', [
                'question' => $request -> question,
                'question_um' => $request -> question,
                'topic_id' =>  1,
                'question_type' => 1,
                'exercise_question' => 1,
                'marks' => 1,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(), 

            ], $userId));

            return response()->json(['success' => true, 'message' => 'Question and options saved successfully.']);


        }
        catch(\Exception $e){
            return response()->json(['success' => false, 'message' => 'Error saving question.', 'error' => $e->getMessage()]);

        }
    }

    public function getSLOQuestionsByTopic(Request $request){
        $is_alp = $request->input('is_alp') ?? 2;
        
        try{
            $questions = DB::select('call GetSLOQuestions(?, ?, ?, ?, ?)', [
                $request -> topic_id,
                $request -> content_id,
                $request -> cognitive_domain,
                $request -> is_mcq,
                $is_alp
            ]);

            

            return response()->json([
                'success' => 1,
                'questions' => $questions,
                'topic_id' => $request->topic_id,
                'is_mcq' => $request->is_mcq,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    //----------------- new lms -----------------------

    public function getQuestionsByTopicForLMS(Request $request, $topic_id){
        try{
            $questions = ExamQuestion::with(['answers', 'answerOptions', 'questionType'])
                ->where('topic_id', $topic_id)
                ->where('activate', 1)
                ->orderBy('question_type', 'asc')
                ->get();

            $topicContext = DB::table('book_unit_topic_tbl as topics')
                ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->join('subject_tbl as subjects', 'subjects.id', '=', 'books.subject_id')
                ->where('topics.id', $topic_id)
                ->select(
                    'topics.id as topic_id',
                    'topics.topic_name',
                    'topics.topic_no',
                    'units.id as unit_id',
                    'units.unit_name',
                    'units.unit_no',
                    'subjects.id as subject_id',
                    'subjects.subject_name'
                )
                ->first();

            if ($topicContext && $request->user()) {
                $unitLabel = $topicContext->unit_no
                    ? 'Unit ' . $topicContext->unit_no
                    : $topicContext->unit_name;

                if ($topicContext->unit_name) {
                    $unitLabel .= ' - ' . $topicContext->unit_name;
                }

                $topicLabel = $topicContext->topic_no
                    ? 'Topic ' . $topicContext->topic_no
                    : 'Topic';

                if ($topicContext->topic_name) {
                    $topicLabel .= ' - ' . $topicContext->topic_name;
                }

                StudentActivity::create([
                    'user_id' => $request->user()->id,
                    'activity_type' => 'topic_questions_opened',
                    'title' => 'Topic Questions Opened',
                    'description' => 'Opened questions for '
                        . $topicContext->subject_name
                        . ' covering '
                        . $unitLabel
                        . ' and '
                        . $topicLabel
                        . '.',
                    'offered_program_id' => $request->integer('offered_program_id') ?: null,
                    'subject_id' => $topicContext->subject_id,
                    'unit_id' => $topicContext->unit_id,
                    'reference_id' => $topicContext->topic_id,
                    'reference_type' => 'topic',
                    'meta' => [
                        'topic_id' => (int) $topicContext->topic_id,
                        'topic_name' => $topicContext->topic_name,
                        'topic_no' => $topicContext->topic_no,
                        'unit_name' => $topicContext->unit_name,
                        'unit_no' => $topicContext->unit_no,
                        'subject_name' => $topicContext->subject_name,
                        'question_count' => $questions->count(),
                    ],
                    'activity_at' => now(),
                ]);
            }

                return response()->json($questions);


        } catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveQuestionStatement(Request $request, ?string $currentStatement = null, $questionId = null): ?string
    {
        $image = $this->questionStatementImage($request);

        if (!$image) {
            return $request->has('question') ? $request->question : $currentStatement;
        }

        if (!$questionId) {
            throw new \InvalidArgumentException('Question ID is required to upload a question statement image.');
        }

        $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg');
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

        if (!in_array($extension, $allowedExtensions, true) || !str_starts_with((string) $image->getMimeType(), 'image/')) {
            throw new \InvalidArgumentException('Question statement image must be a valid image file.');
        }

        $awsUploadService = app(AwsUploadService::class);
        $s3Key = $awsUploadService->uploadFileToS3(
            $image,
            $extension,
            $this->questionStatementImageDirectory($questionId)
        );

        if (!$s3Key) {
            throw new \RuntimeException('Failed to upload question statement image.');
        }

        return $awsUploadService->getS3Url($s3Key);
    }

    private function questionStatementImageDirectory($questionId): string
    {
        return 'questions/' . (int) $questionId . '/question_statement';
    }

    private function hasQuestionStatementImage(Request $request): bool
    {
        return $this->questionStatementImage($request) !== null;
    }

    private function questionStatementImage(Request $request)
    {
        foreach (['question_image', 'question_statement_image', 'statement_image', 'image'] as $field) {
            if ($request->hasFile($field) && $request->file($field)->isValid()) {
                return $request->file($field);
            }
        }

        return null;
    }

    private function deleteManagedQuestionStatementImage(?string $statement): void
    {
        $s3Key = $this->managedQuestionStatementImageKey($statement);

        if (!$s3Key) {
            return;
        }

        app(AwsUploadService::class)->deleteFileFromS3($s3Key);
    }

    private function managedQuestionStatementImageKey(?string $statement): ?string
    {
        $statement = trim((string) $statement);

        if ($statement === '') {
            return null;
        }

        foreach ($this->managedStorageUrlPrefixes() as $prefix) {
            if (str_starts_with($statement, $prefix)) {
                return ltrim(substr($statement, strlen($prefix)), '/');
            }
        }

        if (parse_url($statement, PHP_URL_SCHEME) || parse_url($statement, PHP_URL_HOST)) {
            return null;
        }

        $path = parse_url($statement, PHP_URL_PATH);

        if (!$path) {
            return null;
        }

        $key = ltrim($path, '/');

        if (str_contains($key, 'questions/question_statements/')) {
            return $key;
        }

        return preg_match('#(^|/)questions/\d+/question_statement/#', $key) ? $key : null;
    }

    private function managedStorageUrlPrefixes(): array
    {
        $prefixes = [];

        if (env('CDN_URL')) {
            $prefixes[] = rtrim(env('CDN_URL'), '/') . '/';
        }

        try {
            $s3Url = Storage::disk('s3')->url('');

            if ($s3Url) {
                $prefixes[] = rtrim($s3Url, '/') . '/';
            }
        } catch (\Exception $e) {
            Log::warning('Unable to build S3 URL prefix for question image cleanup.', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique($prefixes));
    }

    private function authenticatedUserId(Request $request): ?int
    {
        $user = $request->user()
            ?: auth('api')->user()
            ?: auth('web_api')->user()
            ?: auth()->user();

        return $user?->id ? (int) $user->id : null;
    }

    private function withAuditColumns(string $table, array $data, ?int $userId, bool $isCreate = true): array
    {
        if (!$userId) {
            return $data;
        }

        if ($isCreate && Schema::hasColumn($table, 'created_by') && !array_key_exists('created_by', $data)) {
            $data['created_by'] = $userId;
        }

        if (Schema::hasColumn($table, 'updated_by') && !array_key_exists('updated_by', $data)) {
            $data['updated_by'] = $userId;
        }

        return $data;
    }

    private function applyQuestionScopeFilter($query, Request $request, string $permissionName): void
    {
        $scopes = $this->questionPermissionScopes($request, $permissionName);

        if ($scopes === null || $scopes->isEmpty()) {
            return;
        }

        $query->where(function ($scopeQuery) use ($scopes) {
            foreach ($scopes as $scope) {
                $scopeId = (int) $scope->scope_id;

                match ($scope->scope_type) {
                    'curriculum_board' => $scopeQuery->orWhere('books.curriculum_board_id', $scopeId),
                    'class' => $scopeQuery->orWhere('books.class_id', $scopeId),
                    'subject' => $scopeQuery->orWhere('books.subject_id', $scopeId),
                    'book' => $scopeQuery->orWhere(function ($bookQuery) use ($scopeId) {
                        $bookQuery->where('q.book_id', $scopeId)
                            ->orWhere('units.book_id', $scopeId);
                    }),
                    'unit' => $scopeQuery->orWhere(function ($unitQuery) use ($scopeId) {
                        $unitQuery->where('q.unit_id', $scopeId)
                            ->orWhere('topics.unit_id', $scopeId);
                    }),
                    'topic' => $scopeQuery->orWhere('q.topic_id', $scopeId),
                    'question_type' => $scopeQuery->orWhere('q.question_type', $scopeId),
                    'cognitive_domain' => $scopeQuery->orWhere('q.cognitive_domain', $scopeId),
                    'topic_content' => $scopeQuery->orWhere('q.topic_content', $scopeId),
                    default => null,
                };
            }
        });
    }

    private function canAccessQuestionScope(Request $request, string $permissionName, ?array $context): bool
    {
        $scopes = $this->questionPermissionScopes($request, $permissionName);

        if ($scopes === null || $scopes->isEmpty()) {
            return true;
        }

        if (!$context) {
            return false;
        }

        foreach ($scopes as $scope) {
            $contextKey = $this->scopeContextKey($scope->scope_type);

            if ($contextKey && isset($context[$contextKey]) && (int) $context[$contextKey] === (int) $scope->scope_id) {
                return true;
            }
        }

        return false;
    }

    private function questionPermissionScopes(Request $request, string $permissionName)
    {
        $user = $request->user() ?: auth()->user();

        if (!$user || !$user->role_id) {
            return collect();
        }

        $user->loadMissing('role');

        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $user->role?->name)));
        if ($roleName === 'super_admin') {
            return null;
        }

        return DB::table('role_permission_scopes as scopes')
            ->join('permissions', 'permissions.id', '=', 'scopes.permission_id')
            ->where('scopes.role_id', $user->role_id)
            ->where('permissions.name', $permissionName)
            ->get(['scopes.scope_type', 'scopes.scope_id']);
    }

    private function questionContextFromRequest(Request $request): ?array
    {
        $context = [
            'topic_id' => $request->filled('topic_id') ? (int) $request->topic_id : null,
            'unit_id' => $request->filled('unit_id') ? (int) $request->unit_id : null,
            'book_id' => $request->filled('book_id') ? (int) $request->book_id : null,
            'question_type' => $request->filled('question_type') ? (int) $request->question_type : null,
            'cognitive_domain' => $request->filled('cognitive_domain') ? (int) $request->cognitive_domain : null,
            'topic_content' => $request->filled('topic_content') ? (int) $request->topic_content : null,
            'subject_id' => null,
            'class_id' => null,
            'curriculum_board_id' => null,
        ];

        if ($context['topic_id']) {
            $topicContext = DB::table('book_unit_topic_tbl as topics')
                ->leftJoin('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->leftJoin('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->where('topics.id', $context['topic_id'])
                ->select(
                    'topics.id as topic_id',
                    'units.id as unit_id',
                    'books.id as book_id',
                    'books.subject_id',
                    'books.class_id',
                    'books.curriculum_board_id'
                )
                ->first();

            if ($topicContext) {
                $context['unit_id'] = $context['unit_id'] ?: (int) $topicContext->unit_id;
                $context['book_id'] = $context['book_id'] ?: (int) $topicContext->book_id;
                $context['subject_id'] = $topicContext->subject_id ? (int) $topicContext->subject_id : null;
                $context['class_id'] = $topicContext->class_id ? (int) $topicContext->class_id : null;
                $context['curriculum_board_id'] = $topicContext->curriculum_board_id ? (int) $topicContext->curriculum_board_id : null;
            }
        }

        if ($context['unit_id'] && !$context['book_id']) {
            $unitContext = DB::table('book_unit_tbl')
                ->where('id', $context['unit_id'])
                ->select('book_id')
                ->first();

            $context['book_id'] = $unitContext?->book_id ? (int) $unitContext->book_id : null;
        }

        if ($context['book_id']) {
            $bookContext = DB::table('book_tbl')
                ->where('id', $context['book_id'])
                ->select('subject_id', 'class_id', 'curriculum_board_id')
                ->first();

            if ($bookContext) {
                $context['subject_id'] = $bookContext->subject_id ? (int) $bookContext->subject_id : null;
                $context['class_id'] = $bookContext->class_id ? (int) $bookContext->class_id : null;
                $context['curriculum_board_id'] = $bookContext->curriculum_board_id ? (int) $bookContext->curriculum_board_id : null;
            }
        }

        return $context;
    }

    private function questionContextById($questionId): ?array
    {
        $question = DB::table('exam_question_tbl as q')
            ->leftJoin('book_unit_topic_tbl as topics', 'topics.id', '=', 'q.topic_id')
            ->leftJoin('book_unit_tbl as units', function ($join) {
                $join->on('units.id', '=', DB::raw('COALESCE(q.unit_id, topics.unit_id)'));
            })
            ->leftJoin('book_tbl as books', function ($join) {
                $join->on('books.id', '=', DB::raw('COALESCE(q.book_id, units.book_id)'));
            })
            ->where('q.id', $questionId)
            ->select(
                'q.topic_id',
                DB::raw('COALESCE(q.unit_id, topics.unit_id) as unit_id'),
                DB::raw('COALESCE(q.book_id, units.book_id) as book_id'),
                'books.subject_id',
                'books.class_id',
                'books.curriculum_board_id',
                'q.question_type',
                'q.cognitive_domain',
                'q.topic_content'
            )
            ->first();

        if (!$question) {
            return null;
        }

        return [
            'topic_id' => $question->topic_id ? (int) $question->topic_id : null,
            'unit_id' => $question->unit_id ? (int) $question->unit_id : null,
            'book_id' => $question->book_id ? (int) $question->book_id : null,
            'subject_id' => $question->subject_id ? (int) $question->subject_id : null,
            'class_id' => $question->class_id ? (int) $question->class_id : null,
            'curriculum_board_id' => $question->curriculum_board_id ? (int) $question->curriculum_board_id : null,
            'question_type' => $question->question_type ? (int) $question->question_type : null,
            'cognitive_domain' => $question->cognitive_domain ? (int) $question->cognitive_domain : null,
            'topic_content' => $question->topic_content ? (int) $question->topic_content : null,
        ];
    }

    private function scopeContextKey(string $scopeType): ?string
    {
        return match ($scopeType) {
            'curriculum_board' => 'curriculum_board_id',
            'class' => 'class_id',
            'subject' => 'subject_id',
            'book' => 'book_id',
            'unit' => 'unit_id',
            'topic' => 'topic_id',
            'question_type' => 'question_type',
            'cognitive_domain' => 'cognitive_domain',
            'topic_content' => 'topic_content',
            default => null,
        };
    }

    
}
