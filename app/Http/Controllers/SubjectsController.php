<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                        ->select(['id', 'subject_name', 'activate'])
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

    public function saveSubject(Request $request){
        DB::table('subject_tbl')
        ->insert(['subject_name' => $request -> subject_name,
                  'icon_name' => $request -> icon_name,
                  'activate' => 1,
                  'created_at' => now(),
                  'updated_at' => now()]);

        return response()->json([
            'success' => 1
        ]);
    }

    public function editSubject(Request $request){
        $subjectClass = DB::table('subject_tbl')
         ->where('id', '=', $request -> id)
        ->update(['subject_name' => $request -> className,
                  'icon_name' => $request -> icon_name,
                  'updated_at' => now()]);

        if ($subjectClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
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
}
