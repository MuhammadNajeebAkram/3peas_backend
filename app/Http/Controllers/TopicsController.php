<?php

namespace App\Http\Controllers;

use App\Http\Services\BookService;
use App\Models\BookUnitTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopicsController extends Controller
{
    //
    private BookService $bookService;
    public function __construct(BookService $bookService)
    {
        $this->bookService = $bookService;
    }   
    
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
        $unit_id = $request -> unit_id;

         $is_alp = $request->input('is_alp') ?? 2;
        $activate = $request->input('activate') ?? 2;

        $topics = $this->bookService->getTopicsOfUnit($unit_id, $is_alp, $activate);
        $topicsData = $topics->getData();
       
        if($topicsData->success == 1){
            return response()->json([
                'success' => 1,
                'topics' => $topicsData->topics
            ]);
        } else {
            return response()->json([
                'success' => 0,
                'message' => $topicsData->message
            ], 500);
        }
       
        /*
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

        }*/
    }

    public function saveTopic(Request $request){

        $topic = $this->bookService->saveTopic($request);
        $topic = $topic->getData();
        if($topic->success == 1){
            return response()->json([
                'success' => 1,
                'message' => $topic->message
            ]);
        } else {
            return response()->json([
                'success' => 0,
                'error' => $topic->error
            ], 500);
        }

       /* try{

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

        }*/
    }

    

    public function editTopic(Request $request){
        $topics = $this->bookService->updateTopic($request, $request->id);
        $topicsData = $topics->getData();

        if ($topicsData->success == 1) {
            return response()->json([
                'success' => 1,
                'message' => $topicsData->message
            ]);
        }
        else{
            return response()->json([
                'success' => $topicsData->success, // Duplicate entry or error
                'message' => $topicsData->message
            ]);

        }

       /* try{

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

        }*/
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
        $topic = $this->bookService->saveTopic($request);
        $topic = $topic->getData();
        if($topic->success == 1){
            return response()->json([
                'success' => 1,
                'message' => $topic->message
            ]);
        } else {
            return response()->json([
                'success' => 0,
                'message' => $topic->message
            ], 500);
        }
        /*
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
        }*/
    }

    public function updateNewTopic(Request $request){
        $topic = $this->bookService->updateTopic($request, $request->topic_id);
        $topic = $topic->getData();
        if($topic->success == 1){
            return response()->json([
                'success' => 1,
                'message' => $topic->message
            ]);
        } else {
            return response()->json([
                'success' => 0,
                'message' => $topic->message
            ], 500);
        }
        /*
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
        }*/
    }

    public function getNewTopicsByUnit(Request $request, $unit_id){
         
        $is_alp = $request->input('is_alp') ?? 2;
        $activate = $request->input('activate') ?? 2;

        $topics = $this->bookService->getTopicsOfUnit($unit_id, $is_alp, $activate);
        $topicsData = $topics->getData();
       
        if($topicsData->success == 1){
            return response()->json([
                'success' => 1,
                'topics' => $topicsData->topics
            ]);
        } else {
            return response()->json([
                'success' => 0,
                'message' => $topicsData->message
            ], 500);
        }
        /*
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

        }*/
    }


    //---------------- new lms -------------------

    public function getTopicsByUnitForLMS($unit_id){
        try{
            $topics = BookUnitTopic::where('unit_id', $unit_id)
            ->where('activate', 1)
            ->select('id', 'topic_name', 'topic_no')
            ->get();

            return response()->json($topics);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'topics' => 'Failed to retrieve topics'], 500);

        }
    }

    public function getTopicsForAdmin()
    {
        try {
            $topics = BookUnitTopic::with('bookUnit:id,unit_name')
                ->orderBy('unit_id')
                ->orderBy('topic_no')
                ->get()
                ->map(function ($topic) {
                    return [
                        'id' => $topic->id,
                        'topic_name' => $topic->topic_name,
                        'topic_name_um' => $topic->topic_name_um,
                        'topic_no' => $topic->topic_no,
                        'unit_id' => $topic->unit_id,
                        'unit_name' => $topic->bookUnit?->unit_name,
                        'activate' => (bool) $topic->activate,
                        'is_alp' => (bool) $topic->is_alp,
                    ];
                });

            return response()->json([
                'success' => 1,
                'topics' => $topics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActiveTopicsForAdmin()
    {
        try {
            $topics = BookUnitTopic::with('bookUnit:id,unit_name')
                ->where('activate', 1)
                ->orderBy('unit_id')
                ->orderBy('topic_no')
                ->get()
                ->map(function ($topic) {
                    return [
                        'id' => $topic->id,
                        'topic_name' => $topic->topic_name,
                        'topic_name_um' => $topic->topic_name_um,
                        'topic_no' => $topic->topic_no,
                        'unit_id' => $topic->unit_id,
                        'unit_name' => $topic->bookUnit?->unit_name,
                        'is_alp' => (bool) $topic->is_alp,
                    ];
                });

            return response()->json([
                'success' => 1,
                'topics' => $topics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveTopicForAdmin(Request $request)
    {
        $request->validate([
            'topic_name' => 'required|string|max:255',
            'topic_name_um' => 'nullable|string|max:255',
            'topic_no' => 'nullable|integer|min:1',
            'unit_id' => 'required|integer|exists:book_unit_tbl,id',
            'is_alp' => 'nullable|boolean',
        ]);

        try {
            $duplicateTopic = BookUnitTopic::where('unit_id', $request->unit_id)
                ->where('topic_name', $request->topic_name)
                ->exists();

            if ($duplicateTopic) {
                return response()->json([
                    'success' => 2,
                    'message' => 'Topic name already exists for this unit.',
                ]);
            }

            BookUnitTopic::create([
                'topic_name' => $request->topic_name,
                'topic_name_um' => $request->topic_name_um,
                'topic_no' => $request->topic_no,
                'unit_id' => $request->unit_id,
                'activate' => 1,
                'is_alp' => $request->boolean('is_alp', true),
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Topic saved successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateTopicForAdmin(Request $request, $id)
    {
        $request->validate([
            'topic_name' => 'required|string|max:255',
            'topic_name_um' => 'nullable|string|max:255',
            'topic_no' => 'nullable|integer|min:1',
            'unit_id' => 'required|integer|exists:book_unit_tbl,id',
            'is_alp' => 'nullable|boolean',
        ]);

        try {
            $topic = BookUnitTopic::findOrFail($id);

            $duplicateTopic = BookUnitTopic::where('unit_id', $request->unit_id)
                ->where('topic_name', $request->topic_name)
                ->where('id', '!=', $id)
                ->exists();

            if ($duplicateTopic) {
                return response()->json([
                    'success' => 2,
                    'message' => 'Topic name already exists for this unit.',
                ]);
            }

            $topic->topic_name = $request->topic_name;
            $topic->topic_name_um = $request->topic_name_um;
            $topic->topic_no = $request->topic_no;
            $topic->unit_id = $request->unit_id;
            $topic->is_alp = $request->has('is_alp')
                ? $request->boolean('is_alp')
                : $topic->is_alp;

            if ($request->has('activate')) {
                $topic->activate = $request->activate;
            }

            $topic->save();

            return response()->json([
                'success' => 1,
                'message' => 'Topic updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activateTopicForAdmin(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:book_unit_topic_tbl,id',
            'activate' => 'required|boolean',
        ]);

        try {
            $topic = BookUnitTopic::findOrFail($request->id);
            $topic->activate = $request->activate;
            $topic->save();

            return response()->json([
                'success' => 1,
                'message' => 'Topic status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
