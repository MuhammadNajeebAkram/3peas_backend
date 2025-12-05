<?php

namespace App\Http\Controllers;

use App\Models\StudyPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class StudyPlanController extends Controller
{
    //
    public function getStudyPlans(Request $request){
       // $user = $request->user();
       $user = auth('web_api')->user();
        try{
            
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
                'user' => $user,
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
                'class_id',
                'curriculum_board_id',
                DB::raw("CASE WHEN is_full_course = 0 THEN 'No' ELSE 'Yes' END as is_full_course"),
                'activate',
                'session_id',
            )
            ->get();

            return response()->json([
                'success' => 1,
                'plans' => $plans,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
                
            ]);
        }
    }
    public function getStudyPlansByClass(Request $request){
        try{
            
            $plans = DB::table('study_plan_tbl')
            ->where('class_id', $request->class_id)
            ->where('curriculum_board_id', $request->curriculum_id)
            ->where('plan_for', 'Student')
            ->where('activate', 1)
            ->get();

            return response()->json([
                'success' => 1,
                'plans' => $plans,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
                
            ]);

        }

    }

    public function saveStudyPlan(Request $request){
        try{

            $planData = [
                'name' => $request -> name,
                'price' => $request -> price,
                'plan_for' => $request -> plan_for,
                'is_full_course' => $request ->is_full_course,
                'class_id' => $request->class_id,
                'curriculum_board_id' => $request->curriculum_id,
                'session_id' => $request->session_id,
                'is_trial' => $request->is_trial,
                'activate' => 1,

            ];
            StudyPlan::create($planData);

            /*DB::table('study_plan_tbl')
            ->insert([
                'name' => $request -> name,
                'price' => $request -> price,
                'plan_for' => $request -> plan_for,
                'is_full_course' => $request ->is_full_course,
                'class_id' => $request->class_id,
                'curriculum_board_id' => $request->curriculum_id,
                'is_trial' => $request->is_trial,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);*/

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
            $planId = $request->id;

            $plan = StudyPlan::find($planId);
            if(!$plan){
                return response()->json([
                    'success' => 0
                ]);
            }

            $planData = [
                'name' => $request -> name,
                'price' => $request -> price,
                'plan_for' => $request -> plan_for,
                'is_full_course' => $request ->is_full_course,

            ];
            $plan->update($planData);
            /*
            DB::table('study_plan_tbl')
            ->where('id', $request->id)
            ->update([
                'name' => $request -> name,
                'price' => $request -> price,
                'plan_for' => $request -> plan_for,
                'is_full_course' => $request ->is_full_course,
                'updated_at' => now(),
            ]);*/

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

    public function activateStudyPlan(Request $request){

        $plan_id = $request->id;
        $plan_status = $request->status ?? 1;
        $plan = StudyPlan::find($plan_id);

        if(!$plan){
             return response()->json([
            'success' => 0,
            'message' => 'Plan is not valid'
        ], 404);
        }
        $plan->update(['activate' => $plan_status]);

        return response()->json([
            'success' => 1,
            'message' => 'Plan activated successfully'
        ]);

    }
}
