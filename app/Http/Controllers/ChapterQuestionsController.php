<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChapterQuestionsController extends Controller
{
    //
    public function getTopMCQsQuestions(Request $request){
        try {
            
    
            $unitIds = implode(',', $request->unit_ids);
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetTopMCQsExamQuestionsOfUnitsWithBoard(?, ?)', [
                $unitIds,                 
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
            
    
            $unitIds = implode(',', $request->unit_ids);
    
            // Call stored procedure safely
            $questions = DB::select('CALL GetTopSQsExamQuestionsOfUnitsWithBoard(?, ?, ?)', [
                $unitIds,                 
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
