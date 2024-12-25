<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id')
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
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id')
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
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id')
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
            $questions = DB::table('exam_question_tbl')
    ->join('book_unit_topic_tbl', 'exam_question_tbl.topic_id', '=', 'book_unit_topic_tbl.id')
    ->join('question_type_tbl', 'exam_question_tbl.question_type', '=', 'question_type_tbl.id')
    ->join('book_unit_tbl', 'book_unit_topic_tbl.unit_id', '=', 'book_unit_tbl.id')
    ->join('book_tbl', 'book_unit_tbl.book_id', '=', 'book_tbl.id')
    ->join('exam_question_board_tbl', 'exam_question_tbl.id', '=', 'exam_question_board_tbl.question_id')
    ->select('exam_question_tbl.id', 'exam_question_tbl.question', 'book_unit_topic_tbl.topic_name', 
    'question_type_tbl.type_name', 'exam_question_tbl.activate', 'exam_question_tbl.topic_id')
    ->where('book_tbl.subject_id', $request -> subject_id)
    ->where('book_tbl.class_id', $request -> class_id)
    ->where('exam_question_board_tbl.board_id', $request -> board_id)
    ->where('exam_question_board_tbl.session_id', $request -> session_id)
    ->where('exam_question_board_tbl.year', $request -> year)
    ->where('exam_question_board_tbl.group_id', $request -> group_id)
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

            $questionData = DB::table('exam_question_tbl')
            ->where('id', '=', $request -> id)
            ->get();

            $question = $questionData -> first();

            $answers = DB::table('exam_answer_tbl')
            ->where('question_id', '=', $request ->id)
            ->get();

            $unit = DB::table('book_unit_topic_tbl')
            ->where('id', '=', $request -> topic_id)
            ->select('unit_id')
            ->get();

            $book = DB::table('book_unit_tbl')
            ->where('id', '=', $unit -> first() -> unit_id)
            ->select('book_id')
            ->get();

            $sublass = DB::table('book_tbl')
            ->where('id', '=', $book -> first() -> book_id)
            ->select('subject_id', 'class_id')
            ->get();

            $options = [];

            if ($question -> question_type == 1){

                $options = DB::table('exam_question_options_tbl')
                ->where('question_id', '=', $question -> id)
                ->select('id', 'option as text', 'option_um as text_um', 'is_answer as is_correct')
                ->get();

            }

            return response() -> json([
                'success' => true,
                'question_data' => $questionData,
                'unit' => $unit,
                'book' => $book,
                'sublass' => $sublass,
                'options' => $options,
                'answers' => $answers,

            ]);



        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e -> getMessage()], 500);

        }

        
    }

    public function saveQuestion(Request $request){
        try{
            DB::beginTransaction();

            $question = DB::table('exam_question_tbl')
            ->insertGetId([
                'question' => $request -> question,
                'question_um' => $request -> question_um,
                'topic_id' => $request -> topic_id,
                'question_type' => $request -> question_type,
                'exercise_question' => $request -> exercise_question,
                'marks' => $request -> marks,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(), 

            ]);

            if ($request -> answer != "" || $request ->answer_um != ""){
                $answer = DB::table('exam_answer_tbl')
                ->insert([
                    'question_id' => $question,
                    'answer' => $request -> answer,
                    'answer_um' => $request -> answer_um,
                    'created_at' => now(),
                    'updated_at' => now(), 
                ]);
            }

            $board_question = DB::table('exam_question_board_tbl')
            ->insert([
                'question_id' => $question,
                'board_id' => $request -> board_id,
                'session_id' => $request -> session_id,
                'group_id' => $request -> group_id,
                'year' => $request -> year,
                'created_at' => now(),
                'updated_at' => now(), 
            ]);

            $options = $request -> options;

            if ($options != null && is_array($options)) {
                foreach ($options as $option) {
                    DB::table('exam_question_options_tbl')->insert([
                        'question_id' => $question,
                        'option' => $option['text'], // Assuming 'text' is a key in each option array
                        'option_um' => $option['text_um'],
                        'is_answer' => $option['is_correct'], // Assuming 'is_correct' is a boolean key in each option array
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Question and options saved successfully.']);



        }
        catch(\Exception $e){

            DB::rollBack();

            \Log::error('Error saving question:', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json(['success' => false, 'message' => 'Error saving question.', 'error' => $e->getMessage()]);

        }
    }

    public function updateQuestion(Request $request){
        try {
            DB::beginTransaction();
    
            // Update the main question
            DB::table('exam_question_tbl')
                ->where('id', '=', $request->id) // Apply the where condition first
                ->update([
                    'question' => $request->question,
                    'question_um' => $request->question_um,
                    'topic_id' => $request->topic_id,
                    'question_type' => $request->question_type,
                    'exercise_question' => $request->exercise_question,
                    'updated_at' => now(),
                ]);

                if ($request -> answer != "" || $request ->answer_um != ""){
                    $answer = DB::table('exam_answer_tbl')
                    ->where('question_id', '=', $request -> id)
                    ->update([
                        'answer' => $request -> answer,
                        'answer_um' => $request -> answer_um,                        
                        'updated_at' => now(), 
                    ]);
                }
    
            // Handle options update
            $options = $request->options;
            if ($options != null && is_array($options)) {
                foreach ($options as $option) {
                    DB::table('exam_question_options_tbl')
                        ->where('id', '=', $option['id']) // Correctly reference the option ID
                        ->update([
                            'option' => $option['text'], // Assuming 'text' is a key in each option array
                            'option_um' => $option['text_um'],
                            'is_answer' => $option['is_correct'], // Assuming 'is_correct' is a boolean key in each option array
                            'updated_at' => now(),
                        ]);
                }
            }
    
            DB::commit();
    
            return response()->json(['success' => true, 'message' => 'Question and options updated successfully.']);
        } catch(\Exception $e) {
            DB::rollBack();
    
            \Log::error('Error saving question:', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
    
            return response()->json(['success' => false, 'message' => 'Error updating question.', 'error' => $e->getMessage()]);
        }
    }
    

    public function repeatQuestion(Request $request){

        try{


            $exists = DB::table('exam_question_board_tbl')
            ->where('question_id', $request->question_id)
            ->where('board_id', $request->board_id)
            ->where('session_id', $request->session_id)
            ->where('group_id', $request->group_id)
            ->where('year', $request->year)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Duplicate record found.']);
        }

            $board_question = DB::table('exam_question_board_tbl')
            ->insert([
                'question_id' => $request -> question_id,
                'board_id' => $request -> board_id,
                'session_id' => $request -> session_id,
                'group_id' => $request -> group_id,
                'year' => $request -> year,
                'created_at' => now(),
                'updated_at' => now(), 
            ]);

            return response()->json(['success' => true, 'message' => 'Question and options saved successfully.']);


        }
        catch(\Exception $e){

            return response()->json(['success' => false, 'message' => 'Error saving question.', 'error' => $e->getMessage()]);


        }
    }
}
