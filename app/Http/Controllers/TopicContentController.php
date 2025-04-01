<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TopicContentController extends Controller
{
    //

    public function saveContent(Request $request){
        try{

            DB::table('topic_content_type_tbl')
            ->insert([
                'name' => $request->name,
                'name_um' => $request->name_um,
                'is_mcq' => $request->is_mcq || 0,
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

        public function getAllContents(){
            try{
                $contents = DB::table('topic_content_type_tbl')
                ->select('id', 'name', 'name_um', 'activate')
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
                ->select('tcst.topic_content_type_id', 'tcst.id', 'tctt.name', 'tctt.is_mcq')
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
    }

