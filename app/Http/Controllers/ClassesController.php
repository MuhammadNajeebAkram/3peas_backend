<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassesController extends Controller
{
    public function getClasses(){
        try {
            $classes = DB::table('class_tbl')
                        ->select(['id', 'class_name'])
                        ->get();

            return response()->json([
                'success' => 1,
                'response' => $classes
            ]);

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'response' => 'Failed to retrieve classes'], 500);
        }
    }
}
