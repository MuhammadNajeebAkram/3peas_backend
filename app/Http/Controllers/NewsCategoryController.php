<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewsCategoryController extends Controller
{
    //
    public function getAllNewsCategory(Request $request){
        try{
            $news_category = DB::table('news_category_tbl')->get();

            return response()->json([
                'success' => 1,
                'news_category' => $news_category
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news_category' => 'Failed to retrieve news category'], 500);

        }
    }
    public function getActiveNewsCategory(Request $request){
        try{
            $news_category = DB::table('news_category_tbl')
            ->where('activate', '=', 1)
            ->select([
                'id',
                'category_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'news_category' => $news_category
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news_category' => 'Failed to retrieve news category'], 500);

        }
    }

    public function saveCategory(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('news_category_tbl')
        ->where('category_name', '=', $request->category_name)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Category Name is already exists.'
            ]);

        }

            $books = DB::table('news_category_tbl')
            ->insert([
                'category_name' => $request -> category_name,                
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
                $checkDuplicate = DB::table('news_category_tbl')
                ->where('category_name', '=', $request->category_name)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Category Name is already exists.'
                    ]); 
                }
        
            }

            $category = DB::table('news_category_tbl')
         ->where('id', '=', $request -> id)
        ->update(['category_name' => $request -> category_name,                  
                  'updated_at' => now()]);

                  if ($category) {
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
    public function activateCategory(Request $request){
        $editClass = DB::table('news_category_tbl')
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
