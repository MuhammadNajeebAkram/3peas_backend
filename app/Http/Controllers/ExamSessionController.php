<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamSessionController extends Controller
{
    //
    public function getAllExamSessions(){
        try {
            $sessions = DB::table('exam_session_tbl')
                        ->select(['id', 'session_name', 'activate'])
                        ->get();

            return response()->json([
                'success' => 1,
                'sessions' => $sessions
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'sessions' => 'Failed to retrieve sessions'], 500);
        }
    }

    public function getExamSessions(){
        try {
            $sessions = DB::table('exam_session_tbl')
                        ->where('activate', '=', 1)
                        ->select(['id', 'session_name'])
                        ->get();

            return response()->json([
                'success' => 1,
                'sessions' => $sessions
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'sessions' => 'Failed to retrieve sessions'], 500);
        }
    }

    public function saveSession(Request $request){
        try{
            $checkDuplicate = DB::table('exam_session_tbl')
        ->where('session_name', '=', $request -> session_name)
        ->exists();  // Use exists() to check if the record exists
        if (!$checkDuplicate){
            DB::table('exam_session_tbl')
            ->insert(['session_name' => $request -> session_name,
                      'activate' => 1,
                      'created_at' => now(),
                      'updated_at' => now()]);
    
            return response()->json([
                'success' => 1
            ]);
        }else {
            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Session is already exists.'
            ]);
        }
            
        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'message' => $e -> getMessage()
            ]);
        }
        
    }

    public function editSession(Request $request){
        try{
            if($request -> session_name != $request -> oldSessionName){
                $checkDuplicate = DB::table('exam_session_tbl')
                ->where('session_name', '=', $request->session_name)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Session Name already exists.'
                    ]); 
                }
            }
            $editSession = DB::table('exam_session_tbl')
            ->where('id', '=', $request -> id)
           ->update(['session_name' => $request -> session_name,
                     'updated_at' => now()]);
   
           if ($editSession) {
               return response()->json(['success' => 1], 200);
           } else {
               return response()->json(['success' => 0], 400);
           }
        }
        catch(\Exception $e){
            return response()->json(
                ['success' => 0,
                 'message' => $e -> getMessage(),]
                , 200);
        }
        
    }
    public function activateSession(Request $request){
        try{
            $editSession = DB::table('exam_session_tbl')
            ->where('id', '=', $request -> id)
           ->update(['activate' => $request -> activate,
                     'updated_at' => now()]);
   
           if ($editSession) {
               return response()->json(['success' => 1], 200);
           } else {
               return response()->json(['success' => 0], 400);
           }
        } catch(\Exception $e){
            return response()->json(
                ['success' => 0,
                 'message' => $e -> getMessage(),]
                , 200);
        }
        
    }
}
