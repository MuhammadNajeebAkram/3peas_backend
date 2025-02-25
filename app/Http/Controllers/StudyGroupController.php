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
            ->where('curriculum_board_id', $request->curriculum_board_id)
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

    public function getAllStudyGroups(Request $request){
        try{
            $groups=DB::table('study_group_tbl as sgt')
            ->join('class_tbl as ct', 'sgt.class_id', '=', 'ct.id') 
            ->join('curriculum_board_tbl as cbt', 'sgt.curriculum_board_id', '=', 'cbt.id')           
            ->select('sgt.id', 'sgt.name', 'ct.class_name', 'cbt.name', 'sgt.activate', 'sgt.class_id', 'sgt.curriculum_board_id')
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
            ->where('study_subjects_tbl.curriculum_board_id', $request->curriculum_board_id)
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
    public function getSelectedOfferedSubjects(Request $request){
        try{

            // Allow only specific column names to prevent SQL injection
        $allowedColumns = ['subject_id', 'class_id', 'curriculum_board_id']; // Replace with actual column names

        if (!in_array($request->keyword, $allowedColumns)) {
            return response()->json([
                'success' => 0,
                'error' => 'Invalid column name provided.',
            ], 400);
        }

        $column = $request->keyword;

            $subjects = DB::table('study_subjects_tbl as sst')
            ->where($column, $request->id)
            ->join('subject_tbl as st', 'sst.subject_id', '=', 'st.id')
            ->join('class_tbl as ct', 'sst.class_id', '=', 'ct.id')
            ->join('curriculum_board_tbl as cbt', 'sst.curriculum_board_id', '=', 'cbt.id')
            ->select('sst.id', 'st.subject_name', 'ct.class_name', 'cbt.name', 'sst.subject_id', 'sst.class_id', 'sst.curriculum_board_id')
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

    public function getSelectedOfferedGroups(Request $request){
        try{

            // Allow only specific column names to prevent SQL injection
        $allowedColumns = ['class_id', 'curriculum_board_id']; // Replace with actual column names

        if (!in_array($request->keyword, $allowedColumns)) {
            return response()->json([
                'success' => 0,
                'error' => 'Invalid column name provided.',
            ], 400);
        }

        $column = $request->keyword;

            $groups = DB::table('study_group_tbl as sgt')
            ->where($column, $request->id)            
            ->join('class_tbl as ct', 'sgt.class_id', '=', 'ct.id')
            ->join('curriculum_board_tbl as cbt', 'sgt.curriculum_board_id', '=', 'cbt.id')
            ->select('sgt.id', 'sgt.name as group', 'ct.class_name', 'cbt.name', 'sgt.class_id', 'sgt.curriculum_board_id')
            ->get();

            return response()->json([
                'success' => 1,
                'groups' => $groups,
            ]);



        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getOfferedStudySubjectsByClassAndCurriculum(Request $request){
        try{
            $subjects = DB::table('study_subjects_tbl as sst')
            ->where('sst.class_id', $request->class_id)
            ->where('sst.curriculum_board_id', $request->curriculum_board_id)
            ->join('subject_tbl as st', 'sst.subject_id', '=', 'st.id')
            ->select('st.id', 'st.subject_name')
            ->get();

            return response()->json([
                'success' => 1,
                'subjects' => $subjects,
            ]);

        }catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getOfferedStudyGroupsByClassAndCurriculum(Request $request){
        try{
            $groups = DB::table('study_group_tbl as sgt')
            ->where('sgt.class_id', $request->class_id)
            ->where('sgt.curriculum_board_id', $request->curriculum_board_id)            
            ->select('sgt.id', 'sgt.name')
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
    
    public function saveOfferedSubjects(Request $request){
        try {
            $subjects = DB::table('study_subjects_tbl')->insert([
                'subject_id' => $request->subject_id,
                'class_id' => $request->class_id,
                'curriculum_board_id' => $request->curriculum_board_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            return response()->json([
                'success' => 1,
                'message' => 'Subject successfully added.',
            ]);
    
        } catch (\Illuminate\Database\QueryException $e) {
            // Check if the error is due to a UNIQUE constraint violation
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'success' => 0,
                    'error' => 'This subject, class, and curriculum board combination already exists!',
                ], 422);
            }
    
            // Return a generic error message for other exceptions
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveOfferedGroups(Request $request){
        try {
            DB::beginTransaction();

            $group_id = DB::table('study_group_tbl')
            ->insertGetId([
                'name' => $request->group,
                'class_id' => $request->class_id,
                'curriculum_board_id' => $request->curriculum_board_id,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $subjects = $request->subject_id;

            if (!empty($request->subject_id) && is_array($request->subject_id)) {
                foreach ($request->subject_id as $subject) {
                    DB::table('study_group_detail_tbl')->insert([
                        'study_group_id' => $group_id,
                        'subject_id' => $subject,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }}

            DB::commit();
    
            return response()->json([
                'success' => 1,
                'message' => 'Offered Group successfully added.',
            ]);
    
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            // Check if the error is due to a UNIQUE constraint violation
            if ($e->errorInfo[1] == 1062) {
                
                return response()->json([
                    'success' => 0,
                    'error' => 'Group combination of Class and Curriculum already exists!',
                ], 422);
            }
    
            // Return a generic error message for other exceptions
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
}
