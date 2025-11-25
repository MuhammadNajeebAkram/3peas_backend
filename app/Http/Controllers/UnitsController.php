<?php

namespace App\Http\Controllers;

use App\Models\BookUnit;
use App\Http\Services\BookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitsController extends Controller
{
    private BookService $bookService;
    public function __construct(BookService $bookService)
    {
        $this->bookService = $bookService;
    }
   
    //
    public function getAllUnits(Request $request){
        try{
            $units = DB::table('book_unit_view')->get();

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'units' => 'Failed to retrieve units'], 500);

        }
    }
    public function getUnitsOfBook($book_id){
        try{
            $units = DB::table('book_unit_tbl')
            ->where('book_id', '=', $book_id)
            ->where('activate', '=', 1)
            ->select([
                'id',
                'unit_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'units' => 'Failed to retrieve units'], 500);

        }
    }
    public function getUnitsByBook(Request $request){
        try{
            $units = DB::table('book_unit_tbl')
            ->where('book_id', '=', $request -> book_id)
            ->where('activate', '=', 1)
            ->select([
                'id',
                'unit_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'units' => 'Failed to retrieve units'], 500);

        }
    }

    public function saveUnit(Request $request){
        $units = $this->bookService->saveUnit($request);
        $unitsData = $units->getData();

        if($unitsData->success == 1){
            return response()->json([
                'success' => 1, // Successfully inserted
                'message' => 'Unit saved successfully.'
            ]);
        }
        else {
            return response()->json([
                'success' => $unitsData->success, // Duplicate entry or error
                'message' => $unitsData->message
            ]);
        }
       


       /*
        try{

            

            
        $checkDuplicate = DB::table('book_unit_tbl')
        ->where('unit_name', '=', $request->unit_name)
        ->where('book_id', '=', $request -> book_id)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Unit Name is already exists.'
            ]);

        }

            $units = DB::table('book_unit_tbl')
            ->insert([
                'unit_name' => $request -> unit_name,
                'unit_no' => $request -> unit_no,
                'book_id' => $request -> book_id,               
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1 // Successfully inserted
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,  // Error occurred
                'error' => $e->getMessage(),
            ]);

        }*/
    }

    public function editUnit(Request $request){
        $units = $this->bookService->updateUnit($request, $request->id);
        $unitsData = $units->getData();

        if($unitsData->success == 1){
            return response()->json([
                'success' => 1, // Successfully updated
                'message' => 'Unit updated successfully.'
            ]);
        }
        else {
            return response()->json([
                'success' => $unitsData->success, // Duplicate entry or error
                'message' => $unitsData->message
            ]);
        }
       /* try{

            if($request -> unit_name != $request -> oldUnitName){
                $checkDuplicate = DB::table('book_unit_tbl')
                ->where('unit_name', '=', $request->unit_name)
                ->where('book_id', '=', $request -> book_id)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Unit Name is already exists.'
                    ]); 
                }
        
            }

            $unit = DB::table('book_unit_tbl')
         ->where('id', '=', $request -> id)
        ->update(['unit_name' => $request -> unit_name,
                  'unit_no' => $request -> unit_no,
                  'book_id' => $request -> book_id,                  
                  'updated_at' => now()]);

                  if ($unit) {
                    return response()->json(['success' => 1], 200);
                } else {
                    return response()->json(['success' => 3, 'message' => 'Bad Request'], 400);
                }

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0, // error
                'message' => $e->getMessage(),
            ]);

        }*/
    }
    public function activateUnit(Request $request){
        $editClass = DB::table('book_unit_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }
    public function getUnitsByUserSelectedBook(Request $request){
        $units = $this->bookService->getUnitsOfUserSelectedBook($request);
        $unitsData = $units->getData();

        if($unitsData->success == 1){
             return response()->json([
            'success' => $unitsData->success,
            'units' => $unitsData->units,
        ]);
           
        }
        else{
             return response()->json([
                'success' => 0,
                'message' => $unitsData->message,
            ]);
        }
       
       /* try{
            $info = DB::table('user_profile_tbl')
            ->where('user_id', $request->user()->id)
            ->select('class_id', 'curriculum_board_id')
            ->first();

           /* $units = DB::table('book_unit_tbl as but')
            ->join('book_tbl as bt', 'but.book_id', '=', 'bt.id')
            ->where('bt.class_id', $info->class_id)
            ->where('bt.subject_id', $request->subject_id)
            ->where('bt.curriculum_board_id', $info->curriculum_board_id)
            ->where('bt.activate', 1)
            ->select('but.id', 'but.unit_name')
            ->get();

            $units = BookUnit::whereHas('book', function ($query) use($info, $request){
                $query->where('class_id', $info->class_id)
                ->where('curriculum_board_id', $info->curriculum_board_id)
                ->where('subject_id', $request->subject_id)
              ->where('activate', 1);
            })
            ->where('activate', 1)
            ->orderBy('book_id', 'asc')
            ->orderBy('unit_no')
            ->select('id', 'unit_name')->get();

            return response()->json([
                'success' => 1,
                'units' => $units,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0, // error
                'message' => $e->getMessage(),
            ]);

        }*/
    }
}
