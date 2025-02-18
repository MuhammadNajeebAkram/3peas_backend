<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudyGroupController extends Controller
{
    //
    public function getStudyGroups(Request $request){
        try{
            $groups=DB::table('study_group_tbl')
            ->where('activate', $request->status)
            ->where('class_id', $request->class_id)
            ->select('id', 'name')
            ->get();

            return response()->json([
                'success' => 1,
                'groups' => $groups,
            ]);

        }catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }
    public function getStudySubjects(Request $request){
        try{
            $subjects = DB::table('study_subjects_tbl')
            //->where('study_subjects_tbl.activate', $request->status)
            ->where('study_subjects_tbl.class_id', $request->class_id)
            ->join('subject_tbl', 'study_subjects_tbl.subject_id', '=', 'subject_tbl.id')
            ->select('study_subjects_tbl.subject_id as id', 'subject_tbl.subject_name as name')
            ->get();

            return response()->json([
                'success' => 1,
                'subjects' => $subjects,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
