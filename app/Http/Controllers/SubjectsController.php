<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubjectsController extends Controller
{
    //
    public function getSubjectsByClass($id){
        try {
            $subjects = DB::table('subjects_of_classes_view')
                        ->where('class_id', '=', $id)
                        ->select(['subject_id', 'subject_name', 'icon_name'])
                        ->get();

            return response()->json([
                'success' => 1,
                'subjects' => $subjects
            ]);

            
        } catch (\Exception $e) {
            Log::error('Database error in subjects by user', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'subjects' => 'Failed to retrieve subjects'], 500);
        }
    }
    public function getSubjects(Request $request){
        try {
            $subjects = DB::table('subject_tbl')
                        ->where('activate', '=', 1)
                        ->select(['id', 'subject_name'])
                        ->orderBy('subject_name')
                        ->get();

            return response()->json([
                'success' => 1,
                'subjects' => $subjects
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'subjects' => 'Failed to retrieve subjects'], 500);
        }
    }
    public function getAllSubjects(Request $request){
        try {
            $subjects = DB::table('subject_tbl')
                        ->select(['id', 'subject_name', 'icon_name', 'activate'])
                        ->get();

            return response()->json([
                'success' => 1,
                'subjects' => $subjects
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'subjects' => 'Failed to retrieve subjects'], 500);
        }
    }

    public function saveSubject(Request $request)
{
    try {
        // Check for duplicate subject name
        $checkDuplicate = DB::table('subject_tbl')
            ->where('subject_name', '=', $request->subject_name)
            ->exists();  // Use exists() to check if the record exists
    
        if (!$checkDuplicate) {
            // Insert the new subject
            DB::table('subject_tbl')
                ->insert([
                    'subject_name' => $request->subject_name,
                    'icon_name' => $request->icon_name,
                    'activate' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
    
            return response()->json([
                'success' => 1 // Successfully inserted
            ]);
        } else {
            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Subject already exists.'
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => 0,  // Error occurred
            'error' => $e->getMessage(),
        ]);
    }
}


    public function editSubject(Request $request){
        try{

        
        if($request -> subject_name != $request -> oldSubjectName){
            $checkDuplicate = DB::table('subject_tbl')
            ->where('subject_name', '=', $request->subject_name)
            ->exists();  // Use exists() to check if the record exists
            if($checkDuplicate){
                return response()->json([
                    'success' => 2, // Duplicate entry
                    'message' => 'Subject already exists.'
                ]); 
            }
    
        }
        $subjectClass = DB::table('subject_tbl')
         ->where('id', '=', $request -> id)
        ->update(['subject_name' => $request -> subject_name,
                  'icon_name' => $request -> icon_name,
                  'updated_at' => now()]);

        if ($subjectClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 3, 'message' => 'Bad Request'], 400);
        }
    }catch(\Exception $e){
        return response()->json([
            'success' => 0, // error
            'message' => $e->getMessage(),
        ]);
    }
    }
    public function activateSubject(Request $request){
        $editSubject = DB::table('subject_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editSubject) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }

    public function getSubjectsByUser(Request $request)
{
    $user = Auth::guard('web_api')->user();

    try {
        $subjects = DB::table('user_selected_subject_tbl as usst')
            ->join('subject_tbl as st', 'usst.subject_id', '=', 'st.id')
            ->where('usst.user_id', $user->id)
            ->select('usst.subject_id as id', 'st.subject_name')
            ->get();

        return response()->json([
            'success' => 1,
            'subjects' => $subjects,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => 0,
            'error' => $e->getMessage(), // Changed key from 'subjects' to 'error' for clarity
        ], 500);
    }
}

}
