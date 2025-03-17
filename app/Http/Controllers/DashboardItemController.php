<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DashboardItemController extends Controller
{
    //
    public function getOverAllSummary(Request $request){
        try{

            $user = Auth::user();

            $result = DB::select("CALL GetStudentPerformanceSummary(?, ?)", [$user->id, $request->subject_id]);

            return response()->json([
                'success' => 1,
                'stats' => $result,
                'user' => $user,
                'subject' => $request->subject_id
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getSubjectLeaderBoard(Request $request){
        try{
            $user = Auth::user();

            $result = DB::select("CALL GetSubjectLeaderBoard(?)", [$user->id]);

            return response()->json([
                'success' => 1,
                'stats' => $result,
               
            ]);


        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getTestHistory(Request $request){
        try{
            $user = Auth::user();

            $result = DB::select("CALL GetTestHistory(?)", [$user->id]);

            return response()->json([
                'success' => 1,
                'history' => $result,
               
            ]);


        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }
}
