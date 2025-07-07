<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionTypesController extends Controller
{
    //
    public function getAllTypes(Request $request){
        try{
            $types = DB::table('question_type_tbl')
            ->select([
                'id',
                'type_name',
                'activate',
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'types' => $types
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'types' => 'Failed to retrieve types'], 500);

        }
    }

    public function getActivateQuestionTypes(Request $request){
        try{
            $types = DB::table('question_type_tbl')
            ->where('activate', '=', 1)
            ->select([
                'id',
                'type_name',                
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'types' => $types
            ], 200);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'message' => $e->getMessage()], 500);

        }
    }


    public function saveType(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('question_type_tbl')
        ->where('type_name', '=', $request->topic_name)        
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Type Name is already exists.'
            ]);

        }

            $units = DB::table('question_type_tbl')
            ->insert([
                'type_name' => $request -> type_name,                            
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

    public function editType(Request $request){
        try{

            if($request -> type_name != $request -> oldTypeName){
                $checkDuplicate = DB::table('question_type_tbl')
                ->where('type_name', '=', $request->type_name)                
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Type Name is already exists.'
                    ]); 
                }
        
            }

            $qtype = DB::table('question_type_tbl')
         ->where('id', '=', $request -> id)
        ->update(['type_name' => $request -> type_name,                               
                  'updated_at' => now()]);

                  if ($qtype) {
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
    public function activateType(Request $request){
        $editType = DB::table('question_type_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editType) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }
}
