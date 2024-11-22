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
}
