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
                ->select('class_id')
                ->first();
    
            if (!$classData || !$classData->class_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetTopMCQsExamQuestionsWithBoard(?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit
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
                ->select('class_id')
                ->first();
    
            if (!$classData || !$classData->class_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetRandomMCQsExamQuestionsWithBoard(?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit
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
                ->select('class_id')
                ->first();
    
            if (!$classData || !$classData->class_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetTopSQsExamQuestionsWithBoard(?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit,
                $request->qtype,
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
                ->select('class_id')
                ->first();
    
            if (!$classData || !$classData->class_id) {
                return response()->json([
                    'success' => 0,
                    'error' => 'User class ID not found.',
                ], 400);
            }
    
            $class_id = $classData->class_id; // Extract integer value
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetRandomSQsExamQuestionsWithBoard(?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->limit,
                $request->qtype,
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
