<?php

namespace App\Http\Services;

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
}