<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class FeedbackController extends Controller
{
    //

    public function saveFeedback(Request $request){
        $user = Auth::guard('web_api')->user();
        try{

            DB::table('feedback_tbl')
            ->insert([
                'user_id' => $user->id,
                'subject' => $request->subject,
                'feedback' => $request->feedback,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return response()->json([
                'success' => 1,
            ], 200);

        }catch(\Exception $e){

            return response()->json([
                'success' => -1,
                'message' => $e->getMessage(),
            ], 500);

        }
    }
}
