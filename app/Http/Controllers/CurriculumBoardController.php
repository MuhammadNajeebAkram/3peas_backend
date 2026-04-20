<?php

namespace App\Http\Controllers;

use App\Models\CurriculumBoard;
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
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getAllCurriculumBoardsForAdmin()
    {
        try {
            $boards = CurriculumBoard::orderBy('name')->get();

            return response()->json([
                'success' => 1,
                'boards' => $boards,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActiveCurriculumBoardsForAdmin()
    {
        try {
            $boards = CurriculumBoard::where('activate', 1)
                ->orderBy('name')
                ->get(['id', 'name']);

            return response()->json([
                'success' => 1,
                'data' => $boards,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveCurriculumBoardForAdmin(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:curriculum_board_tbl,name',
        ]);

        try {
            CurriculumBoard::create([
                'name' => $request->name,
                'activate' => 1,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Curriculum board saved successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateCurriculumBoardForAdmin(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:curriculum_board_tbl,name,' . $id,
        ]);

        try {
            $board = CurriculumBoard::findOrFail($id);
            $board->name = $request->name;

            if ($request->has('activate')) {
                $board->activate = $request->activate;
            }

            $board->save();

            return response()->json([
                'success' => 1,
                'message' => 'Curriculum board updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activateCurriculumBoardForAdmin(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:curriculum_board_tbl,id',
            'activate' => 'required|boolean',
        ]);

        try {
            $board = CurriculumBoard::findOrFail($request->id);
            $board->activate = $request->activate;
            $board->save();

            return response()->json([
                'success' => 1,
                'message' => 'Curriculum board status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
