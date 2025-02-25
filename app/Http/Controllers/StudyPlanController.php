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

    public function getAllStudyPlans(Request $request){
        try{

            $plans = DB::table('study_plan_tbl')
            ->select(
                'id',
                'name',
                'plan_for',
                'price',
                DB::raw("CASE WHEN is_full_course = 0 THEN 'No' ELSE 'Yes' END as is_full_course"),
                'activate',
            )
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

    public function saveStudyPlan(Request $request){
        try{
            DB::table('study_plan_tbl')
            ->insert([
                'name' => $request -> name,
                'price' => $request -> price,
                'plan_for' => $request -> plan_for,
                'is_full_course' => $request ->is_full_course,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1,
                
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updateStudyPlan(Request $request){
        try{
            DB::table('study_plan_tbl')
            ->where('id', $request->id)
            ->update([
                'name' => $request -> name,
                'price' => $request -> price,
                'plan_for' => $request -> plan_for,
                'is_full_course' => $request ->is_full_course,
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1,
                
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
