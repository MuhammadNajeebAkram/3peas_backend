<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudySessionController extends Controller
{
    //
    public function saveSession(Request $request){
        try{

            $checkDates = DB::table('study_session_tbl')
            ->where('start_date', $request->start_date)
            ->where('end_date', $request->end_date)
            ->first();

            if($checkDates){
                return response()->json([
                    'success' => 2,
                    'error' => 'Duplicate Session dates',
                ]);
            }

            DB::table('study_session_tbl')
            ->insert([
                'name' => $request->name,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'class_id' => $request->class_id,
                'curriculum_board_id' => $request->curriculum_id,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
            ]);

        }

    }

    public function getSessionsByClassAndBoard($class_id, $curriculum_id){
        try{

            $sessions = DB::table('study_session_tbl')
            ->where('class_id', $class_id)
            ->where('curriculum_board_id', $curriculum_id)
            ->where('activate', 1)
            ->get();

            return response()->json([
                'success' => 1,
                'sessions' => $sessions,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
            ]);

        }
    }
}
