<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
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

    public function getRandomMCQsTestOfUnits(Request $request){
        try{

            $request->validate([
                'unit_ids' => 'required|array',
                'limit' => 'required|integer|min:1',                
            ]);

            $unitIds = implode(',', $request->unit_ids);
            $limit = $request->limit;
           

            // Call the stored procedure
            $questions = DB::select("CALL GetRandomTestMCQsExamQuestionsOfUnits(?, ?)", [$unitIds, $limit]);

           
                

            return response()->json([
                'success' => 1,
                'questions' => $questions,
               
            ]);

        
            

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);


        }
    }

    public function saveTestResult(Request $request){
        try{
            $testStartTime = Carbon::parse($request->start_time);
            $testEndTime = Carbon::parse($request->end_time);

            DB::beginTransaction();
            $test = DB::table('student_test_tbl')
            ->insertGetId([
                'user_id' => $request->user()->id,
                'test_type' => $request->test_type,
                'created_at' => now(),
                'updated_at' => now(),
                'test_start_at' => $testStartTime,
                'test_end_at' => $testEndTime,
            ]);

           $questions = $request -> questions;

            if (!empty($request->questions) && is_array($request->questions)){
                foreach($questions as $question){
                    

                    DB::table('student_performance_tbl')
                    ->insert([
                        'test_id' => $test,
                        'question_id' => $question['question_id'],
                        'is_correct' => $question['is_correct'],
                        'is_attempted' => $question['is_attempted'],

                    ]);

                    

                }

            }

            DB::commit();

                    return response()->json([
                        'success' => 1,
                        
                    ]);

           

        }catch(\Exception $e){

            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 404);

        }
    }
}
