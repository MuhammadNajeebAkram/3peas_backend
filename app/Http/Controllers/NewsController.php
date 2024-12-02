<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewsController extends Controller
{
    //
    public function getAllNews(Request $request){
        try{
            $news = DB::table('news_view')->get();

            return response()->json([
                'success' => 1,
                'news' => $news
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news'], 500);

        }
    }

    public function getActiveNewsTitle(Request $request){
        try{
            $news = DB::table('news_tbl')
            ->where('activate', '=', 1)
            ->select([
                'id',
                'title',
                DB::raw('DATE(created_at) as Date')
            ])
            ->orderby('created_at', 'desc')
            ->get();

            return response()->json([
                'success' => 1,
                'news' => $news
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news'], 500);

        }
    }

    public function getNewsContentById($id){
        try{
            $news = DB::table('news_tbl')
            ->where('id', '=', $id)
            ->select([
                'title',
                'content',
                DB::raw('DATE(created_at) as Date')
            ])            
            ->get();

            return response()->json([
                'success' => 1,
                'news' => $news
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news'], 500);

        }
    }

    public function saveNews(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('news_tbl')
        ->where('content', '=', $request->news_content)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'News Content is already exists.'
            ]);

        }

            $news = DB::table('news_tbl')
            ->insert([
                'title' => $request -> news_title,
                'content' => $request -> news_content,
                'category_id' => $request -> category_id,
                'haveFile' => $request -> have_file,                
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

    public function editCategory(Request $request){
        try{

            if($request -> category_name != $request -> oldCategoryName){
                $checkDuplicate = DB::table('news_tbl')
                ->where('content', '=', $request->news_content)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'news content is already exists.'
                    ]); 
                }
        
            }

            $news = DB::table('news_tbl')
         ->where('id', '=', $request -> id)
        ->update([
            'title' => $request -> news_title,
            'content' => $request -> news_content,
            'category_id' => $request -> category_id,
            'haveFile' => $request -> have_file,                   
            'updated_at' => now()]);

                  if ($news) {
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
    public function activateNews(Request $request){
        $editNews = DB::table('news_tbl')
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
