<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardQuestionsController extends Controller
{
    //
    public function getMCQsQuestions(Request $request){
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
            $questions = DB::select('CALL GetMCQsExamQuestionsOfBoard(?, ?, ?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->board_id,
                $request->session_id,
                $request->group_id,
                $request->year
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

    public function getExamQuestions(Request $request){
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
            $questions = DB::select('CALL GetExamQuestionsOfBoard(?, ?, ?, ?, ?, ?, ?)', [
                $class_id, 
                $request->subject_id, 
                $request->board_id,
                $request->session_id,
                $request->group_id,
                $request->year,
                $request->qtype
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
