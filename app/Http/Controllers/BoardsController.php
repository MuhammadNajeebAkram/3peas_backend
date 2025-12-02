<?php

namespace App\Http\Controllers;

use App\Http\Services\PastPaperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardsController extends Controller
{
    protected   PastPaperService $pastPaperService;
    public function __construct()
    {
        $this->pastPaperService = new PastPaperService();
        
    }
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
    public function getAllBoards(Request $request){
        try {
            $boards = DB::table('board_tbl')
                        ->select(['id', 'board_name', 'icon_name', 'activate'])
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
    public function saveBoard(Request $request)
{
    try {
        // Check for duplicate subject name
        $checkDuplicate = DB::table('board_tbl')
            ->where('board_name', '=', $request->board_name)
            ->exists();  // Use exists() to check if the record exists
    
        if (!$checkDuplicate) {
            // Insert the new subject
            DB::table('board_tbl')
                ->insert([
                    'board_name' => $request->board_name,
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
                'message' => 'Board already exists.'
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => 0,  // Error occurred
            'error' => $e->getMessage(),
        ]);
    }
}


    public function editBoard(Request $request){
        try{

        
        if($request -> board_name != $request -> oldBoardName){
            $checkDuplicate = DB::table('board_tbl')
            ->where('board_name', '=', $request->board_name)
            ->exists();  // Use exists() to check if the record exists
            if($checkDuplicate){
                return response()->json([
                    'success' => 2, // Duplicate entry
                    'message' => 'Board already exists.'
                ]); 
            }
    
        }
        $boardClass = DB::table('board_tbl')
         ->where('id', '=', $request -> id)
        ->update(['board_name' => $request -> board_name,
                  'icon_name' => $request -> icon_name,
                  'updated_at' => now()]);

        if ($boardClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }catch(\Exception $e){
        return response()->json([
            'success' => 0, // error
            'message' => $e->getMessage(),
        ]);
    }
    }
    public function activateBoard(Request $request){
        $editBoard = DB::table('board_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editBoard) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }

    public function getBoardData(){

        $boards = $this->pastPaperService->getBoardData();
        $boardsData = $boards->getData();

        if($boardsData->success == 1){
            return response()->json([
                'success' => 1,
                'data' => $boardsData->data
            ]);
        }
        else{
           return response()->json([
            'success' => $boardsData->success,
            'message' => $boardsData->message,
           ]);
        }

    }

    public function searchResult(Request $request){
        $result = $this->pastPaperService->searchResult($request);
        $resultData = $result->getData();

        if($resultData->success == 1){
            return response()->json([
                'success' => 1,
                'data' => $resultData->data,
            ]);
        }
        else{
             return response()->json([
                'success' => $resultData->success,
                'message' => $resultData->message,
            ]);

        }
    }
}
