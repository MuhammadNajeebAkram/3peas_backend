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

    public function getTopicsByUnit(Request $request){
        try{
            $topics = DB::table('book_unit_topic_tbl')
            ->where('activate', '=', 1)
            ->where('unit_id', '=', $request -> unit_id)
            ->select([
                'id',
                'topic_name',
            ])
            ->get();

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

            $topic = DB::table('book_unit_topic_tbl')
         ->where('id', '=', $request -> id)
        ->update(['topic_name' => $request -> topic_name,
                  'topic_no' => $request -> topic_no,
                  'unit_id' => $request -> unit_id,                  
                  'updated_at' => now()]);

                  if ($topic) {
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
//-------------------------- New Topic ------------------
    public function saveNewTopic(Request $request){
        try{
            DB::beginTransaction();

            $topic = DB::table('book_unit_topic_tbl')
            ->insertGetId([
                'topic_name' => $request -> topic_name,
                'topic_name_um' => $request -> topic_name_um,
                'topic_no' => $request -> topic_no,
                'unit_id' => $request -> unit_id,               
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach($request->contents as $content){
                DB::table('topic_content_structure_tbl')
                ->insert([
                    'topic_id' => $topic,
                    'topic_content_type_id' => $content,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
            ]);


        }
        catch(\Exception $e){

            DB::rollBack();
            return response()->json([
                'success' => 0,  // Error occurred
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updateNewTopic(Request $request){
        try{
            DB::beginTransaction();

            DB::table('book_unit_topic_tbl')
            ->where('id', $request->topic_id)
            ->update([
                'topic_name' => $request -> topic_name,
                'topic_name_um' => $request -> topic_name_um,
                'topic_no' => $request -> topic_no,
                'unit_id' => $request -> unit_id,
                'updated_at' => now(),
            ]);

            DB::table('topic_content_structure_tbl')
            ->where('topic_id', $request->topic_id)
            ->delete();

            foreach($request->contents as $content){
                DB::table('topic_content_structure_tbl')
                ->insert([
                    'topic_id' => $request->topic_id,
                    'topic_content_type_id' => $content,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
            ]);


        }
        catch(\Exception $e){

            DB::rollBack();
            return response()->json([
                'success' => 0,  // Error occurred
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getNewTopicsByUnit($unit_id){
        try{
            $topics = DB::table('book_unit_topic_tbl')
            ->where('unit_id', $unit_id)
            ->select('id', 'topic_name', 'topic_name_um', 'topic_no', 'activate') 
            ->get();

            return response()->json([
                'success' => 1,
                'topics' => $topics,
            ], 200);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,  // Error occurred
                'error' => $e->getMessage(),
            ], 500);

        }
    }

    


}
