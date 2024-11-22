<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopicsController extends Controller
{
    //
    public function getAllTopics(Request $request){
        try{
            $topics = DB::table('book_unit_topic_view')->get();

            return response()->json([
                'success' => 1,
                'topics' => $topics
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'topics' => 'Failed to retrieve topics'], 500);

        }
    }

    public function saveTopic(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('book_unit_topic_tbl')
        ->where('topic_name', '=', $request->topic_name)
        ->where('unit_id', '=', $request -> unit_id)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Topic Name is already exists.'
            ]);

        }

            $units = DB::table('book_unit_topic_tbl')
            ->insert([
                'topic_name' => $request -> topic_name,
                'topic_no' => $request -> topic_no,
                'unit_id' => $request -> unit_id,               
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

    public function editTopic(Request $request){
        try{

            if($request -> topic_name != $request -> oldTopicName){
                $checkDuplicate = DB::table('book_unit_topic_tbl')
                ->where('topic_name', '=', $request->topic_name)
                ->where('unit_id', '=', $request -> unit_id)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Topic Name is already exists.'
                    ]); 
                }
        
            }

            $unit = DB::table('book_unit_topic_tbl')
         ->where('id', '=', $request -> id)
        ->update(['topic_name' => $request -> topic_name,
                  'topic_no' => $request -> topic_no,
                  'unit_id' => $request -> unit_id,                  
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
    public function activateTopic(Request $request){
        $editClass = DB::table('book_unit_topic_tbl')
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
