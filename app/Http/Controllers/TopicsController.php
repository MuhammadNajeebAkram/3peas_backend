<?php

namespace App\Http\Controllers;

use App\Http\Services\BookService;
use App\Http\Services\Freemium\UnifiedEntitlementResolver;
use App\Models\BookUnitTopic;
use App\Models\BookUnit;
use App\Models\SloTopic;
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

    public function getTopicsByUnitForLMS($request, $unit_id = null)
    {
        try {
            if (!($request instanceof Request)) {
                $actualUnitId = $request;
                $request = request();
                $unit_id = $actualUnitId;
            }

            $student = $request->user();

            if (!$student || $student->role !== 'student' || $student->status !== 'active') {
                return response()->json([
                    'success' => 0,
                    'message' => 'Active student account required.',
                ], 403);
            }

            // 1. Resolve DB-grounded unit hierarchy
            $unitRow = DB::table('book_unit_tbl as units')
                ->join('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->join('subject_tbl as subjects', 'subjects.id', '=', 'books.subject_id')
                ->where('units.id', (int) $unit_id)
                ->where('units.activate', 1)
                ->where('books.activate', 1)
                ->select([
                    'units.id as unit_id',
                    'units.unit_name',
                    'units.unit_no',
                    'books.id as book_id',
                    'books.class_id',
                    'books.curriculum_board_id',
                    'subjects.id as subject_id',
                    'subjects.subject_name',
                ])
                ->first();

            if (!$unitRow) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unit not found or inactive.',
                ], 404);
            }

            // 2. Validate client-supplied academic hierarchy parameters if present
            if ($request->filled('book_id') && (int) $request->input('book_id') !== (int) $unitRow->book_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested book.',
                ], 422);
            }

            if ($request->filled('subject_id') && (int) $request->input('subject_id') !== (int) $unitRow->subject_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested subject.',
                ], 422);
            }

            if ($request->filled('class_id') && (int) $request->input('class_id') !== (int) $unitRow->class_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested class.',
                ], 422);
            }

            if ($request->filled('curriculum_board_id') && (int) $request->input('curriculum_board_id') !== (int) $unitRow->curriculum_board_id) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Hierarchy mismatch: Unit does not belong to the requested curriculum board.',
                ], 422);
            }

            $offeredProgramId = $request->integer('offered_program_id') ?: ($request->integer('program_id') ?: null);
            if ($offeredProgramId) {
                $programValid = DB::table('offered_programs as programs')
                    ->join('offered_classes as classes', 'classes.id', '=', 'programs.offered_class_id')
                    ->join('program_subjects as prog_subs', 'prog_subs.offered_program_id', '=', 'programs.id')
                    ->where('programs.id', $offeredProgramId)
                    ->where('programs.is_active', 1)
                    ->where('classes.class_id', $unitRow->class_id)
                    ->where('classes.curriculum_board_id', $unitRow->curriculum_board_id)
                    ->where('prog_subs.subject_id', $unitRow->subject_id)
                    ->where('prog_subs.is_active', 1)
                    ->exists();

                if (!$programValid) {
                    return response()->json([
                        'success' => 0,
                        'message' => 'Hierarchy mismatch: Program does not cover the requested unit subject/class/board.',
                    ], 422);
                }
            }

            // 3. Resolve unified entitlement BEFORE loading topics
            $context = [
                'chapter_id'          => (int) $unitRow->unit_id,
                'book_id'             => (int) $unitRow->book_id,
                'subject_id'          => (int) $unitRow->subject_id,
                'class_id'            => (int) $unitRow->class_id,
                'curriculum_board_id' => (int) $unitRow->curriculum_board_id,
            ];
            if ($offeredProgramId) {
                $context['offered_program_id'] = $offeredProgramId;
            }

            /** @var UnifiedEntitlementResolver $resolver */
            $resolver = app(UnifiedEntitlementResolver::class);
            $decision = $resolver->resolve($student, $context, 'perm_mcqs');

            if ($decision->isDenied()) {
                return response()->json([
                    'success'     => 0,
                    'message'     => $decision->getDenialReason() ?: 'An active subscription is required to access these topics.',
                    'code'        => 'ACCESS_DENIED',
                    'upgrade_url' => $decision->getUpgradeProgramSlug()
                        ? "/lms/subscribe/{$decision->getUpgradeProgramSlug()}"
                        : null,
                ], 403);
            }

            // 4. Access GRANTED: Topic selection validation and loading
            $allUnitTopics = BookUnitTopic::where('unit_id', $unit_id)
                ->where('activate', 1)
                ->select('id', 'topic_name', 'topic_no', 'slo_no')
                ->get();

            if ($request->filled('topic_ids') && $request->input('topic_ids') !== 'all') {
                $rawTopicIds = $request->input('topic_ids');
                if (is_string($rawTopicIds)) {
                    $rawTopicIds = explode(',', $rawTopicIds);
                }
                $requestedTopicIds = array_values(array_unique(array_filter(
                    array_map('intval', (array) $rawTopicIds),
                    fn($id) => $id > 0
                )));

                $allUnitTopicIds = $allUnitTopics->pluck('id')->all();
                $mismatched = array_diff($requestedTopicIds, $allUnitTopicIds);
                if (!empty($mismatched)) {
                    return response()->json([
                        'success'           => 0,
                        'message'           => 'Hierarchy mismatch: One or more requested topic IDs do not belong to the selected unit or are inactive.',
                        'code'              => 'HIERARCHY_MISMATCH',
                        'invalid_topic_ids' => array_values($mismatched),
                    ], 422);
                }

                $topics = $allUnitTopics->whereIn('id', $requestedTopicIds)->values();
            } else {
                $topics = $allUnitTopics;
            }

            if ($request->boolean('with_counts')) {
                /** @var \App\Http\Controllers\Tests\AcademicHierarchyController $hierarchyController */
                $hierarchyController = app(\App\Http\Controllers\Tests\AcademicHierarchyController::class);
                return $hierarchyController->getTopics($request);
            }

            return response()->json($topics);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'topics'  => 'Failed to retrieve topics',
            ], 500);
        }
    }

    public function getTopicsForAdmin()
    {
        try {
            $topics = BookUnitTopic::with([
                'bookUnit:id,unit_name',
                'sloTopics:id,slo_code,slo_title',
            ])
                ->orderBy('unit_id')
                ->orderBy('topic_no')
                ->get()
                ->map(function ($topic) {
                    return [
                        'id' => $topic->id,
                        'topic_name' => $topic->topic_name,
                        'topic_name_um' => $topic->topic_name_um,
                        'topic_no' => $topic->topic_no,
                        'slo_no' => $topic->slo_no,
                        'unit_id' => $topic->unit_id,
                        'unit_name' => $topic->bookUnit?->unit_name,
                        'activate' => (bool) $topic->activate,
                        'is_alp' => (bool) $topic->is_alp,
                        'slo_topics' => $topic->sloTopics->map(fn ($s) => [
                            'id' => $s->id,
                            'slo_code' => $s->slo_code,
                            'slo_title' => $s->slo_title,
                        ]),
                        'slo_topic_ids' => $topic->sloTopics->pluck('id')->all(),
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
            $topics = BookUnitTopic::with([
                'bookUnit:id,unit_name',
                'sloTopics:id,slo_code,slo_title',
            ])
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
                        'slo_no' => $topic->slo_no,
                        'unit_id' => $topic->unit_id,
                        'unit_name' => $topic->bookUnit?->unit_name,
                        'is_alp' => (bool) $topic->is_alp,
                        'slo_topics' => $topic->sloTopics->map(fn ($s) => [
                            'id' => $s->id,
                            'slo_code' => $s->slo_code,
                            'slo_title' => $s->slo_title,
                        ]),
                        'slo_topic_ids' => $topic->sloTopics->pluck('id')->all(),
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
            'slo_no' => 'nullable|string|max:255',
            'unit_id' => 'required|integer|exists:book_unit_tbl,id',
            'is_alp' => 'nullable|boolean',
            'slo_topic_ids' => 'nullable|array',
            'slo_topic_ids.*' => 'integer|exists:slo_topic_tbl,id',
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

            $newTopic = BookUnitTopic::create([
                'topic_name' => $request->topic_name,
                'topic_name_um' => $request->topic_name_um,
                'topic_no' => $request->topic_no,
                'slo_no' => $request->slo_no,
                'unit_id' => $request->unit_id,
                'activate' => 1,
                'is_alp' => $request->boolean('is_alp', true),
            ]);

            if ($request->has('slo_topic_ids')) {
                $newTopic->sloTopics()->sync($request->slo_topic_ids ?? []);
            }

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
            'slo_no' => 'nullable|string|max:255',
            'unit_id' => 'required|integer|exists:book_unit_tbl,id',
            'is_alp' => 'nullable|boolean',
            'slo_topic_ids' => 'nullable|array',
            'slo_topic_ids.*' => 'integer|exists:slo_topic_tbl,id',
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
            $topic->slo_no = $request->slo_no;
            $topic->unit_id = $request->unit_id;
            $topic->is_alp = $request->has('is_alp')
                ? $request->boolean('is_alp')
                : $topic->is_alp;

            if ($request->has('activate')) {
                $topic->activate = $request->activate;
            }

            $topic->save();

            if ($request->has('slo_topic_ids')) {
                $topic->sloTopics()->sync($request->slo_topic_ids ?? []);
            }

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

    /**
     * Bulk import Topics from structured JSON array.
     */
    public function bulkImportTopicsForAdmin(Request $request)
    {
        $request->validate([
            'topics' => 'required|array|min:1',
            'unit_id' => 'nullable|integer|exists:book_unit_tbl,id',
            'update_existing' => 'nullable|boolean',
        ]);

        $defaultUnitId = $request->unit_id;
        $updateExisting = $request->boolean('update_existing', true);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($request->topics as $index => $item) {
                $idxLabel = "Topic #" . ($index + 1);

                $unitId = $item['unit_id'] ?? $defaultUnitId;
                if (!$unitId && !empty($item['unit_name'])) {
                    $unitId = BookUnit::where('unit_name', trim($item['unit_name']))->value('id');
                }

                if (!$unitId) {
                    $errors[] = "$idxLabel: Missing or unresolvable Unit.";
                    $skipped++;
                    continue;
                }

                $topicName = trim($item['topic_name'] ?? $item['name'] ?? $item['title'] ?? '');
                if (!$topicName) {
                    $errors[] = "$idxLabel: Missing required 'topic_name'.";
                    $skipped++;
                    continue;
                }

                $topicNameUm = !empty($item['topic_name_um'])
                    ? trim($item['topic_name_um'])
                    : (!empty($item['urdu_name']) ? trim($item['urdu_name']) : null);

                $topicNo = !empty($item['topic_no']) && is_numeric($item['topic_no'])
                    ? (int) $item['topic_no']
                    : null;

                $sloNo = !empty($item['slo_no']) ? trim($item['slo_no']) : null;

                $isAlp = isset($item['is_alp'])
                    ? filter_var($item['is_alp'], FILTER_VALIDATE_BOOLEAN)
                    : true;

                $activate = isset($item['activate'])
                    ? filter_var($item['activate'], FILTER_VALIDATE_BOOLEAN)
                    : 1;

                // Resolve SLO Topic IDs if provided directly or by SLO codes
                $sloTopicIds = [];
                if (!empty($item['slo_topic_ids']) && is_array($item['slo_topic_ids'])) {
                    $sloTopicIds = array_filter(array_map('intval', $item['slo_topic_ids']));
                } elseif (!empty($item['slo_codes'])) {
                    $codes = is_array($item['slo_codes'])
                        ? $item['slo_codes']
                        : array_map('trim', explode(',', (string) $item['slo_codes']));
                    $sloTopicIds = SloTopic::whereIn('slo_code', $codes)->pluck('id')->toArray();
                }

                // Match existing by unit and topic name
                $existing = BookUnitTopic::where('unit_id', $unitId)
                    ->where('topic_name', $topicName)
                    ->first();

                if (!$existing && $topicNo !== null) {
                    $existing = BookUnitTopic::where('unit_id', $unitId)
                        ->where('topic_no', $topicNo)
                        ->first();
                }

                if ($existing) {
                    if (!$updateExisting) {
                        $skipped++;
                        continue;
                    }

                    $existing->topic_name = $topicName;
                    if ($topicNameUm !== null) {
                        $existing->topic_name_um = $topicNameUm;
                    }
                    if ($topicNo !== null) {
                        $existing->topic_no = $topicNo;
                    }
                    if ($sloNo !== null) {
                        $existing->slo_no = $sloNo;
                    }
                    $existing->is_alp = $isAlp;
                    $existing->activate = $activate;
                    $existing->save();

                    if (!empty($sloTopicIds)) {
                        $existing->sloTopics()->syncWithoutDetaching($sloTopicIds);
                    }

                    $updated++;
                } else {
                    if ($topicNo === null) {
                        $maxNo = BookUnitTopic::where('unit_id', $unitId)->max('topic_no');
                        $topicNo = ($maxNo ? $maxNo : 0) + 1;
                    }

                    $topic = BookUnitTopic::create([
                        'unit_id' => $unitId,
                        'topic_name' => $topicName,
                        'topic_name_um' => $topicNameUm,
                        'topic_no' => $topicNo,
                        'slo_no' => $sloNo,
                        'is_alp' => $isAlp,
                        'activate' => $activate,
                    ]);

                    if (!empty($sloTopicIds)) {
                        $topic->sloTopics()->sync($sloTopicIds);
                    }

                    $imported++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => "Import complete. $imported created, $updated updated, $skipped skipped.",
                'total_imported' => $imported,
                'total_updated' => $updated,
                'total_skipped' => $skipped,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => 0,
                'error' => 'Failed to import topics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
