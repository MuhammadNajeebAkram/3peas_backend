<?php

namespace App\Http\Services;

use App\Models\StudyGroupDetail;
use App\Models\WebUserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebUserService{

    

    public function getUserClass(Request $req){

        try {
        $user = $req->user();

        $class = DB::table('user_profile_tbl as upt')
            ->join('class_tbl as ct', 'upt.class_id', '=', 'ct.id')
            ->where('upt.user_id', $user->id)
            ->select('upt.class_id', 'ct.class_name', 'upt.curriculum_board_id')
            ->first();

        if (!$class) {
            Log::warning($user->id, ': the class of user not found');
            return response()->json([
                'success' => 0,
                'message' => 'Class not found for user.',
            ]);
        }

        return response()->json([
            'success' => 1,
            'data' => $class,
        ]);

    } catch (\Exception $e) {
         Log::error('Exception found in WebUserService: ', $e->getMessage());
        return response()->json([
            'success' => 0,
            'error' => $e->getMessage(),
        ]);
    }

    }

    public function getSubjectsByUser($user_id){
        try{
            $userProfile = WebUserProfile::where('user_id', $user_id)->first();
            $groupId = $userProfile->study_group_id;
            $subjects = StudyGroupDetail::where('study_group_id', $groupId)->with('subject:id,subject_name')->get();

            $formatted = $subjects->map(function ($detail){
                if($detail->subject){
                    return [
                        'id' => $detail->subject->id,
                        'subject_name' => $detail->subject->subject_name,
                    ];

                }
                return null;
            })->filter()->values();

            return response()->json([
                'success' => 1,
                'data' => $formatted,
            ]);

        }catch (\Exception $e) {
         Log::error('Exception found in WebUserService: ', $e->getMessage());
        return response()->json([
            'success' => 0,
            'error' => $e->getMessage(),
        ]);
    }

    }
}