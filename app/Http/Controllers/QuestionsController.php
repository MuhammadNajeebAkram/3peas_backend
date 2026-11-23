<?php

namespace App\Http\Controllers;

use App\Models\ExamQuestion;
use App\Models\StudentActivity;
use App\Http\Services\AwsUploadService;
use App\Http\Services\QuestionAuthoringSecurityService;
use App\Http\Services\Freemium\FreemiumEvaluationResult;
use App\Http\Services\Freemium\DTOs\UnifiedAccessDecision;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class QuestionsController extends Controller
{
    /**
     * Sanitizes corrupt image paths where a relative prefix like "images/" or "images/1/"
     * was accidentally prepended to an absolute URL (e.g. "images/https://...").
     */
    public function sanitizeCorruptImageUrl($val)
    {
        if (!is_string($val) || empty($val)) {
            return $val;
        }
        return preg_replace('/(?:images\/[a-zA-Z0-9_\-\.\/]*|images\/)(https?:\/\/[^"\'\s>]+)/i', '$1', $val);
    }

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
            $hasProvider = Schema::hasTable('ai_providers') && Schema::hasColumn('exam_question_tbl', 'ai_provider_id');
            $hasModel = Schema::hasTable('ai_models') && Schema::hasColumn('exam_question_tbl', 'ai_model_id');

            $query = DB::table('exam_question_tbl as questions')
            ->leftJoin('question_presentation_type_tbl as presentation_types', 'questions.question_presentation_type_id', '=', 'presentation_types.id')
            ->leftJoin('question_scenario_groups_tbl as scenario_groups', 'questions.scenario_group_id', '=', 'scenario_groups.id');

            if ($hasProvider) {
                $query->leftJoin('ai_providers', 'questions.ai_provider_id', '=', 'ai_providers.id');
            }

            if ($hasModel) {
                $query->leftJoin('ai_models', 'questions.ai_model_id', '=', 'ai_models.id');
            }

            $selects = [
                'questions.*',
                'presentation_types.type_name as question_presentation_type_name',
                'presentation_types.code as question_presentation_type_code',
                'presentation_types.allows_multiple_mcqs as question_presentation_type_allows_multiple_mcqs',
                'scenario_groups.title as scenario_title',
                'scenario_groups.scenario_text',
                'scenario_groups.scenario_text_um',
                'scenario_groups.scenario_image',
            ];

            if ($hasProvider) {
                $selects[] = 'ai_providers.name as ai_provider_name';
                $selects[] = 'ai_providers.key as ai_provider_key';
            } else {
                $selects[] = DB::raw('NULL as ai_provider_name');
                $selects[] = DB::raw('NULL as ai_provider_key');
            }

            if ($hasModel) {
                $selects[] = 'ai_models.name as ai_model_name';
                $selects[] = 'ai_models.model_key as ai_model_key';
            } else {
                $selects[] = DB::raw('NULL as ai_model_name');
                $selects[] = DB::raw('NULL as ai_model_key');
            }

            $questionData = $query->where('questions.id', '=', $request -> id)
                ->select($selects)
                ->get();

            $question = $questionData -> first();

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Question not found.',
                ], 404);
            }

            if (!empty($question->scenario_text)) {
                $question->scenario_text = $this->sanitizeCorruptImageUrl($question->scenario_text);
            }
            if (!empty($question->scenario_text_um)) {
                $question->scenario_text_um = $this->sanitizeCorruptImageUrl($question->scenario_text_um);
            }
            if (!empty($question->scenario_image)) {
                $question->scenario_image = $this->sanitizeCorruptImageUrl($question->scenario_image);
            }
            if (!empty($question->question)) {
                $question->question = $this->sanitizeCorruptImageUrl($question->question);
            }
            if (!empty($question->question_um)) {
                $question->question_um = $this->sanitizeCorruptImageUrl($question->question_um);
            }
            if (!empty($question->explanation)) {
                $question->explanation = $this->sanitizeCorruptImageUrl($question->explanation);
            }
            if (!empty($question->explanation_um)) {
                $question->explanation_um = $this->sanitizeCorruptImageUrl($question->explanation_um);
            }
            if (!empty($question->diagram)) {
                $question->diagram = $this->sanitizeCorruptImageUrl($question->diagram);
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
                ->get()
                ->map(function ($opt) {
                    if (!empty($opt->text)) {
                        $opt->text = $this->sanitizeCorruptImageUrl($opt->text);
                    }
                    if (!empty($opt->text_um)) {
                        $opt->text_um = $this->sanitizeCorruptImageUrl($opt->text_um);
                    }
                    return $opt;
                });

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
        return $this->questionFilterResponse($request);
    }

    public function exportQuestions(Request $request)
    {
        $rules = ['search' => ['nullable', 'string']];
        foreach (['topic_id', 'question_type', 'cognitive_domain', 'topic_content', 'difficulty',
            'activate', 'is_mcq', 'has_diagram', 'question_presentation_type_id', 'is_alp_question',
            'class_id', 'subject_id', 'curriculum_board_id', 'board_id', 'session_id', 'year',
            'group_id', 'unit_id', 'book_id', 'status', 'ai_provider_id', 'ai_model_id'] as $field) {
            $valueRule = $field === 'status' ? 'string' : 'integer';
            $rules[$field] = ['nullable', is_array($request->input($field)) ? 'array' : $valueRule];
            if (is_array($request->input($field))) {
                $rules[$field . '.*'] = ['nullable', $valueRule];
            }
        }
        $request->validate($rules);

        return $this->questionFilterResponse($request, true);
    }

    private function questionFilterResponse(Request $request, bool $export = false)
    {
        try {
            $hasProviderTable = Schema::hasTable('ai_providers');
            $hasModelTable = Schema::hasTable('ai_models');
            $hasProviderCol = Schema::hasColumn('exam_question_tbl', 'ai_provider_id');
            $hasModelCol = Schema::hasColumn('exam_question_tbl', 'ai_model_id');
            $hasReports = Schema::hasTable('question_reports');

            $allQuestions = DB::table('exam_question_tbl as q')
                ->leftJoin('book_unit_topic_tbl as topics', 'q.topic_id', '=', 'topics.id')
                ->leftJoin('book_unit_tbl as units', function ($join) {
                    $join->on('units.id', '=', DB::raw('COALESCE(q.unit_id, topics.unit_id)'));
                })
                ->leftJoin('book_tbl as books', function ($join) {
                    $join->on('books.id', '=', DB::raw('COALESCE(q.book_id, units.book_id)'));
                })
                ->leftJoin('question_type_tbl as question_types', 'q.question_type', '=', 'question_types.id')
                ->leftJoin('question_presentation_type_tbl as presentation_types', 'q.question_presentation_type_id', '=', 'presentation_types.id')
                ->leftJoin('question_scenario_groups_tbl as scenario_groups', 'q.scenario_group_id', '=', 'scenario_groups.id');

            if ($hasProviderTable && $hasProviderCol) {
                $allQuestions->leftJoin('ai_providers', 'q.ai_provider_id', '=', 'ai_providers.id');
            }

            if ($hasModelTable && $hasModelCol) {
                $allQuestions->leftJoin('ai_models', 'q.ai_model_id', '=', 'ai_models.id');
            }

            $selects = [
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
                Schema::hasColumn('exam_question_tbl', 'source') ? 'q.source' : DB::raw('NULL as source'),
                Schema::hasColumn('exam_question_tbl', 'ai_question_generation_id') ? 'q.ai_question_generation_id' : DB::raw('NULL as ai_question_generation_id'),
                $hasProviderCol ? 'q.ai_provider_id' : DB::raw('NULL as ai_provider_id'),
                $hasModelCol ? 'q.ai_model_id' : DB::raw('NULL as ai_model_id'),
            ];

            if ($hasProviderTable && $hasProviderCol) {
                $selects[] = 'ai_providers.name as ai_provider_name';
                $selects[] = 'ai_providers.key as ai_provider_key';
            } else {
                $selects[] = DB::raw('NULL as ai_provider_name');
                $selects[] = DB::raw('NULL as ai_provider_key');
            }

            if ($hasModelTable && $hasModelCol) {
                $selects[] = 'ai_models.name as ai_model_name';
                $selects[] = 'ai_models.model_key as ai_model_key';
            } else {
                $selects[] = DB::raw('NULL as ai_model_name');
                $selects[] = DB::raw('NULL as ai_model_key');
            }

            $selects = array_merge($selects, [
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
                'presentation_types.allows_multiple_mcqs as question_presentation_type_allows_multiple_mcqs',
                'q.scenario_group_id',
                'q.scenario_question_order',
                'scenario_groups.title as scenario_title',
                'q.is_alp_question',
                'q.created_at',
                'q.updated_at',
                $hasReports
                    ? DB::raw('(SELECT COUNT(*) FROM question_reports WHERE question_reports.question_id = q.id) as reports_count')
                    : DB::raw('0 as reports_count'),
            ]);

            $query = $allQuestions->select($selects);

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
            if (Schema::hasColumn('exam_question_tbl', 'source')) {
                $applyFilter('q.source', $request->input('source'));
            }
            if ($hasProviderCol) {
                $applyFilter('q.ai_provider_id', $request->input('ai_provider_id'));
            }
            if ($hasModelCol) {
                $applyFilter('q.ai_model_id', $request->input('ai_model_id'));
            }
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

            foreach ($export ? ['questions.report.view', 'questions.report.export'] : ['questions.view'] as $permission) {
                $this->applyQuestionScopeFilter($query, $request, $permission);
            }

            if ($export) {
                $query->leftJoin('curriculum_board_tbl as curriculum_boards', 'books.curriculum_board_id', '=', 'curriculum_boards.id')
                    ->leftJoin('class_tbl as classes', 'books.class_id', '=', 'classes.id')
                    ->leftJoin('subject_tbl as subjects', 'books.subject_id', '=', 'subjects.id')
                    ->addSelect('curriculum_boards.name as curriculum_board_name',
                        'classes.class_name', 'subjects.subject_name');
                $query->addSelect('q.question_lang', 'q.question_um_lang',
                    'scenario_groups.scenario_text', 'scenario_groups.scenario_text_um',
                    'scenario_groups.scenario_image');
            }

            $perPage = min((int) $request->input('per_page', 50), 200);

            $query->orderByDesc('q.id');
            $questions = $export ? $query->get() : $query->paginate($perPage);
            $questionCollection = $export ? $questions : $questions->getCollection();

            $questionIds = $questionCollection->pluck('id')->filter()->values();

            if ($questionIds->isNotEmpty()) {
                $boardLinks = DB::table('exam_question_board_tbl as question_boards')
                    ->leftJoin('board_tbl as boards', 'question_boards.board_id', '=', 'boards.id')
                    ->leftJoin('exam_session_tbl as sessions', 'question_boards.session_id', '=', 'sessions.id')
                    ->leftJoin('study_group_tbl as groups', 'question_boards.group_id', '=', 'groups.id')
                    ->whereIntegerInRaw('question_boards.question_id', $questionIds->all())
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

                if (!$export) {
                    $applyBoardFilters($boardLinks);
                }

                $boardLinksByQuestion = $boardLinks
                    ->orderByDesc('question_boards.year')
                    ->orderBy('boards.board_name')
                    ->orderBy('sessions.session_name')
                    ->get()
                    ->groupBy('question_id');

                $questionCollection->transform(function ($question) use ($boardLinksByQuestion) {
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
                    $question->reports_count = (int) ($question->reports_count ?? 0);

                    return $question;
                });
            }

            if ($export) {
                foreach ($questionCollection->chunk(500) as $chunk) {
                    $ids = $chunk->pluck('id')->all();
                    $answers = DB::table('exam_answer_tbl')->whereIn('question_id', $ids)
                        ->orderBy('id')->get()->groupBy('question_id');
                    $options = DB::table('exam_question_options_tbl')->whereIn('question_id', $ids)
                        ->select('id', 'question_id', 'option as text', 'option_um as text_um', 'is_answer as is_correct')
                        ->orderBy('id')->get()->groupBy('question_id');
                    foreach ($chunk as $question) {
                        $question->answers = $answers->get($question->id, collect())->values();
                        $question->options = $options->get($question->id, collect())->values();
                    }
                }

                return response()->json([
                    'success' => 1,
                    'total' => $questionCollection->count(),
                    'questions' => $questionCollection->values(),
                ]);
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

    public function getQuestionScenarioGroups(Request $request)
    {
        try {
            $query = DB::table('question_scenario_groups_tbl as scenario_groups')
                ->leftJoin('question_presentation_type_tbl as presentation_types', 'scenario_groups.question_presentation_type_id', '=', 'presentation_types.id')
                ->leftJoin('book_unit_topic_tbl as topics', 'scenario_groups.topic_id', '=', 'topics.id')
                ->leftJoin('book_unit_tbl as units', function ($join) {
                    $join->on('units.id', '=', DB::raw('COALESCE(scenario_groups.unit_id, topics.unit_id)'));
                })
                ->leftJoin('book_tbl as books', function ($join) {
                    $join->on('books.id', '=', DB::raw('COALESCE(scenario_groups.book_id, units.book_id)'));
                })
                ->leftJoin('exam_question_tbl as questions', 'questions.scenario_group_id', '=', 'scenario_groups.id')
                ->select(
                    'scenario_groups.id',
                    'scenario_groups.title',
                    'scenario_groups.scenario_text',
                    'scenario_groups.scenario_text_um',
                    'scenario_groups.scenario_image',
                    'scenario_groups.question_presentation_type_id',
                    'presentation_types.type_name as question_presentation_type_name',
                    'presentation_types.code as question_presentation_type_code',
                    'presentation_types.allows_multiple_mcqs as question_presentation_type_allows_multiple_mcqs',
                    'scenario_groups.topic_id',
                    'topics.topic_name',
                    'topics.topic_name_um',
                    DB::raw('COALESCE(scenario_groups.unit_id, topics.unit_id) as unit_id'),
                    'units.unit_name',
                    DB::raw('COALESCE(scenario_groups.book_id, units.book_id) as book_id'),
                    'books.book_name',
                    'books.class_id',
                    'books.subject_id',
                    'books.curriculum_board_id',
                    'scenario_groups.activate',
                    'scenario_groups.created_at',
                    'scenario_groups.updated_at',
                    DB::raw('COUNT(questions.id) as questions_count')
                )
                ->groupBy(
                    'scenario_groups.id',
                    'scenario_groups.title',
                    'scenario_groups.scenario_text',
                    'scenario_groups.scenario_text_um',
                    'scenario_groups.scenario_image',
                    'scenario_groups.question_presentation_type_id',
                    'presentation_types.type_name',
                    'presentation_types.code',
                    'presentation_types.allows_multiple_mcqs',
                    'scenario_groups.topic_id',
                    'topics.topic_name',
                    'topics.topic_name_um',
                    'scenario_groups.unit_id',
                    'topics.unit_id',
                    'units.unit_name',
                    'scenario_groups.book_id',
                    'units.book_id',
                    'books.book_name',
                    'books.class_id',
                    'books.subject_id',
                    'books.curriculum_board_id',
                    'scenario_groups.activate',
                    'scenario_groups.created_at',
                    'scenario_groups.updated_at'
                );

            foreach (['id', 'topic_id', 'unit_id', 'book_id', 'question_presentation_type_id'] as $field) {
                if ($request->filled($field)) {
                    $query->where('scenario_groups.' . $field, $request->input($field));
                }
            }

            if ($request->filled('activate')) {
                $query->where('scenario_groups.activate', $request->boolean('activate'));
            }

            $scenarioGroups = $query
                ->orderBy('scenario_groups.updated_at', 'desc')
                ->orderBy('scenario_groups.id', 'desc')
                ->get();

            return response()->json([
                'success' => 1,
                'scenario_groups' => $scenarioGroups,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve question scenario groups.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getQuestionScenarioGroupById(Request $request)
    {
        try {
            $request->validate([
                'id' => ['required', 'integer', 'exists:question_scenario_groups_tbl,id'],
            ]);

            $hasProvider = Schema::hasTable('ai_providers') && Schema::hasColumn('question_scenario_groups_tbl', 'ai_provider_id');
            $hasModel = Schema::hasTable('ai_models') && Schema::hasColumn('question_scenario_groups_tbl', 'ai_model_id');

            $query = DB::table('question_scenario_groups_tbl as scenario_groups')
                ->leftJoin('question_presentation_type_tbl as presentation_types', 'scenario_groups.question_presentation_type_id', '=', 'presentation_types.id');

            if ($hasProvider) {
                $query->leftJoin('ai_providers', 'scenario_groups.ai_provider_id', '=', 'ai_providers.id');
            }

            if ($hasModel) {
                $query->leftJoin('ai_models', 'scenario_groups.ai_model_id', '=', 'ai_models.id');
            }

            $selects = [
                'scenario_groups.*',
                'presentation_types.type_name as question_presentation_type_name',
                'presentation_types.code as question_presentation_type_code',
                'presentation_types.allows_multiple_mcqs as question_presentation_type_allows_multiple_mcqs',
            ];

            if ($hasProvider) {
                $selects[] = 'ai_providers.name as ai_provider_name';
                $selects[] = 'ai_providers.key as ai_provider_key';
            } else {
                $selects[] = DB::raw('NULL as ai_provider_name');
                $selects[] = DB::raw('NULL as ai_provider_key');
            }

            if ($hasModel) {
                $selects[] = 'ai_models.name as ai_model_name';
                $selects[] = 'ai_models.model_key as ai_model_key';
            } else {
                $selects[] = DB::raw('NULL as ai_model_name');
                $selects[] = DB::raw('NULL as ai_model_key');
            }

            $scenarioGroup = $query->where('scenario_groups.id', $request->id)
                ->select($selects)
                ->first();

            if ($scenarioGroup) {
                if (!empty($scenarioGroup->scenario_text)) {
                    $scenarioGroup->scenario_text = $this->sanitizeCorruptImageUrl($scenarioGroup->scenario_text);
                }
                if (!empty($scenarioGroup->scenario_text_um)) {
                    $scenarioGroup->scenario_text_um = $this->sanitizeCorruptImageUrl($scenarioGroup->scenario_text_um);
                }
                if (!empty($scenarioGroup->scenario_image)) {
                    $scenarioGroup->scenario_image = $this->sanitizeCorruptImageUrl($scenarioGroup->scenario_image);
                }
            }





            $questions = DB::table('exam_question_tbl as questions')
                ->leftJoin('question_type_tbl as question_types', 'questions.question_type', '=', 'question_types.id')
                ->where('questions.scenario_group_id', $request->id)
                ->select(
                    'questions.id',
                    'questions.question',
                    'questions.question_um',
                    'questions.topic_id',
                    'questions.question_type',
                    'question_types.type_name',
                    'questions.marks',
                    'questions.difficulty',
                    'questions.activate',
                    'questions.scenario_question_order'
                )
                ->orderBy('questions.scenario_question_order')
                ->orderBy('questions.id')
                ->get();

            $options = DB::table('exam_question_options_tbl')
                ->whereIn('question_id', $questions->pluck('id'))
                ->select('id', 'question_id', 'option as text', 'option_um as text_um', 'is_answer as is_correct')
                ->orderBy('id')
                ->get()
                ->groupBy('question_id');

            $questions = $questions->map(function ($question) use ($options) {
                $question->options = $options->get($question->id, collect())->values();

                return $question;
            });

            return response()->json([
                'success' => 1,
                'scenario_group' => $scenarioGroup,
                'questions' => $questions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve question scenario group.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveQuestionScenarioGroup(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'scenario_text' => ['required', 'string'],
            'scenario_text_um' => ['nullable', 'string'],
            'scenario_image' => ['nullable', 'string', 'max:255'],
            'question_presentation_type_id' => ['nullable', 'integer', 'exists:question_presentation_type_tbl,id'],
            'topic_id' => ['nullable', 'integer', 'exists:book_unit_topic_tbl,id'],
            'unit_id' => ['nullable', 'integer', 'exists:book_unit_tbl,id'],
            'book_id' => ['nullable', 'integer', 'exists:book_tbl,id'],
            'activate' => ['nullable', 'boolean'],
        ]);

        try {
            if (!$this->canAccessQuestionScope($request, 'questions.create', $this->questionContextFromRequest($request))) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to create scenario groups for the selected scope.',
                ], 403);
            }

            $userId = $this->authenticatedUserId($request);

            $scenarioGroupId = DB::table('question_scenario_groups_tbl')
                ->insertGetId($this->withAuditColumns('question_scenario_groups_tbl', [
                    'title' => $validated['title'] ?? null,
                    'scenario_text' => $validated['scenario_text'],
                    'scenario_text_um' => $validated['scenario_text_um'] ?? null,
                    'scenario_image' => $validated['scenario_image'] ?? null,
                    'question_presentation_type_id' => $validated['question_presentation_type_id'] ?? null,
                    'topic_id' => $validated['topic_id'] ?? null,
                    'unit_id' => $validated['unit_id'] ?? null,
                    'book_id' => $validated['book_id'] ?? null,
                    'activate' => $validated['activate'] ?? 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $userId));

            return response()->json([
                'success' => 1,
                'message' => 'Question scenario group saved successfully.',
                'scenario_group_id' => $scenarioGroupId,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to save question scenario group.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateQuestionScenarioGroup(Request $request, $id = null)
    {
        $scenarioGroupId = $id ?? $request->id;

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'scenario_text' => ['required', 'string'],
            'scenario_text_um' => ['nullable', 'string'],
            'scenario_image' => ['nullable', 'string', 'max:255'],
            'question_presentation_type_id' => ['nullable', 'integer', 'exists:question_presentation_type_tbl,id'],
            'topic_id' => ['nullable', 'integer', 'exists:book_unit_topic_tbl,id'],
            'unit_id' => ['nullable', 'integer', 'exists:book_unit_tbl,id'],
            'book_id' => ['nullable', 'integer', 'exists:book_tbl,id'],
            'activate' => ['nullable', 'boolean'],
        ]);

        try {
            $scenarioGroup = DB::table('question_scenario_groups_tbl')
                ->where('id', $scenarioGroupId)
                ->first();





            if (!$scenarioGroup) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Question scenario group not found.',
                ], 404);
            }

            if (!$this->canAccessQuestionScope($request, 'questions.update', $this->questionContextFromRequest($request))) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to update scenario groups for the selected scope.',
                ], 403);
            }

            $userId = $this->authenticatedUserId($request);

            DB::table('question_scenario_groups_tbl')
                ->where('id', $scenarioGroupId)
                ->update($this->withAuditColumns('question_scenario_groups_tbl', [
                    'title' => $validated['title'] ?? null,
                    'scenario_text' => $validated['scenario_text'],
                    'scenario_text_um' => $validated['scenario_text_um'] ?? null,
                    'scenario_image' => $validated['scenario_image'] ?? null,
                    'question_presentation_type_id' => $validated['question_presentation_type_id'] ?? null,
                    'topic_id' => $validated['topic_id'] ?? null,
                    'unit_id' => $validated['unit_id'] ?? null,
                    'book_id' => $validated['book_id'] ?? null,
                    'activate' => array_key_exists('activate', $validated)
                        ? $validated['activate']
                        : $scenarioGroup->activate,
                    'updated_at' => now(),
                ], $userId, false));

            return response()->json([
                'success' => 1,
                'message' => 'Question scenario group updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update question scenario group.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activateQuestionScenarioGroup(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:question_scenario_groups_tbl,id'],
            'activate' => ['required', 'boolean'],
        ]);

        try {
            $context = $this->scenarioGroupContextById($validated['id']);
            if (!$this->canAccessQuestionScope($request, 'questions.activate', $context)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You are not allowed to update question scenario group status.',
                ], 403);
            }

            $userId = $this->authenticatedUserId($request);

            DB::table('question_scenario_groups_tbl')
                ->where('id', $validated['id'])
                ->update($this->withAuditColumns('question_scenario_groups_tbl', [
                    'activate' => $validated['activate'],
                    'updated_at' => now(),
                ], $userId, false));

            return response()->json([
                'success' => 1,
                'message' => 'Question scenario group status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update question scenario group status.',
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
            $scenarioData = $this->resolveQuestionScenarioData($request);

            $questionInsert = [
                'question' => $request -> question,
                'question_um' => $request -> question_um,
                'topic_id' => $request -> topic_id,
                'question_type' => $request -> question_type,
                'exercise_question' => $request->has('exercise_question') ? ($request->exercise_question ? 1 : 0) : 0,
                'marks' => $request->marks !== null && $request->marks !== ''
                    ? round((float) $request->marks, 2)
                    : ($request->is_mcq ? 1.00 : 3.00),
                'difficulty' => $request->difficulty ?? 3,
                'question_lang' => $request->has('question_lang') ? ($request->question_lang ? 1 : 0) : 0,
                'question_um_lang' => $request->has('question_um_lang') ? ($request->question_um_lang ? 1 : 0) : 1,
                'cognitive_domain' => $request->cognitive_domain ?? 1,
                'topic_content' => $request->topic_content ?? 1,
                'status' => $status,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedAt,
                'explanation' => $request->explanation,
                'explanation_um' => $request->explanation_um,
                'explanation_video_url' => $request->explanation_video_url,
                'book_id' => $request->book_id,
                'unit_id' => $request->unit_id,
                'activate' => $request->has('activate')
                    ? ($request->activate ? 1 : 0)
                    : ($status !== 'archived' ? 1 : 0),
                'is_mcq' => $request -> is_mcq ? 1 : 0,
                'has_diagram' => $request->has('has_diagram') ? ($request->has_diagram ? 1 : 0) : 0,
                'question_presentation_type_id' => $scenarioData['question_presentation_type_id'],
                'scenario_group_id' => $scenarioData['scenario_group_id'],
                'scenario_question_order' => $scenarioData['scenario_question_order'] ?? 0,
                'is_alp_question' => $request->has('is_alp_question') ? ($request->is_alp_question ? 1 : 0) : 1,
                'created_at' => now(),
                'updated_at' => now(), 
            ];

            if (Schema::hasColumn('exam_question_tbl', 'ai_provider_id')) {
                $questionInsert['ai_provider_id'] = $request->filled('ai_provider_id') ? (int) $request->ai_provider_id : null;
            }
            if (Schema::hasColumn('exam_question_tbl', 'ai_model_id')) {
                $questionInsert['ai_model_id'] = $request->filled('ai_model_id') ? (int) $request->ai_model_id : null;
            }

            $question = DB::table('exam_question_tbl')
            ->insertGetId($this->withAuditColumns('exam_question_tbl', $questionInsert, $userId));

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
                    'answer_lang' => $request->answer_lang ?? 0,
                    'answer_um_lang' => $request->answer_um_lang ?? 1,
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

            return response()->json(['success' => true, 'message' => 'Question and options saved successfully.', 'question_id' => $question]);



        }
        catch(\Exception $e){

            DB::rollBack();

            

            return response()->json([
                'success' => false,
                'message' => $e instanceof \InvalidArgumentException
                    ? $e->getMessage()
                    : 'Error saving question.',
                'error' => $e->getMessage(),
            ], $e instanceof \InvalidArgumentException ? 422 : 500);

        }
    }

    public function validateImportPackage(Request $request)
    {
        $request->validate([
            'package' => ['nullable', 'file', 'max:25600'], // max 25MB
            'json_content' => ['nullable', 'string', 'max:2000000'],
        ]);

        try {
            $questions = [];
            $stimulus = null;
            $archiveImages = [];

            if ($request->hasFile('package')) {
                $zipFile = $request->file('package');
                $zip = new \ZipArchive();
                $res = $zip->open($zipFile->getRealPath());

                if ($res !== true) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to open ZIP archive. Please ensure it is a valid zip file.',
                    ], 422);
                }

                $jsonFileContent = null;

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);

                    // Path traversal protection
                    if (
                        str_contains($entryName, '..')
                        || str_starts_with($entryName, '/')
                        || str_starts_with($entryName, '\\')
                        || str_contains($entryName, "\0")
                    ) {
                        $zip->close();
                        return response()->json([
                            'success' => false,
                            'message' => "Unsafe entry path detected in archive: {$entryName}",
                        ], 422);
                    }

                    // Ignore macOS / hidden metadata files
                    if (str_contains($entryName, '__MACOSX') || basename($entryName) === '.DS_Store' || str_starts_with(basename($entryName), '._')) {
                        continue;
                    }

                    if (basename($entryName) === 'questions.json') {
                        $jsonFileContent = $zip->getFromIndex($i);
                    } elseif (preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $entryName)) {
                        $archiveImages[] = $entryName;
                    }
                }

                $zip->close();

                if (!$jsonFileContent) {
                    return response()->json([
                        'success' => false,
                        'message' => "Archive must contain a 'questions.json' file.",
                    ], 422);
                }

                $decoded = json_decode($jsonFileContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Malformed questions.json: ' . json_last_error_msg(),
                    ], 422);
                }

                $normalizePackage = function ($decoded) {
                    $questions = [];
                    $stimuli = [];
                    if (is_array($decoded) && isset($decoded['scenarios']) && is_array($decoded['scenarios'])) {
                        foreach ($decoded['scenarios'] as $sc) {
                            if (!empty($sc['stimulus'])) $stimuli[] = $sc['stimulus'];
                            elseif (!empty($sc['scenario'])) $stimuli[] = $sc['scenario'];
                            if (!empty($sc['questions']) && is_array($sc['questions'])) {
                                foreach ($sc['questions'] as $q) $questions[] = $q;
                            }
                        }
                    } elseif (is_array($decoded) && array_is_list($decoded) && !empty($decoded[0]['questions'])) {
                        foreach ($decoded as $sc) {
                            if (!empty($sc['stimulus'])) $stimuli[] = $sc['stimulus'];
                            elseif (!empty($sc['scenario'])) $stimuli[] = $sc['scenario'];
                            if (!empty($sc['questions']) && is_array($sc['questions'])) {
                                foreach ($sc['questions'] as $q) $questions[] = $q;
                            }
                        }
                    } elseif (is_array($decoded) && isset($decoded['questions']) && is_array($decoded['questions'])) {
                        $questions = $decoded['questions'];
                        if (!empty($decoded['stimulus'])) $stimuli[] = $decoded['stimulus'];
                        elseif (!empty($decoded['scenario'])) $stimuli[] = $decoded['scenario'];
                    } elseif (is_array($decoded) && array_is_list($decoded)) {
                        $questions = $decoded;
                    }
                    return [$questions, $stimuli];
                };

                [$questions, $allStimuli] = $normalizePackage($decoded);
                if (empty($questions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'questions.json must contain an array of questions or an object with a "questions" array.',
                    ], 422);
                }
            } elseif ($request->filled('json_content')) {
                $decoded = json_decode($request->json_content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Malformed JSON: ' . json_last_error_msg(),
                    ], 422);
                }

                $normalizePackage = function ($decoded) {
                    $questions = [];
                    $stimuli = [];
                    if (is_array($decoded) && isset($decoded['scenarios']) && is_array($decoded['scenarios'])) {
                        foreach ($decoded['scenarios'] as $sc) {
                            if (!empty($sc['stimulus'])) $stimuli[] = $sc['stimulus'];
                            elseif (!empty($sc['scenario'])) $stimuli[] = $sc['scenario'];
                            if (!empty($sc['questions']) && is_array($sc['questions'])) {
                                foreach ($sc['questions'] as $q) $questions[] = $q;
                            }
                        }
                    } elseif (is_array($decoded) && array_is_list($decoded) && !empty($decoded[0]['questions'])) {
                        foreach ($decoded as $sc) {
                            if (!empty($sc['stimulus'])) $stimuli[] = $sc['stimulus'];
                            elseif (!empty($sc['scenario'])) $stimuli[] = $sc['scenario'];
                            if (!empty($sc['questions']) && is_array($sc['questions'])) {
                                foreach ($sc['questions'] as $q) $questions[] = $q;
                            }
                        }
                    } elseif (is_array($decoded) && isset($decoded['questions']) && is_array($decoded['questions'])) {
                        $questions = $decoded['questions'];
                        if (!empty($decoded['stimulus'])) $stimuli[] = $decoded['stimulus'];
                        elseif (!empty($decoded['scenario'])) $stimuli[] = $decoded['scenario'];
                    } elseif (is_array($decoded) && array_is_list($decoded)) {
                        $questions = $decoded;
                    }
                    return [$questions, $stimuli];
                };

                [$questions, $allStimuli] = $normalizePackage($decoded);
                if (empty($questions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'JSON must be an array of questions or an object with a "questions" array.',
                    ], 422);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Either a package ZIP file or json_content is required.',
                ], 422);
            }

            if (empty($questions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The questions list cannot be empty.',
                ], 422);
            }

            if (count($questions) > 100) {
                return response()->json([
                    'success' => false,
                    'message' => 'A single import package may contain at most 100 questions.',
                ], 422);
            }

            // Extract relative image references
            $referencedImages = [];
            $collectImageRefs = function ($text, $isExplicit = false) use (&$referencedImages) {
                if (!is_string($text) || empty($text)) return;
                $trimmed = trim($text);
                if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'data:')) {
                    return;
                }

                // 1. Explicit diagram/image fields
                if ($isExplicit) {
                    if (preg_match('/^(?:images\/|\.\/images\/|\.\/)?[a-zA-Z0-9_\-\.\/]+\.(?:png|jpe?g|webp|gif|svg)$/i', $trimmed)) {
                        $referencedImages[] = ltrim(str_replace('\\', '/', $trimmed), './');
                    }
                    return;
                }

                // 2. In rich text: ONLY match actual <img src="..."> tags
                if (preg_match_all('/<img\s+[^>]*?src=["\']([^"\']+)["\'][^>]*?>/i', $text, $matches)) {
                    foreach ($matches[1] as $ref) {
                        $refClean = trim($ref);
                        if (!str_starts_with($refClean, 'http://') && !str_starts_with($refClean, 'https://') && !str_starts_with($refClean, 'data:')) {
                            $referencedImages[] = ltrim(str_replace('\\', '/', $refClean), './');
                        }
                    }
                }

                // 3. Match markdown images ![alt](path)
                if (preg_match_all('/!\[.*?\]\(([^)]+)\)/i', $text, $matches)) {
                    foreach ($matches[1] as $ref) {
                        $refClean = trim($ref);
                        if (!str_starts_with($refClean, 'http://') && !str_starts_with($refClean, 'https://') && !str_starts_with($refClean, 'data:')) {
                            $referencedImages[] = ltrim(str_replace('\\', '/', $refClean), './');
                        }
                    }
                }

                // 4. Standalone package image path explicitly starting with images/
                if (preg_match_all('/(?:^|[\s"\'<(])((?:\.\/)?images\/[a-zA-Z0-9_\-\.\/]+\.(?:png|jpe?g|webp|gif|svg))(?:$|[\s"\'>)])/i', $text, $matches)) {
                    foreach ($matches[1] as $ref) {
                        $referencedImages[] = ltrim(str_replace('\\', '/', trim($ref)), './');
                    }
                }
            };

            foreach ($questions as $q) {
                $collectImageRefs($q['question'] ?? null, false);
                $collectImageRefs($q['question_um'] ?? null, false);
                $collectImageRefs($q['explanation'] ?? null, false);
                $collectImageRefs($q['explanation_um'] ?? null, false);
                $collectImageRefs($q['diagram'] ?? null, true);
                $collectImageRefs($q['diagram_url'] ?? null, true);
                $collectImageRefs($q['image'] ?? null, true);
                $collectImageRefs($q['question_diagram'] ?? null, true);
                if (!empty($q['options']) && is_array($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        if (is_array($opt)) {
                            $collectImageRefs($opt['text'] ?? null, false);
                            $collectImageRefs($opt['option'] ?? null, false);
                            $collectImageRefs($opt['image'] ?? null, true);
                        }
                    }
                }
            }

            if (!empty($allStimuli)) {
                foreach ($allStimuli as $stimulus) {
                    $collectImageRefs($stimulus['passage'] ?? null, false);
                    $collectImageRefs($stimulus['scenario_text'] ?? null, false);
                    $collectImageRefs($stimulus['diagram_url'] ?? null, true);
                    $collectImageRefs($stimulus['scenario_image'] ?? null, true);
                    $collectImageRefs($stimulus['image_url'] ?? null, true);
                }
            }

            $referencedImages = array_values(array_unique($referencedImages));
            $cleanArchiveImages = array_values(array_unique(array_map(fn ($img) => ltrim(str_replace('\\', '/', $img), './'), $archiveImages)));
            $archiveBasenames = array_map(fn ($img) => basename($img), $cleanArchiveImages);

            $missingImages = [];
            foreach ($referencedImages as $ref) {
                $cleanRef = ltrim(str_replace('\\', '/', $ref), './');
                $refBase = basename($cleanRef);
                $matched = in_array($cleanRef, $cleanArchiveImages, true) || in_array($refBase, $archiveBasenames, true);
                if (!$matched) {
                    foreach ($cleanArchiveImages as $archImg) {
                        if (str_ends_with($archImg, '/' . $cleanRef) || str_ends_with($archImg, $cleanRef)) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if (!$matched) {
                    $missingImages[] = $ref;
                }
            }

            $unusedImages = [];
            $referencedBasenames = array_map(fn ($ref) => basename($ref), $referencedImages);
            foreach ($cleanArchiveImages as $archImg) {
                $archBase = basename($archImg);
                if (
                    !in_array($archImg, $referencedImages, true)
                    && !in_array('images/' . $archBase, $referencedImages, true)
                    && !in_array($archBase, $referencedBasenames, true)
                ) {
                    $unusedImages[] = $archImg;
                }
            }

            return response()->json([
                'success' => true,
                'valid' => empty($missingImages),
                'question_count' => count($questions),
                'images_count' => count($archiveImages),
                'referenced_images' => $referencedImages,
                'missing_images' => $missingImages,
                'unused_images' => $unusedImages,
                'has_stimulus' => !empty($stimulus),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Package validation error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkImportQuestions(Request $request)
    {
        $validationRules = [
            'questions' => ['required', 'array', 'min:1', 'max:2000'],
            'book_id' => ['nullable', 'integer', 'exists:book_tbl,id'],
            'unit_id' => ['nullable', 'integer', 'exists:book_unit_tbl,id'],
            'topic_id' => ['nullable', 'integer', 'exists:book_unit_topic_tbl,id'],
            'default_marks' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'default_status' => ['nullable', 'string', 'in:draft,published,under_review,archived'],
            'default_presentation_type_id' => ['nullable', 'integer'],
            'question_presentation_type_id' => ['nullable', 'integer'],
            'auto_create_topics' => ['nullable', 'boolean'],
            'stimulus' => ['nullable', 'array'],
            'scenario' => ['nullable', 'array'],
            'scenarios' => ['nullable', 'array'],
            'import_batch_id' => ['nullable', 'string', 'max:128'],
            'ai_provider_id' => ['nullable', 'integer'],
            'ai_model_id' => ['nullable', 'integer'],
        ];

        if (Schema::hasTable('ai_providers')) {
            $validationRules['ai_provider_id'][] = 'exists:ai_providers,id';
        }
        if (Schema::hasTable('ai_models')) {
            $validationRules['ai_model_id'][] = 'exists:ai_models,id';
        }

        $validated = $request->validate($validationRules);

        $defaultBookId = $validated['book_id'] ?? null;
        $defaultUnitId = $validated['unit_id'] ?? null;
        $defaultTopicId = $validated['topic_id'] ?? null;
        $aiModelId = !empty($validated['ai_model_id']) ? (int) $validated['ai_model_id'] : null;
        $aiProviderId = !empty($validated['ai_provider_id']) ? (int) $validated['ai_provider_id'] : null;
        if ($aiModelId && !$aiProviderId && Schema::hasTable('ai_models') && Schema::hasColumn('ai_models', 'ai_provider_id')) {
            $aiProviderId = (int) DB::table('ai_models')->where('id', $aiModelId)->value('ai_provider_id');
        }
        $defaultMarks = isset($validated['default_marks']) && is_numeric($validated['default_marks'])
            ? (float) $validated['default_marks']
            : null;
        $defaultStatus = $validated['default_status'] ?? 'draft';
        $defaultPresentationTypeId = !empty($validated['default_presentation_type_id'])
            ? (int) $validated['default_presentation_type_id']
            : (!empty($validated['question_presentation_type_id'])
                ? (int) $validated['question_presentation_type_id']
                : (!empty($validated['stimulus']['question_presentation_type_id'])
                    ? (int) $validated['stimulus']['question_presentation_type_id']
                    : (!empty($validated['scenario']['question_presentation_type_id'])
                        ? (int) $validated['scenario']['question_presentation_type_id']
                        : null)));
        $autoCreateTopics = $request->boolean('auto_create_topics', true);
        $userId = $this->authenticatedUserId($request);

        // Resolve or create Stimulus / Scenario Group(s)
        $defaultPassagePresTypeId = DB::table('question_presentation_type_tbl')
            ->where('activate', 1)
            ->where('allows_multiple_mcqs', 1)
            ->value('id') ?? 1;

        $createdScenarioGroupsMap = [];

        $sanitizeCorruptImageUrl = function ($val) {
            if (!is_string($val) || empty($val)) {
                return $val;
            }
            return preg_replace('/(?:images\/[a-zA-Z0-9_\-\.\/]*|images\/)(https?:\/\/[^"\'\s>]+)/i', '$1', $val);
        };

        $resolveScenarioGroup = function ($stimData, $groupKey) use (
            &$createdScenarioGroupsMap,
            $defaultPresentationTypeId,
            $defaultPassagePresTypeId,
            $defaultBookId,
            $defaultUnitId,
            $defaultTopicId,
            $userId,
            $sanitizeCorruptImageUrl,
            $aiProviderId,
            $aiModelId
        ) {
            if (empty($stimData) || !is_array($stimData)) {
                return null;
            }

            if (!empty($stimData['scenario_group_id'])) {
                return (int) $stimData['scenario_group_id'];
            }

            if (isset($createdScenarioGroupsMap[$groupKey])) {
                return $createdScenarioGroupsMap[$groupKey];
            }

            $scenarioText = $sanitizeCorruptImageUrl($stimData['scenario_text'] ?? $stimData['passage'] ?? '');
            $scenarioTitle = $stimData['title'] ?? ('Imported Scenario ' . now()->format('Y-m-d H:i'));

            if (empty($scenarioText) && empty($scenarioTitle)) {
                return null;
            }

            $presTypeId = $stimData['question_presentation_type_id']
                ?? $defaultPresentationTypeId
                ?? $defaultPassagePresTypeId;

            $groupFields = [
                'title' => $scenarioTitle,
                'scenario_text' => $scenarioText,
                'scenario_text_um' => $sanitizeCorruptImageUrl($stimData['scenario_text_um'] ?? $stimData['passage_um'] ?? null),
                'scenario_image' => $sanitizeCorruptImageUrl($stimData['scenario_image'] ?? $stimData['diagram_url'] ?? $stimData['image_url'] ?? null),
                'question_presentation_type_id' => $presTypeId,
                'topic_id' => $defaultTopicId,
                'unit_id' => $defaultUnitId,
                'book_id' => $defaultBookId,
                'activate' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('question_scenario_groups_tbl', 'ai_provider_id')) {
                $groupFields['ai_provider_id'] = !empty($stimData['ai_provider_id']) ? (int) $stimData['ai_provider_id'] : $aiProviderId;
            }
            if (Schema::hasColumn('question_scenario_groups_tbl', 'ai_model_id')) {
                $groupFields['ai_model_id'] = !empty($stimData['ai_model_id']) ? (int) $stimData['ai_model_id'] : $aiModelId;
            }

            $newGroupId = DB::table('question_scenario_groups_tbl')->insertGetId($this->withAuditColumns('question_scenario_groups_tbl', $groupFields, $userId));

            $createdScenarioGroupsMap[$groupKey] = $newGroupId;
            return $newGroupId;
        };

        // If top-level single stimulus is present
        $singleStimulus = $request->input('stimulus') ?: $request->input('scenario');
        $singleScenarioGroupId = null;
        if ($singleStimulus && is_array($singleStimulus)) {
            $singleScenarioGroupId = $resolveScenarioGroup($singleStimulus, 'root_stimulus');
        }

        // If scenarios array was passed
        $scenariosInput = $request->input('scenarios');
        if (is_array($scenariosInput)) {
            foreach ($scenariosInput as $scIdx => $scItem) {
                $scData = $scItem['stimulus'] ?? $scItem;
                $resolveScenarioGroup($scData, 'sc_' . $scIdx);
            }
        }

        $results = [];
        $importedCount = 0;
        $failedCount = 0;
        $createdTopicsMap = [];
        $newlyCreatedTopicNames = [];

        foreach ($request->questions as $index => $qData) {
            try {
                if (empty($qData['question']) && empty($qData['question_um'])) {
                    throw new \InvalidArgumentException('Question statement cannot be empty.');
                }

                // Idempotency check: if client_id was provided, check if already imported in this session
                $clientId = !empty($qData['client_id']) ? (string) $qData['client_id'] : null;
                if ($clientId) {
                    $cacheKey = 'bulk_q_import_' . $userId . '_' . md5($clientId);
                    $existingQuestionId = Cache::get($cacheKey);
                    if ($existingQuestionId && DB::table('exam_question_tbl')->where('id', $existingQuestionId)->exists()) {
                        $importedCount++;
                        $results[] = [
                            'index' => $index,
                            'status' => 'success',
                            'question_id' => (int) $existingQuestionId,
                            'already_imported' => true,
                        ];
                        continue;
                    }
                }

                $bookId = !empty($qData['book_id']) ? (int) $qData['book_id'] : $defaultBookId;
                $unitId = !empty($qData['unit_id']) ? (int) $qData['unit_id'] : $defaultUnitId;
                $rawTopicName = trim((string) ($qData['new_topic_name'] ?? $qData['topic_name'] ?? $qData['topic'] ?? ''));
                $topicId = !empty($qData['topic_id']) ? (int) $qData['topic_id'] : null;

                if (!empty($rawTopicName) && $unitId) {
                    $normName = strtolower($rawTopicName);

                    if (isset($createdTopicsMap[$normName])) {
                        $topicId = $createdTopicsMap[$normName];
                    } else {
                        $existingTopic = DB::table('book_unit_topic_tbl')
                            ->where('unit_id', $unitId)
                            ->where(function ($query) use ($rawTopicName, $normName) {
                                $query->where('topic_name', $rawTopicName)
                                      ->orWhereRaw('LOWER(topic_name) = ?', [$normName]);
                            })
                            ->first();





                        if ($existingTopic) {
                            $topicId = $existingTopic->id;
                            $createdTopicsMap[$normName] = $topicId;
                        } elseif ($autoCreateTopics) {
                            $maxTopicNo = (int) (DB::table('book_unit_topic_tbl')
                                ->where('unit_id', $unitId)
                                ->max('topic_no') ?? 0);

                            $newTopicId = DB::table('book_unit_topic_tbl')->insertGetId([
                                'unit_id' => $unitId,
                                'topic_name' => $rawTopicName,
                                'topic_name_um' => $qData['topic_name_um'] ?? null,
                                'topic_no' => $maxTopicNo + 1,
                                'slo_no' => null,
                                'activate' => 1,
                                'is_alp' => 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $topicId = $newTopicId;
                            $createdTopicsMap[$normName] = $newTopicId;
                            $newlyCreatedTopicNames[] = $rawTopicName;
                        }
                    }
                }

                if (!$topicId) {
                    $topicId = $defaultTopicId;
                }

                $isMcq = (!empty($qData['options']) && is_array($qData['options']) && count($qData['options']) >= 2)
                    || (!empty($qData['is_mcq']) && $qData['is_mcq'] !== 'false' && $qData['is_mcq'] !== 0)
                    || ((int) ($qData['question_type'] ?? 0) === 1 && !empty($qData['options']));

                $isStimulus = !empty($qData['scenario_group_id'])
                    || isset($qData['scenario_group_index'])
                    || !empty($qData['stimulus'])
                    || !empty($singleScenarioGroupId);

                $defaultQuestionMarks = $defaultMarks !== null
                    ? (float) $defaultMarks
                    : ($isMcq ? ($isStimulus ? 0.5 : 1.0) : 3.0);

                $resolvedMarks = isset($qData['marks']) && is_numeric($qData['marks'])
                    ? round((float) $qData['marks'], 2)
                    : $defaultQuestionMarks;

                // If stimulus MCQ and defaultMarks is omitted, ensure it defaults to 0.5 even if raw marks was 1
                if ($isStimulus && $isMcq && $defaultMarks === null && isset($qData['marks']) && (float) $qData['marks'] === 1.0) {
                    $resolvedMarks = 0.5;
                }

                $subData = array_merge($qData, [
                    'book_id' => $bookId,
                    'unit_id' => $unitId,
                    'topic_id' => $topicId,
                    'status' => $qData['status'] ?? $defaultStatus,
                    'is_mcq' => $isMcq ? 1 : 0,
                    'question_type' => $qData['question_type'] ?? ($isMcq ? 1 : 2),
                    'exercise_question' => !empty($qData['exercise_question']) ? 1 : 0,
                    'is_alp_question' => isset($qData['is_alp_question']) ? ($qData['is_alp_question'] ? 1 : 0) : 1,
                    'marks' => $resolvedMarks,
                    'difficulty' => !empty($qData['difficulty']) ? (int) $qData['difficulty'] : 3,
                    'cognitive_domain' => !empty($qData['cognitive_domain']) ? (int) $qData['cognitive_domain'] : 1,
                    'topic_content' => !empty($qData['topic_content']) ? (int) $qData['topic_content'] : ($isMcq ? 1 : 2),
                    'question_lang' => !empty($qData['question_lang']) ? 1 : 0,
                    'question_um_lang' => isset($qData['question_um_lang']) ? ($qData['question_um_lang'] ? 1 : 0) : 1,
                ]);

                $qPresTypeId = !empty($qData['question_presentation_type_id'])
                    ? (int) $qData['question_presentation_type_id']
                    : $defaultPresentationTypeId;

                if ($isMcq) {
                    if (!$qPresTypeId) {
                        throw new \InvalidArgumentException("Question #" . ($index + 1) . " requires a question presentation type for MCQs.");
                    }
                    $presType = DB::table('question_presentation_type_tbl')
                        ->where('id', $qPresTypeId)
                        ->first();
                    if (!$presType || !(bool) $presType->activate) {
                        throw new \InvalidArgumentException("Question #" . ($index + 1) . " has an invalid or inactive presentation type.");
                    }
                    $subData['question_presentation_type_id'] = $qPresTypeId;
                } elseif ($qPresTypeId) {
                    $subData['question_presentation_type_id'] = $qPresTypeId;
                }

                // Attach stimulus / scenario group if present
                $qScenarioGroupId = null;
                if (!empty($qData['scenario_group_id'])) {
                    $qScenarioGroupId = (int) $qData['scenario_group_id'];
                } elseif (isset($qData['scenario_group_index']) && isset($createdScenarioGroupsMap['sc_' . $qData['scenario_group_index']])) {
                    $qScenarioGroupId = $createdScenarioGroupsMap['sc_' . $qData['scenario_group_index']];
                } elseif (!empty($qData['stimulus']) && is_array($qData['stimulus'])) {
                    $scKey = 'q_stimulus_' . md5($qData['stimulus']['title'] ?? $qData['stimulus']['passage'] ?? (string) $index);
                    $qScenarioGroupId = $resolveScenarioGroup($qData['stimulus'], $scKey);
                } elseif ($singleScenarioGroupId) {
                    $qScenarioGroupId = $singleScenarioGroupId;
                }

                if ($qScenarioGroupId) {
                    $subData['scenario_group_id'] = $qScenarioGroupId;
                    $subData['scenario_question_order'] = $qData['scenario_question_order'] ?? ($index + 1);
                    if ($presentationTypeId = $subData['question_presentation_type_id'] ?? $defaultPresentationTypeId ?? $defaultPassagePresTypeId) {
                        $subData['question_presentation_type_id'] = $presentationTypeId;
                    }

                    if ($topicId) {
                        DB::table('question_scenario_groups_tbl')
                            ->where('id', $qScenarioGroupId)
                            ->where(function ($q) use ($defaultTopicId) {
                                $q->whereNull('topic_id');
                                if ($defaultTopicId) {
                                    $q->orWhere('topic_id', $defaultTopicId);
                                }
                            })
                            ->update(['topic_id' => $topicId]);
                    }
                }

                // Set diagram flag
                if (!empty($qData['diagram']) || !empty($qData['diagram_url']) || !empty($qData['question_diagram']) || preg_match('/<img/i', (string) ($qData['question'] ?? ''))) {
                    $subData['has_diagram'] = 1;
                }

                // Model answer for non-MCQ
                if (!$isMcq && (!empty($qData['answer']) || !empty($qData['answer_um']))) {
                    $subData['answer'] = $qData['answer'] ?? '';
                    $subData['answer_um'] = $qData['answer_um'] ?? null;
                }

                // Normalize options
                if (!empty($subData['options']) && is_array($subData['options'])) {
                    $formattedOptions = [];
                    foreach ($subData['options'] as $opt) {
                        if (is_string($opt)) {
                            $formattedOptions[] = [
                                'text' => $opt,
                                'text_um' => null,
                                'is_correct' => false,
                            ];
                        } elseif (is_array($opt)) {
                            $formattedOptions[] = [
                                'text' => $sanitizeCorruptImageUrl($opt['text'] ?? $opt['option'] ?? ''),
                                'text_um' => $sanitizeCorruptImageUrl($opt['text_um'] ?? $opt['option_um'] ?? null),
                                'is_correct' => !empty($opt['is_correct']) || !empty($opt['is_answer']),
                            ];
                        }
                    }
                    $subData['options'] = $formattedOptions;
                }

                if (!empty($subData['question'])) {
                    $subData['question'] = $sanitizeCorruptImageUrl($subData['question']);
                }
                if (!empty($subData['question_um'])) {
                    $subData['question_um'] = $sanitizeCorruptImageUrl($subData['question_um']);
                }
                if (!empty($subData['explanation'])) {
                    $subData['explanation'] = $sanitizeCorruptImageUrl($subData['explanation']);
                }
                if (!empty($subData['explanation_um'])) {
                    $subData['explanation_um'] = $sanitizeCorruptImageUrl($subData['explanation_um']);
                }
                if (!empty($subData['diagram'])) {
                    $subData['diagram'] = $sanitizeCorruptImageUrl($subData['diagram']);
                }

                $qAiModelId = !empty($qData['ai_model_id']) ? (int) $qData['ai_model_id'] : $aiModelId;
                $qAiProviderId = !empty($qData['ai_provider_id']) ? (int) $qData['ai_provider_id'] : $aiProviderId;
                if ($qAiModelId && !$qAiProviderId && Schema::hasTable('ai_models') && Schema::hasColumn('ai_models', 'ai_provider_id')) {
                    $qAiProviderId = (int) DB::table('ai_models')->where('id', $qAiModelId)->value('ai_provider_id');
                }
                $subData['ai_provider_id'] = $qAiProviderId;
                $subData['ai_model_id'] = $qAiModelId;

                $subRequest = new Request();
                $subRequest->merge($subData);
                $subRequest->setUserResolver(fn () => $request->user());

                $res = $this->saveQuestion($subRequest);
                $resData = $res->getData(true);

                if (!empty($resData['success']) && !empty($resData['question_id'])) {
                    $questionUpdates = [];
                    if (Schema::hasColumn('exam_question_tbl', 'source')) {
                        $questionUpdates['source'] = ($qAiModelId || $qAiProviderId) ? 'ai' : 'import';
                    }
                    if (Schema::hasColumn('exam_question_tbl', 'ai_provider_id')) {
                        $questionUpdates['ai_provider_id'] = $qAiProviderId;
                    }
                    if (Schema::hasColumn('exam_question_tbl', 'ai_model_id')) {
                        $questionUpdates['ai_model_id'] = $qAiModelId;
                    }

                    // Auto-align with SLO Topic if slo_code or slo_topic_id is provided
                    $qSloTopicId = !empty($qData['slo_topic_id']) ? (int) $qData['slo_topic_id'] : null;
                    if (!$qSloTopicId && (!empty($qData['slo_code']) || !empty($qData['slo']))) {
                        $rawSlo = trim($qData['slo_code'] ?? $qData['slo']);
                        if (Schema::hasTable('slo_topic_tbl')) {
                            $qSloTopicId = DB::table('slo_topic_tbl')->where('slo_code', $rawSlo)->value('id');
                        }
                    }
                    if ($qSloTopicId && Schema::hasColumn('exam_question_tbl', 'slo_topic_id')) {
                        $questionUpdates['slo_topic_id'] = $qSloTopicId;
                    }
                    if (!empty($questionUpdates)) {
                        DB::table('exam_question_tbl')
                            ->where('id', $resData['question_id'])
                            ->update($questionUpdates);
                    }
                    if ($clientId) {
                        Cache::put('bulk_q_import_' . $userId . '_' . md5($clientId), $resData['question_id'], now()->addHours(6));
                    }
                    $importedCount++;
                    $results[] = [
                        'index' => $index,
                        'status' => 'success',
                        'question_id' => $resData['question_id'],
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'index' => $index,
                        'status' => 'failed',
                        'error' => !empty($resData['error']) ? $resData['error'] : ($resData['message'] ?? 'Question could not be saved.'),
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $uniqueCreatedTopics = array_values(array_unique($newlyCreatedTopicNames));
        $allCreatedScenarioGroupIds = array_values(array_unique(array_filter(array_merge(
            $singleScenarioGroupId ? [$singleScenarioGroupId] : [],
            array_values($createdScenarioGroupsMap)
        ))));
        $allSavedQuestionIds = array_values(array_filter(array_column($results, 'question_id')));

        return response()->json([
            'success' => $importedCount > 0,
            'message' => "Import completed: {$importedCount} saved, {$failedCount} failed." . (count($uniqueCreatedTopics) > 0 ? " (" . count($uniqueCreatedTopics) . " new topic(s) created)" : ""),
            'total' => count($request->questions),
            'imported' => $importedCount,
            'failed' => $failedCount,
            'created_topics' => $uniqueCreatedTopics,
            'created_topic_ids' => array_values(array_unique(array_filter(array_values($createdTopicsMap)))),
            'scenario_group_id' => $singleScenarioGroupId ?? (!empty($createdScenarioGroupsMap) ? reset($createdScenarioGroupsMap) : null),
            'scenario_group_ids' => $allCreatedScenarioGroupIds,
            'question_ids' => $allSavedQuestionIds,
            'results' => $results,
        ]);
    }

    public function rollbackImport(Request $request)
    {
        $validated = $request->validate([
            'question_ids' => ['nullable', 'array'],
            'question_ids.*' => ['integer'],
            'scenario_group_ids' => ['nullable', 'array'],
            'scenario_group_ids.*' => ['integer'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer'],
            'media_keys' => ['nullable', 'array'],
            'media_keys.*' => ['string'],
        ]);

        $questionIds = !empty($validated['question_ids']) ? array_values(array_unique($validated['question_ids'])) : [];
        $scenarioGroupIds = !empty($validated['scenario_group_ids']) ? array_values(array_unique($validated['scenario_group_ids'])) : [];
        $topicIds = !empty($validated['topic_ids']) ? array_values(array_unique($validated['topic_ids'])) : [];
        $mediaKeys = !empty($validated['media_keys']) ? array_values(array_unique($validated['media_keys'])) : [];

        // Safety Guard: Check if any question is already linked to live tests / attempts
        if (!empty($questionIds)) {
            if (Schema::hasTable('test_questions')) {
                $inTests = DB::table('test_questions')->whereIn('question_id', $questionIds)->exists();
                if ($inTests) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot rollback: One or more questions from this batch are already assigned to active tests.',
                    ], 422);
                }
            }
            if (Schema::hasTable('test_attempt_questions')) {
                $inAttempts = DB::table('test_attempt_questions')->whereIn('question_id', $questionIds)->exists();
                if ($inAttempts) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot rollback: Students have already attempted one or more questions from this batch.',
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            $deletedQuestionsCount = 0;
            if (!empty($questionIds)) {
                // Delete question relations
                DB::table('exam_question_board_tbl')->whereIn('question_id', $questionIds)->delete();
                DB::table('exam_question_options_tbl')->whereIn('question_id', $questionIds)->delete();
                DB::table('exam_answer_tbl')->whereIn('question_id', $questionIds)->delete();

                // Delete questions
                $deletedQuestionsCount = DB::table('exam_question_tbl')->whereIn('id', $questionIds)->delete();
            }

            // Delete scenario groups if no other questions link to them
            $deletedScenariosCount = 0;
            if (!empty($scenarioGroupIds)) {
                $deletedScenariosCount = DB::table('question_scenario_groups_tbl')
                    ->whereIn('id', $scenarioGroupIds)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('exam_question_tbl')
                            ->whereColumn('exam_question_tbl.scenario_group_id', 'question_scenario_groups_tbl.id');
                    })
                    ->delete();
            }

            // Clean up auto-created topics if no questions remain under them
            $deletedTopicsCount = 0;
            if (!empty($topicIds)) {
                $deletedTopicsCount = DB::table('book_unit_topic_tbl')
                    ->whereIn('id', $topicIds)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('exam_question_tbl')
                            ->whereColumn('exam_question_tbl.topic_id', 'book_unit_topic_tbl.id');
                    })
                    ->delete();
            }

            DB::commit();

            // Delete S3 media files
            $deletedMediaCount = 0;
            if (!empty($mediaKeys)) {
                $awsUploadService = app(AwsUploadService::class);
                foreach ($mediaKeys as $keyOrUrl) {
                    $key = $this->managedQuestionStatementImageKey($keyOrUrl);
                    if (!$key) {
                        $parsedPath = parse_url($keyOrUrl, PHP_URL_PATH);
                        $key = ltrim($parsedPath ?: $keyOrUrl, '/');
                    }
                    if (!empty($key)) {
                        try {
                            $awsUploadService->deleteFileFromS3($key);
                            $deletedMediaCount++;
                        } catch (\Throwable $e) {
                            Log::warning("Rollback S3 delete warning for {$key}: " . $e->getMessage());
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Rollback successful: Removed {$deletedQuestionsCount} question(s)" .
                    ($deletedScenariosCount > 0 ? " and {$deletedScenariosCount} scenario group(s)." : ".") .
                    ($deletedMediaCount > 0 ? " Cleaned up {$deletedMediaCount} image(s) from S3." : ""),
                'deleted_questions' => $deletedQuestionsCount,
                'deleted_scenarios' => $deletedScenariosCount,
                'deleted_topics' => $deletedTopicsCount,
                'deleted_media' => $deletedMediaCount,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error rolling back import: ' . $e->getMessage(),
            ], 500);
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

            $selectFields = [
                'question',
                'question_presentation_type_id',
                'scenario_group_id',
                'scenario_question_order',
            ];
            if (Schema::hasColumn('exam_question_tbl', 'ai_provider_id')) {
                $selectFields[] = 'ai_provider_id';
            }
            if (Schema::hasColumn('exam_question_tbl', 'ai_model_id')) {
                $selectFields[] = 'ai_model_id';
            }

            $existingQuestion = DB::table('exam_question_tbl')
                ->where('id', '=', $request->id)
                ->select($selectFields)
                ->first();

            if (! $existingQuestion) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Question not found.',
                ], 404);
            }

            $questionStatement = $this->resolveQuestionStatement($request, $existingQuestion?->question, $request->id);
            $scenarioData = $this->resolveQuestionScenarioData($request, $existingQuestion);
    
            // Update the main question
            $updateFields = [
                'question' => $questionStatement,
                'question_um' => $request->question_um,
                'topic_id' => $request->topic_id,
                'question_type' => $request->question_type,
                'exercise_question' => $request->has('exercise_question') ? ($request->exercise_question ? 1 : 0) : ($existingQuestion->exercise_question ?? 0),
                'marks' => $request->marks !== null && $request->marks !== '' ? round((float) $request->marks, 2) : ($existingQuestion->marks ?? 1.00),
                'difficulty' => $request->difficulty ?? ($existingQuestion->difficulty ?? 3),
                'question_lang' => $request->has('question_lang') ? ($request->question_lang ? 1 : 0) : ($existingQuestion->question_lang ?? 0),
                'question_um_lang' => $request->has('question_um_lang') ? ($request->question_um_lang ? 1 : 0) : ($existingQuestion->question_um_lang ?? 1),
                'cognitive_domain' => $request->cognitive_domain ?? ($existingQuestion->cognitive_domain ?? 1),
                'topic_content' => $request->topic_content ?? ($existingQuestion->topic_content ?? 1),
                'status' => $status,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedAt,
                'explanation' => $request->explanation,
                'explanation_um' => $request->explanation_um,
                'explanation_video_url' => $request->explanation_video_url,
                'book_id' => $request->book_id,
                'unit_id' => $request->unit_id,
                'activate' => $request->has('activate')
                    ? ($request->activate ? 1 : 0)
                    : ($status !== 'archived' ? 1 : 0),
                'is_mcq' => $request->is_mcq ? 1 : 0,
                'has_diagram' => $request->has('has_diagram') ? ($request->has_diagram ? 1 : 0) : 0,
                'question_presentation_type_id' => $scenarioData['question_presentation_type_id'],
                'scenario_group_id' => $scenarioData['scenario_group_id'],
                'scenario_question_order' => $scenarioData['scenario_question_order'] ?? 0,
                'updated_at' => now(),
                'is_alp_question' => $request->has('is_alp_question') ? ($request->has_alp_question ? 1 : 0) : ($existingQuestion->is_alp_question ?? 1),
            ];

            if (Schema::hasColumn('exam_question_tbl', 'ai_provider_id')) {
                $updateFields['ai_provider_id'] = $request->has('ai_provider_id') ? ($request->filled('ai_provider_id') ? (int) $request->ai_provider_id : null) : ($existingQuestion->ai_provider_id ?? null);
            }
            if (Schema::hasColumn('exam_question_tbl', 'ai_model_id')) {
                $updateFields['ai_model_id'] = $request->has('ai_model_id') ? ($request->filled('ai_model_id') ? (int) $request->ai_model_id : null) : ($existingQuestion->ai_model_id ?? null);
            }

            DB::table('exam_question_tbl')
                ->where('id', '=', $request->id)
                ->update($this->withAuditColumns('exam_question_tbl', $updateFields, $userId, false));

               
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
            return response()->json([
                'success' => false,
                'message' => $e instanceof \InvalidArgumentException
                    ? $e->getMessage()
                    : 'Error updating question.',
                'error' => $e->getMessage(),
            ], $e instanceof \InvalidArgumentException ? 422 : 500);
        }
    }
    

    public function repeatQuestion(Request $request){

        try{
            if (!$this->canAccessQuestionScope($request, 'questions.create', $this->questionContextById($request->id))) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized. You are not allowed to repeat questions for this scope.',
                ], 403);
            }

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
            $boardLink = DB::table('exam_question_board_tbl')->where('id', $request->id)->first();
            $questionId = $boardLink?->question_id;
            if (!$this->canAccessQuestionScope($request, 'questions.activate', $questionId ? $this->questionContextById($questionId) : null)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized. You are not allowed to activate board questions for this scope.',
                ], 403);
            }

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
            $boardLink = DB::table('exam_question_board_tbl')->where('id', $request->id)->first();
            $questionId = $boardLink?->question_id;
            if (!$this->canAccessQuestionScope($request, 'questions.update', $questionId ? $this->questionContextById($questionId) : null)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized. You are not allowed to update board questions for this scope.',
                ], 403);
            }

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
        catch(\Throwable $e){
            Log::error('QuestionsController@getTest failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving test question.',
            ], 500);


        }
    }

    public function saveTest(Request $request){
        if (!$this->canAccessQuestionScope($request, 'questions.create', null)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Administrator access required.',
            ], 403);
        }

        try{
            $userId = $this->authenticatedUserId($request);
            $questionType = (int) ($request->input('question_type', 1));
            $isMcq = $questionType === 1 || (int) $request->input('is_mcq', 0) === 1;

            $presentationTypeId = $request->input('question_presentation_type_id');
            if ($isMcq) {
                if (empty($presentationTypeId)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Question presentation type is required for MCQs.',
                    ], 422);
                }

                $presType = DB::table('question_presentation_type_tbl')
                    ->where('id', (int) $presentationTypeId)
                    ->first();

                if (!$presType) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected question presentation type was not found.',
                    ], 422);
                }

                if (empty($presType->activate)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected question presentation type is inactive.',
                    ], 422);
                }
            }

            $question = DB::table('exam_question_tbl')
            ->insertGetId($this->withAuditColumns('exam_question_tbl', [
                'question' => $request -> question,
                'question_um' => $request -> question,
                'topic_id' =>  (int) ($request->input('topic_id', 1)),
                'question_type' => $questionType,
                'exercise_question' => (int) ($request->input('exercise_question', 1)),
                'marks' => (float) ($request->input('marks', 1)),
                'question_presentation_type_id' => $isMcq ? (int) $presentationTypeId : ($presentationTypeId ? (int) $presentationTypeId : null),
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(), 

            ], $userId));

            return response()->json([
                'success' => true,
                'message' => 'Question and options saved successfully.',
                'question_id' => $question,
            ]);

        }
        catch(\Exception $e){
            return response()->json(['success' => false, 'message' => 'Error saving question.', 'error' => $e->getMessage()], 500);

        }
    }

    public function deleteQuestion(Request $request, $id = null)
    {
        $questionId = (int) ($id ?? $request->input('id') ?? $request->route('id'));
        if (!$questionId) {
            return response()->json(['success' => false, 'message' => 'Question ID is required.'], 400);
        }

        $context = $this->questionContextById($questionId);
        if (!$context) {
            return response()->json(['success' => false, 'message' => 'Question not found.'], 404);
        }

        if (!$this->canAccessQuestionScope($request, 'questions.update', $context)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Administrator access required.'], 403);
        }

        DB::table('exam_question_tbl')
            ->where('id', $questionId)
            ->update($this->withAuditColumns('exam_question_tbl', [
                'activate' => 0,
                'updated_at' => now(),
            ], $this->authenticatedUserId($request), false));

        return response()->json(['success' => true, 'message' => 'Question deleted successfully.']);
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

    public function getQuestionsByTopicForLMS(Request $request, $topic_id)
    {
        try {
            $student = $request->user();

            if (!$student || $student->role !== 'student' || $student->status !== 'active') {
                return response()->json([
                    'success' => 0,
                    'message' => 'Active student account required.',
                ], 403);
            }

            // 1. Resolve DB-grounded topic hierarchy
            $topicContext = DB::table('book_unit_topic_tbl as topics')
                ->join('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->join('subject_tbl as subjects', 'subjects.id', '=', 'books.subject_id')
                ->where('topics.id', (int) $topic_id)
                ->where('topics.activate', 1)
                ->where('units.activate', 1)
                ->where('books.activate', 1)
                ->select([
                    'topics.id as topic_id',
                    'topics.topic_name',
                    'topics.topic_no',
                    'units.id as unit_id',
                    'units.unit_name',
                    'units.unit_no',
                    'books.id as book_id',
                    'books.class_id',
                    'books.curriculum_board_id',
                    'subjects.id as subject_id',
                    'subjects.subject_name',
                ])
                ->first();

            if (!$topicContext) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Topic not found or inactive.',
                ], 404);
            }

            // 2. Validate client-supplied academic hierarchy parameters if present
            if ($request->filled('unit_id') && (int) $request->input('unit_id') !== (int) $topicContext->unit_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Topic does not belong to the requested unit.',
                ], 422);
            }

            if ($request->filled('book_id') && (int) $request->input('book_id') !== (int) $topicContext->book_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Topic does not belong to the requested book.',
                ], 422);
            }

            if ($request->filled('subject_id') && (int) $request->input('subject_id') !== (int) $topicContext->subject_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Topic does not belong to the requested subject.',
                ], 422);
            }

            if ($request->filled('class_id') && (int) $request->input('class_id') !== (int) $topicContext->class_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Topic does not belong to the requested class.',
                ], 422);
            }

            if ($request->filled('curriculum_board_id') && (int) $request->input('curriculum_board_id') !== (int) $topicContext->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Topic does not belong to the requested curriculum board.',
                ], 422);
            }

            $offeredProgramId = $request->integer('offered_program_id') ?: ($request->integer('program_id') ?: null);
            if ($offeredProgramId) {
                $programValid = DB::table('offered_programs as programs')
                    ->join('offered_classes as classes', 'classes.id', '=', 'programs.offered_class_id')
                    ->join('program_subjects as prog_subs', 'prog_subs.offered_program_id', '=', 'programs.id')
                    ->where('programs.id', $offeredProgramId)
                    ->where('programs.is_active', 1)
                    ->where('classes.class_id', $topicContext->class_id)
                    ->where('classes.curriculum_board_id', $topicContext->curriculum_board_id)
                    ->where('prog_subs.subject_id', $topicContext->subject_id)
                    ->where('prog_subs.is_active', 1)
                    ->exists();

                if (!$programValid) {
                    return response()->json([
                        'success' => 0,
                        'message' => 'Hierarchy mismatch: Program does not cover the requested topic subject/class/board.',
                    ], 422);
                }
            }

            // 3. Resolve unified entitlement BEFORE loading question content
            $context = [
                'topic_id'            => (int) $topicContext->topic_id,
                'chapter_id'          => (int) $topicContext->unit_id,
                'book_id'             => (int) $topicContext->book_id,
                'subject_id'          => (int) $topicContext->subject_id,
                'class_id'            => (int) $topicContext->class_id,
                'curriculum_board_id' => (int) $topicContext->curriculum_board_id,
            ];
            if ($offeredProgramId) {
                $context['offered_program_id'] = $offeredProgramId;
            }

            /** @var UnifiedEntitlementResolver $resolver */
            $resolver = app(UnifiedEntitlementResolver::class);

            $mcqDecision           = $resolver->resolve($student, $context, 'perm_mcqs');
            $shortDecision         = $resolver->resolve($student, $context, 'perm_short_questions');
            $longDecision          = $resolver->resolve($student, $context, 'perm_long_questions');
            $exerciseDecision      = $resolver->resolve($student, $context, 'perm_exercise_questions');
            $supplementaryDecision = $resolver->resolve($student, $context, 'perm_supplementary_questions');
            $answersDecision       = $resolver->resolve($student, $context, 'perm_answers');
            $explanationsDecision  = $resolver->resolve($student, $context, 'perm_explanations');
            $topicContentsDecision = $resolver->resolve($student, $context, 'perm_topic_contents');

            // Fail-closed: If student is denied all question format permissions, reject with 403
            if ($mcqDecision->isDenied() && $shortDecision->isDenied() && $longDecision->isDenied()) {
                return response()->json([
                    'success'     => 0,
                    'message'     => $mcqDecision->getDenialReason() ?: 'An active subscription is required to access these questions.',
                    'code'        => 'ACCESS_DENIED',
                    'upgrade_url' => $mcqDecision->getUpgradeProgramSlug()
                        ? "/lms/subscribe/{$mcqDecision->getUpgradeProgramSlug()}"
                        : null,
                ], 403);
            }

            // Fail-closed: If both exercise and supplementary questions are explicitly denied under Freemium rules, reject with 403
            $isPaid = $mcqDecision->isPaid() || $shortDecision->isPaid() || $longDecision->isPaid();
            if (!$isPaid
                && $exerciseDecision->isDenied() && $exerciseDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE
                && $supplementaryDecision->isDenied() && $supplementaryDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE
            ) {
                return response()->json([
                    'success'       => 0,
                    'message'       => 'Access denied: Both exercise and supplementary questions are restricted under your current subscription or campaign.',
                    'code'          => 'ACCESS_DENIED',
                    'denial_reason' => 'Both exercise and supplementary questions are denied.',
                    'diagnostic'    => 'perm_exercise_questions and perm_supplementary_questions are both denied.',
                    'upgrade_url'   => $exerciseDecision->getUpgradeProgramSlug()
                        ? "/lms/subscribe/{$exerciseDecision->getUpgradeProgramSlug()}"
                        : null,
                ], 403);
            }

            // Client explicit question_type filter check
            if ($request->filled('question_type')) {
                $requestedType = (int) $request->input('question_type');
                if ($requestedType === 1 && $mcqDecision->isDenied()) {
                    return response()->json([
                        'success'     => 0,
                        'message'     => $mcqDecision->getDenialReason() ?: 'Access to MCQs is not allowed under your current subscription or campaign.',
                        'code'        => 'ACCESS_DENIED',
                        'upgrade_url' => $mcqDecision->getUpgradeProgramSlug() ? "/lms/subscribe/{$mcqDecision->getUpgradeProgramSlug()}" : null,
                    ], 403);
                }
                if ($requestedType === 2 && $shortDecision->isDenied()) {
                    return response()->json([
                        'success'     => 0,
                        'message'     => $shortDecision->getDenialReason() ?: 'Access to short questions is not allowed under your current subscription or campaign.',
                        'code'        => 'ACCESS_DENIED',
                        'upgrade_url' => $shortDecision->getUpgradeProgramSlug() ? "/lms/subscribe/{$shortDecision->getUpgradeProgramSlug()}" : null,
                    ], 403);
                }
                if ($requestedType === 3 && $longDecision->isDenied()) {
                    return response()->json([
                        'success'     => 0,
                        'message'     => $longDecision->getDenialReason() ?: 'Access to long questions is not allowed under your current subscription or campaign.',
                        'code'        => 'ACCESS_DENIED',
                        'upgrade_url' => $longDecision->getUpgradeProgramSlug() ? "/lms/subscribe/{$longDecision->getUpgradeProgramSlug()}" : null,
                    ], 403);
                }
            }

            // 4. Access GRANTED: Query and filter questions
            $query = ExamQuestion::with(['answers', 'answerOptions', 'questionType', 'scenarioGroup'])
                ->where('topic_id', $topic_id)
                ->where('activate', 1);

            // 4a. Filter by format permissions (MCQ, Short, Long)
            $query->where(function ($q) use ($mcqDecision, $shortDecision, $longDecision) {
                $hasClause = false;
                if ($mcqDecision->isAllowed()) {
                    $q->orWhere('is_mcq', 1)->orWhere('question_type', 1);
                    $hasClause = true;
                }
                if ($shortDecision->isAllowed()) {
                    $q->orWhere(function ($sub) {
                        $sub->where('question_type', 2)
                            ->orWhereHas('questionType', function ($qt) {
                                $qt->whereRaw('LOWER(type_name) LIKE ?', ['%short%']);
                            });
                    });
                    $hasClause = true;
                }
                if ($longDecision->isAllowed()) {
                    $q->orWhere(function ($sub) {
                        $sub->where('question_type', 3)
                            ->orWhereHas('questionType', function ($qt) {
                                $qt->whereRaw('LOWER(type_name) LIKE ?', ['%long%']);
                            });
                    });
                    $hasClause = true;
                }
                if (!$hasClause) {
                    $q->whereRaw('0 = 1');
                }
            });

            // 4b. Filter by exercise / supplementary permissions
            $isPaid = $mcqDecision->isPaid() || $shortDecision->isPaid() || $longDecision->isPaid();
            if (!$isPaid) {
                if ($exerciseDecision->isDenied() && $exerciseDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE) {
                    $query->where(function ($q) {
                        $q->where('exercise_question', 0)->orWhereNull('exercise_question');
                    });
                }
                if ($supplementaryDecision->isDenied() && $supplementaryDecision->getOrigin() === UnifiedAccessDecision::ORIGIN_FREEMIUM_RULE) {
                    $query->where('exercise_question', 1);
                }
            }

            if ($request->filled('question_type')) {
                $query->where('question_type', (int) $request->input('question_type'));
            }

            $questions = $query->orderBy('question_type', 'asc')->get();

            // 4c. Apply Correct-Answer, Explanation, and Topic-Content Protections
            $canAnswers = $answersDecision->isAllowed();
            $canExplanations = $explanationsDecision->isAllowed();
            $canTopicContents = $topicContentsDecision->isAllowed();

            foreach ($questions as $question) {
                // Correct-answer protection: Never expose is_answer or answers relation when perm_answers is denied
                if (!$canAnswers) {
                    if ($question->relationLoaded('answerOptions')) {
                        foreach ($question->answerOptions as $option) {
                            unset($option->is_answer);
                            $option->offsetUnset('is_answer');
                            $option->makeHidden('is_answer');
                        }
                    }
                    $question->setRelation('answers', collect([]));
                }

                // Explanation protection: Hide solutions and explanations when perm_explanations is denied
                if (!$canExplanations) {
                    $question->explanation = null;
                    $question->explanation_um = null;
                    $question->explanation_video_url = null;
                    $question->makeHidden(['explanation', 'explanation_um', 'explanation_video_url']);
                }

                // Topic content protection: Hide protected topic content when perm_topic_contents is denied
                if (!$canTopicContents) {
                    $question->topic_content = null;
                    $question->makeHidden(['topic_content']);
                }
            }

            // 5. Record activity
            if ($request->user()) {
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
                    'offered_program_id' => $offeredProgramId,
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

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
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

    private function resolveQuestionScenarioData(Request $request, ?object $existingQuestion = null): array
    {
        $hasScenarioGroupInput = array_key_exists('scenario_group_id', $request->all());
        $scenarioGroupId = null;

        if ($hasScenarioGroupInput) {
            $scenarioGroupId = $request->filled('scenario_group_id')
                ? (int) $request->scenario_group_id
                : null;
        } elseif ($existingQuestion?->scenario_group_id) {
            $scenarioGroupId = (int) $existingQuestion->scenario_group_id;
        }

        $isMcq = $this->isMcqQuestionRequest($request);

        $hasPresentationTypeInput = array_key_exists('question_presentation_type_id', $request->all());
        $presentationTypeId = null;

        if ($hasPresentationTypeInput) {
            $presentationTypeId = $request->filled('question_presentation_type_id')
                ? (int) $request->question_presentation_type_id
                : null;
        } elseif ($existingQuestion?->question_presentation_type_id) {
            $presentationTypeId = (int) $existingQuestion->question_presentation_type_id;
        }

        // Future records: Presentation type is compulsory for newly created MCQs
        if ($isMcq && $existingQuestion === null) {
            if (!$request->filled('question_presentation_type_id') || $presentationTypeId === null) {
                throw new \InvalidArgumentException('Question presentation type is required for MCQs.');
            }

            $presentationType = DB::table('question_presentation_type_tbl')
                ->where('id', $presentationTypeId)
                ->first();

            if (! $presentationType) {
                throw new \InvalidArgumentException('Selected question presentation type was not found.');
            }

            if (! (bool) $presentationType->activate) {
                throw new \InvalidArgumentException('Selected question presentation type is inactive.');
            }
        } elseif ($presentationTypeId !== null) {
            $presentationType = DB::table('question_presentation_type_tbl')
                ->where('id', $presentationTypeId)
                ->first();

            if (! $presentationType) {
                throw new \InvalidArgumentException('Selected question presentation type was not found.');
            }

            if (! (bool) $presentationType->activate) {
                throw new \InvalidArgumentException('Selected question presentation type is inactive.');
            }
        }

        if (! $scenarioGroupId) {
            return [
                'question_presentation_type_id' => $presentationTypeId,
                'scenario_group_id' => null,
                'scenario_question_order' => 0,
            ];
        }

        // Both MCQs and subjective / short questions can be attached to scenario groups
        $isMcq = $this->isMcqQuestionRequest($request);

        $scenarioGroup = DB::table('question_scenario_groups_tbl as scenario_groups')
            ->leftJoin('question_presentation_type_tbl as presentation_types', 'scenario_groups.question_presentation_type_id', '=', 'presentation_types.id')
            ->where('scenario_groups.id', $scenarioGroupId)
            ->select(
                'scenario_groups.id',
                'scenario_groups.question_presentation_type_id',
                'scenario_groups.topic_id',
                'scenario_groups.unit_id',
                'scenario_groups.book_id',
                'scenario_groups.activate',
                'presentation_types.allows_multiple_mcqs'
            )
            ->first();

        if (! $scenarioGroup) {
            throw new \InvalidArgumentException('Selected scenario group was not found.');
        }

        if (! (bool) $scenarioGroup->activate) {
            throw new \InvalidArgumentException('Selected scenario group is inactive.');
        }

        $this->assertQuestionMatchesScenarioGroup($request, $scenarioGroup);

        if ($scenarioGroup->question_presentation_type_id) {
            $scenarioPresentationTypeId = (int) $scenarioGroup->question_presentation_type_id;

            if ($presentationTypeId && $presentationTypeId !== $scenarioPresentationTypeId) {
                throw new \InvalidArgumentException('Question presentation type must match the selected scenario group.');
            }

            $presentationTypeId = $scenarioPresentationTypeId;
        }

        if (! $presentationTypeId) {
            throw new \InvalidArgumentException('Scenario questions require a presentation type that allows multiple MCQs.');
        }

        $presentationType = DB::table('question_presentation_type_tbl')
            ->where('id', $presentationTypeId)
            ->select('allows_multiple_mcqs', 'activate')
            ->first();

        if (! $presentationType) {
            throw new \InvalidArgumentException('Selected question presentation type was not found.');
        }

        if (! (bool) $presentationType->activate) {
            throw new \InvalidArgumentException('Selected question presentation type is inactive.');
        }

        if (! (bool) $presentationType->allows_multiple_mcqs) {
            throw new \InvalidArgumentException('Selected question presentation type does not allow multiple MCQs.');
        }

        return [
            'question_presentation_type_id' => $presentationTypeId,
            'scenario_group_id' => $scenarioGroupId,
            'scenario_question_order' => $this->resolveScenarioQuestionOrder($request, $scenarioGroupId, $existingQuestion),
        ];
    }

    private function assertQuestionMatchesScenarioGroup(Request $request, object $scenarioGroup): void
    {
        $context = $this->questionContextFromRequest($request);

        foreach (['topic_id', 'unit_id', 'book_id'] as $field) {
            if (
                $scenarioGroup->{$field}
                && isset($context[$field])
                && (int) $context[$field] !== (int) $scenarioGroup->{$field}
            ) {
                throw new \InvalidArgumentException('Question hierarchy must match the selected scenario group.');
            }
        }
    }

    private function isMcqQuestionRequest(Request $request): bool
    {
        return (int) $request->input('is_mcq', 0) === 1
            || (int) $request->input('question_type', 0) === 1;
    }

    private function resolveScenarioQuestionOrder(Request $request, int $scenarioGroupId, ?object $existingQuestion = null): int
    {
        if ($request->filled('scenario_question_order')) {
            return max(0, (int) $request->scenario_question_order);
        }

        if (
            $existingQuestion
            && (int) ($existingQuestion->scenario_group_id ?? 0) === $scenarioGroupId
            && $existingQuestion->scenario_question_order !== null
        ) {
            return (int) $existingQuestion->scenario_question_order;
        }

        return ((int) DB::table('exam_question_tbl')
            ->where('scenario_group_id', $scenarioGroupId)
            ->max('scenario_question_order')) + 1;
    }

    private function managedStorageUrlPrefixes(): array
    {
        $prefixes = [];
        $cdnUrl = config('filesystems.cdn_url');

        if ($cdnUrl) {
            $prefixes[] = rtrim($cdnUrl, '/') . '/';
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

    private function resolveAdminUser(Request $request): ?\App\Models\User
    {
        return app(QuestionAuthoringSecurityService::class)->resolveAdminUser($request);
    }

    private function authenticatedUserId(Request $request): ?int
    {
        $admin = $this->resolveAdminUser($request);

        return $admin?->id ? (int) $admin->id : null;
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
        return app(QuestionAuthoringSecurityService::class)->canAccessQuestionScope($request, $permissionName, $context);
    }

    private function questionPermissionScopes(Request $request, string $permissionName)
    {
        return app(QuestionAuthoringSecurityService::class)->questionPermissionScopes($request, $permissionName);
    }

    private function scenarioGroupContextById($id): ?array
    {
        return app(QuestionAuthoringSecurityService::class)->scenarioGroupContextById($id);
    }

    private function questionContextFromRequest(Request $request): ?array
    {
        return app(QuestionAuthoringSecurityService::class)->questionContextFromRequest($request);
    }

    private function questionContextById($questionId): ?array
    {
        return app(QuestionAuthoringSecurityService::class)->questionContextById($questionId);
    }

    private function scopeContextKey(string $scopeType): ?string
    {
        return app(QuestionAuthoringSecurityService::class)->scopeContextKey($scopeType);
    }

    
}
