<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurriculumBoardController extends Controller
{
    //
    public function getCurriculumBoard(Request $request){
        try{
            $board=DB::table('curriculum_board_tbl')
            ->where('activate', '=', $request -> status)
            ->select('id', 'name')
            ->get();

            return response()->json([
                'success' => 1,
                'board' => $board,
            ]);


        }catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e.getMessage(),
            ]);

        }
    }
}
