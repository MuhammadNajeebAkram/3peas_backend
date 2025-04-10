<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth as FacadesAuth;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Contracts\Providers\Auth;

class ClassesController extends Controller
{
    public function getClasses(Request $request){
        
        try {
            if(!$request->status){
                $classes = DB::table('class_tbl')
                ->select(['id', 'class_name', 'activate'])
                ->get();

    return response()->json([
        'success' => 1,
        'classes' => $classes
    ]);

            }
            else{
                $classes = DB::table('class_tbl')
                ->where('activate', '=', $request -> status)
                ->select(['id', 'class_name as name'])
                ->get();

    return response()->json([
        'success' => 1,
        'classes' => $classes
    ]);
            }
           

            
        } catch (\Exception $e) {
            // Handle the exception, e.g., log it or return an error response
            return response()->json([
                'success' => 0,
                'classes' => 'Failed to retrieve classes'], 500);
        }
    }
    public function saveClass($className){
        try{
            // Check for duplicate subject name
        $checkDuplicate = DB::table('class_tbl')
        ->where('class_name', '=', $className)
        ->exists();  // Use exists() to check if the record exists

        if (!$checkDuplicate){
            DB::table('class_tbl')
            ->insert(['class_name' => $className,
                      'activate' => 1,
                      'created_at' => now(),
                      'updated_at' => now()]);
    
            return response()->json([
                'success' => 1
            ]);
        }else {
            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Subject is already exists.'
            ]);
        }

            
        } catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'message' => $e -> getMessage(),
            ]);
        }
        
    } 
    public function editClass(Request $request){
        try{
            if($request -> class_name != $request -> oldClassName){
                $checkDuplicate = DB::table('class_tbl')
            ->where('class_name', '=', $request->class_name)
            ->exists();  // Use exists() to check if the record exists
            if($checkDuplicate){
                return response()->json([
                    'success' => 2, // Duplicate entry
                    'message' => 'Class Name is already exists.'
                ]); 
            }
            }
            $editClass = DB::table('class_tbl')
            ->where('id', '=', $request -> id)
           ->update(['class_name' => $request -> class_name,
                     'updated_at' => now()]);
   
           if ($editClass) {
               return response()->json(['success' => 1], 200);
           } else {
               return response()->json(['success' => 3, 'message' => 'Bad Request'], 400);
           }
        } catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'message' => $e -> getMessage(),
            ]);
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

    public function getClassOfUser(Request $request){
        try{

            $user = $request->user();
            $class = DB::table('user_profile_tbl')
            ->where('user_id', $user->id)
            ->select('class_id')
            ->first();

            return response()->json([
                'success' => 1,
                'classId' => $class,
            ]);

        }catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage()
            ]);

        }
    }

    
}
