<?php

namespace App\Http\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebUserService{

    

    public function getUserClass(Request $req){

        try {
        $user = $req->user();

        $class = DB::table('user_profile_tbl as upt')
            ->join('class_tbl as ct', 'upt.class_id', '=', 'ct.id')
            ->where('upt.user_id', $user->id)
            ->select('upt.class_id', 'ct.class_name')
            ->first();

        if (!$class) {
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
        return response()->json([
            'success' => 0,
            'error' => $e->getMessage(),
        ]);
    }

    }
}