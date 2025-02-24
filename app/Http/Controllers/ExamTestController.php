<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamTestController extends Controller
{
    //
    public function getPracticeMCQsTestOfUnits(Request $request){
        try{

            $request->validate([
                'unit_ids' => 'required|array',
                'batch_size' => 'required|integer|min:1',
                'offset' => 'required|integer|min:0'
            ]);

            $unitIds = implode(',', $request->unit_ids);
            $batchSize = $request->batch_size;
            $offset = $request->offset;

            // Call the stored procedure
            $questions = DB::select("CALL GetPracticeTestMCQsExamQuestionsOfUnitsWithBoard(?, ?, ?)", [$unitIds, $batchSize, $offset]);

            $has_more = false;

            if (count($questions) > $batchSize) {
                array_pop($questions); // Remove the extra question
                $has_more = true;
            }
                

            return response()->json([
                'success' => 1,
                'questions' => $questions,
                'has_more' => $has_more,
            ]);

        
            

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);


        }
    }
}
