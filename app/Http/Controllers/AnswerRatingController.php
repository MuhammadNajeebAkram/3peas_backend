<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AnswerRatingController extends Controller
{
    //
    public function saveRating(Request $request) {
        try {
            // Validate input data
           
    
            $user = Auth::guard('web_api')->user();

             // Check if the user has already rated the answer
        $existingRating = DB::table('exam_answer_rating_tbl')
        ->where('user_id', $user->id)
        ->where('question_id', $request->question_id)
        ->where('answer_id', $request->answer_id)
        ->first();

        if($existingRating){
            DB::table('exam_answer_rating_tbl')
            ->where('user_id', '=', $user->id)
            ->where('question_id', '=', $request->question_id)
            ->where('answer_id', '=', $request->answer_id)
            ->update([
                'rating' => $request->rating
            ]);
        }
        else{
             // Insert the rating into the database
             DB::table('exam_answer_rating_tbl')->insert([
                'user_id' => $user->id,
                'question_id' => $request->question_id,
                'answer_id' => $request->answer_id,
                'rating' => $request->rating,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        }
    
           
    
            return response()->json([
                'success' => 1,
                'message' => 'Rating saved successfully.'
            ]);
    
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
}
