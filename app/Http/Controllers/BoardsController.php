<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardsController extends Controller
{
    //
    public function getBoardsByClass_Subjects($id, $subject_id){
        try {
            $boards = DB::table('boards_of_subjects_of_classes_view')
                        ->where('class_id', '=', $id)
                        ->where('subject_id', '=', $subject_id)
                        ->select(['board_id', 'board_name', 'icon_name'])
                        ->get();

            return response()->json([
                'success' => 1,
                'boards' => $boards
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'boards' => 'Failed to retrieve subjects'], 500);
        }
    }
    public function getBoards(){
        try {
            $boards = DB::table('board_tbl')
                        ->where('activate', '=', 1)
                        ->select(['id', 'board_name'])
                        ->get();

            return response()->json([
                'success' => 1,
                'boards' => $boards
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'boards' => 'Failed to retrieve boards'], 500);
        }
    }
}
