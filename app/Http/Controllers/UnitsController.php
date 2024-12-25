<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitsController extends Controller
{
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
        try{

            // Check for duplicate subject name
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

        }
    }

    public function editUnit(Request $request){
        try{

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

                  if ($book) {
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

        }
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
}
