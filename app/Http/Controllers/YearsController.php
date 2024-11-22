<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class YearsController extends Controller
{
    //
    public function getYearsByBoards_Class_Subjects($id, $subject_id, $board_id){
        try {
            $years = DB::table('years_of_boards_of_subjects_of_classes_view')
                        ->where('class_id', '=', $id)
                        ->where('subject_id', '=', $subject_id)
                        ->where('board_id', '=', $board_id)
                        ->orderBy('year', 'desc')
                        ->select('year')                        
                        ->get();

            return response()->json([
                'success' => 1,
                'years' => $years
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'years' => 'Failed to retrieve subjects'], 500);
        }
    }
}
