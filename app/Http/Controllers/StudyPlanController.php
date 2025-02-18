<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudyPlanController extends Controller
{
    //
    public function getStudyPlans(Request $request){
        try{
            $user = $request->user();
            $designation = DB::table('user_profile_tbl')
            ->where('user_id', $user->id)
            ->select('designation')
            ->first();

            $plans = DB::table('study_plan_tbl')
            ->where('activate', $request->status)
            ->where('plan_for', $designation->designation)
            ->select('id', 'name', 'price', 'is_full_course')
            ->get();

            return response()->json([
                'success' => 1,
                'plans' => $plans,
                
            ]);


        }catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }
}
