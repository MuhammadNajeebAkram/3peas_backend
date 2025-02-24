<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;

class CompleteBookQuestionsController extends Controller
{
    //
    public function getTopMCQsQuestions(Request $request){
        try {
            $user_id = $request->user()->id;
    
            // Retrieve class_id safely
            $classData = DB::table('user_profile_tbl')
                ->where('user_id', $user_id)
                ->select('class_id', 'curriculum_board_id')
                ->first();
    
            if (!$classData || !$classData->class_id || !$classData->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
            $curriculum_board_id = $classData->curriculum_board_id;
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetTopMCQsExamQuestionsWithBoard(?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit,
                $curriculum_board_id,
            ]);
    
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

    public function getRandomMCQsQuestions(Request $request){
        try {
            $user_id = $request->user()->id;
    
            // Retrieve class_id safely
            $classData = DB::table('user_profile_tbl')
                ->where('user_id', $user_id)
                ->select('class_id', 'curriculum_board_id')
                ->first();
    
            if (!$classData || !$classData->class_id || !$classData->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
            $curriculum_board_id = $classData->curriculum_board_id;
            $subject_id = $request->subject_id;

            if($subject_id === 0){
                $id = DB::table('user_selected_subject_tbl')
                ->where('user_id', $user_id)
                ->select('subject_id')
                ->inRandomOrder()
                ->first();
                if($id){
                    $subject_id = $id->subject_id;
                }
            }
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetRandomMCQsExamQuestionsWithBoard(?, ?, ?, ?)', [
                $class_id, 
                $subject_id, 
                $request->limit,
                $curriculum_board_id
            ]);
    
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

    public function getTopSQsQuestions(Request $request){
        try {
            $user_id = $request->user()->id;
    
            // Retrieve class_id safely
            $classData = DB::table('user_profile_tbl')
                ->where('user_id', $user_id)
                ->select('class_id', 'curriculum_board_id')
                ->first();
    
            if (!$classData || !$classData->class_id || !$classData->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
            $curriculum_board_id = $classData->curriculum_board_id;
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetTopSQsExamQuestionsWithBoard(?, ?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit,
                $request->qtype,
                $curriculum_board_id
            ]);
    
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

    public function getRandomSQsQuestions(Request $request){
        try {
            $user_id = $request->user()->id;
    
            // Retrieve class_id safely
            $classData = DB::table('user_profile_tbl')
                ->where('user_id', $user_id)
                ->select('class_id', 'curriculum_board_id')
                ->first();
    
            if (!$classData || !$classData->class_id || !$classData->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
            $curriculum_board_id = $classData->curriculum_board_id;
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetRandomSQsExamQuestionsWithBoard(?, ?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit,
                $request->qtype,
                $curriculum_board_id
            ]);
    
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
    
}
