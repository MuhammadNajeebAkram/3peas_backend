<?php

namespace App\Http\Controllers;

use App\Models\TopicContentStructure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TopicContentController extends Controller
{
    //

    public function saveContent(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_um' => 'nullable|string|max:255',
            'has_child' => 'nullable|boolean',
            'is_mcq' => 'nullable|boolean',
        ]);

        try{

            DB::table('topic_content_type_tbl')
            ->insert([
                'name' => $validated['name'],
                'name_um' => $validated['name_um'] ?? null,
                'has_child' => $validated['has_child'] ?? false,
                'is_mcq' => $validated['is_mcq'] ?? false,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(), 
            ]);
            
            return response()->json([
                'success' => 1,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e -> getMessage()], 500);

        }
        }

        public function updateContent(Request $request, $id){
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'name_um' => 'nullable|string|max:255',
                'has_child' => 'nullable|boolean',
                'is_mcq' => 'nullable|boolean',
                'activate' => 'nullable|boolean',
            ]);

            try{
                $updated = DB::table('topic_content_type_tbl')
                ->where('id', $id)
                ->update([
                    'name' => $validated['name'],
                    'name_um' => $validated['name_um'] ?? null,
                    'has_child' => $validated['has_child'] ?? false,
                    'is_mcq' => $validated['is_mcq'] ?? false,
                    'activate' => array_key_exists('activate', $validated)
                        ? $validated['activate']
                        : DB::raw('activate'),
                    'updated_at' => now(),
                ]);

                if ($updated) {
                    return response()->json([
                        'success' => 1,
                        'message' => 'Topic content updated successfully.',
                    ]);
                }

                return response()->json([
                    'success' => 0,
                    'message' => 'Topic content not found or unchanged.',
                ], 404);

            }catch(\Exception $e){
                return response()->json([
                    'success' => 0,
                    'error' => $e -> getMessage()], 500);

            }
        }

        public function getAllContents(){
            try{
                $contents = DB::table('topic_content_type_tbl')
                ->select('id', 'name', 'name_um', 'has_child', 'activate', 'is_mcq')
                ->get();

                return response()->json([
                    'success' => 1,
                    'contents' => $contents,
                ]);

            }
            catch(\Exception $e){
                return response()->json([
                    'success' => 0,
                    'error' => $e -> getMessage()], 500);

            }

        }

        public function getContentsByTopic($topic_id){
            try{
                $contents = DB::table('topic_content_structure_tbl as tcst')
                ->join('topic_content_type_tbl as tctt', 'tcst.topic_content_type_id', '=', 'tctt.id')
                ->where('tcst.topic_id', $topic_id)
                ->select(
                    'tcst.id',
                    'tcst.topic_id',
                    'tcst.topic_content_type_id',
                    'tctt.name',
                    'tctt.name_um',
                    'tctt.has_child',
                    'tctt.is_mcq',
                    'tctt.activate'
                )
                ->orderBy('tctt.name')
                ->get();

                return response()->json([
                    'success' => 1,
                    'contents' => $contents,
                ]);

            }
            catch(\Exception $e){

                return response()->json([
                    'success' => 0,
                    'error' => $e -> getMessage()
                ], 500);

            }
        }

        public function activateTopicContent(Request $request){
        $editClass = DB::table('topic_content_type_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }

        public function getTopicContentStructures($topic_id){
            return $this->getContentsByTopic($topic_id);
        }

        public function saveTopicContentStructure(Request $request){
            $validated = $request->validate([
                'topic_id' => 'required|integer|exists:book_unit_topic_tbl,id',
                'topic_content_type_id' => 'required|integer|exists:topic_content_type_tbl,id',
            ]);

            try{
                $structure = TopicContentStructure::firstOrCreate($validated);

                return response()->json([
                    'success' => 1,
                    'message' => 'Topic content structure saved successfully.',
                    'structure' => $structure,
                ]);

            }catch(\Exception $e){
                return response()->json([
                    'success' => 0,
                    'error' => $e -> getMessage()], 500);

            }
        }

        public function syncTopicContentStructures(Request $request){
            $validated = $request->validate([
                'topic_id' => 'required|integer|exists:book_unit_topic_tbl,id',
                'topic_content_type_ids' => 'required|array',
                'topic_content_type_ids.*' => 'integer|exists:topic_content_type_tbl,id',
            ]);

            try{
                DB::beginTransaction();

                TopicContentStructure::where('topic_id', $validated['topic_id'])->delete();

                collect($validated['topic_content_type_ids'])
                    ->unique()
                    ->each(function ($contentTypeId) use ($validated) {
                        TopicContentStructure::create([
                            'topic_id' => $validated['topic_id'],
                            'topic_content_type_id' => $contentTypeId,
                        ]);
                    });

                DB::commit();

                return response()->json([
                    'success' => 1,
                    'message' => 'Topic content structures updated successfully.',
                ]);

            }catch(\Exception $e){
                DB::rollBack();

                return response()->json([
                    'success' => 0,
                    'error' => $e -> getMessage()], 500);

            }
        }

        public function deleteTopicContentStructure($id){
            try{
                $deleted = TopicContentStructure::where('id', $id)->delete();

                if ($deleted) {
                    return response()->json([
                        'success' => 1,
                        'message' => 'Topic content structure deleted successfully.',
                    ]);
                }

                return response()->json([
                    'success' => 0,
                    'message' => 'Topic content structure not found.',
                ], 404);

            }catch(\Exception $e){
                return response()->json([
                    'success' => 0,
                    'error' => $e -> getMessage()], 500);

            }
        }
    }
