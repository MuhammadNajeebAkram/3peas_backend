<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PapersController extends Controller
{
    //
    public function getPapersByYears_Boards_Class_Subjects($id, $subject_id, $board_id, $year){
        try {
            $papers = DB::table('papers_of_years_of_boards_of_subjects_of_classes_view')
                        ->where('class_id', '=', $id)
                        ->where('subject_id', '=', $subject_id)
                        ->where('board_id', '=', $board_id)
                        ->where('year', '=', $year)
                        ->select('paper_path')
                        ->get();

            return response()->json([
                'success' => 1,
                'papers' => $papers
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'papers' => 'Failed to retrieve subjects'], 500);
        }
    }
    public function getPastPapers(Request $request){
        try{
            $papers = DB::table('past_paper_view')->get();

            return response()->json([
                'success' => 1,
                'papers' => $papers
            ]);
        }
        catch (\Exception $e){
            return response()->json([
                'success' => 0,
                'papers' => 'Failed to retrieve subjects'], 500);

        }

    }
    public function savePastPapers(Request $request){
        DB::table('past_paper_tbl')
        ->insert(['paper_name' => $request -> paper_name,
                  'paper_path' => $request -> paper_path,
                  'class_id' => $request -> class_id,
                  'subject_id' => $request -> subject_id,
                  'board_id' => $request -> board_id,
                  'year' => $request -> year,
                  'session_id' => $request -> session_id,
                  'group' => $request -> group,
                  'activate' => 1,
                  'created_at' => now(),
                  'updated_at' => now()]);

        return response()->json([
            'success' => 1
        ]);
    }

    public function updatePastPapers(Request $request){
        $updatePapers = DB::table('past_paper_tbl')
         ->where('id', '=', $request -> id)
        ->update(['paper_name' => $request -> paper_name,
                  'paper_path' => $request -> paper_path,
                  'class_id' => $request -> class_id,
                  'subject_id' => $request -> subject_id,
                  'board_id' => $request -> board_id,
                  'session_id' => $request -> session_id,
                  'year' => $request -> year,
                  'group' => $request -> group,
                  'updated_at' => now()]);

        if ($updatePapers) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }
}
