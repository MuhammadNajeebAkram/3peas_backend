<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassesController extends Controller
{
    public function getClasses(){
        try {
            $classes = DB::table('class_tbl')
                        ->select(['id', 'class_name', 'activate'])
                        ->get();

            return response()->json([
                'success' => 1,
                'classes' => $classes
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'classes' => 'Failed to retrieve classes'], 500);
        }
    }
    public function saveClass($className){
        DB::table('class_tbl')
        ->insert(['class_name' => $className,
                  'activate' => 1,
                  'created_at' => now(),
                  'updated_at' => now()]);

        return response()->json([
            'success' => 1
        ]);
    } 
    public function editClass(Request $request){
        $editClass = DB::table('class_tbl')
         ->where('id', '=', $request -> id)
        ->update(['class_name' => $request -> className,
                  'updated_at' => now()]);

        if ($editClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }
    public function activateClass(Request $request){
        $editClass = DB::table('class_tbl')
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
