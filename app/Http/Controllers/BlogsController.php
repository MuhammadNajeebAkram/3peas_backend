<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;

class BlogsController extends Controller
{
    //
    public function getAllBlogs(Request $request){
        try{
            $blogs = DB::table('blog_view')->get();

            return response()->json([
                'success' => 1,
                'blogs' => $blogs
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'blogs' => 'Failed to retrieve blogs'], 500);

        }
    }

    public function getActiveTopBlogTitle(Request $request){
        try{
            $blog = DB::table('blog_tbl')
            ->where('activate', '=', 1)
            ->select([
                'id',
                'title',
                'language',
                'slug', 
                'description',               
                DB::raw('DATE(created_at) as Date')
            ])
            ->orderBy('created_at', 'desc')
            ->limit(1)
            ->get();

            $blog_image = null;
            
            if ($blog->isNotEmpty()){
                $id = $blog->first()->id;
                $blog_image = DB::table('blog_files_tbl')
                ->where('blog_id', '=', $id) 
                ->where('file_type', 'like', 'image%')               
                ->select('path', 'file_type', 'description')
                ->get();
                
            }
            return response()->json([
                'success' => 1,
                'blog' => $blog,
                'blog_image' => $blog_image,
                
                
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'blog' => 'Failed to retrieve blog',
                'blog_image' => 'Failed to retrieve news image',                
            ], 500);

        }
    }

    public function getBlogsContentBySlug($slug){
        try{
            $blog = DB::table('blog_tbl')
            ->where('slug', '=', $slug)
            ->select([
                'id',
                'title',
                'content',
                'language',
                'description',
                'meta_description',
                DB::raw('DATE(created_at) as Date')
            ])            
            ->get();

            $id = $blog->first()-> id;
            $blog_files = DB::table('blog_files_tbl')
            ->where('blog_id', '=', $id)
            ->where('file_type', 'like', '%image%')             
            ->select(['path', 'description'])
            ->get();

           

            return response()->json([
                'success' => 1,
                'blog' => $blog,
                'blog_files' => $blog_files,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'blog' => 'Failed to retrieve news',
                'blog_files' => 'Failed to retrieve news files',
                'error' => $e], 500);

        }
    }

    public function saveBlogs(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('blog_tbl')
        ->where('slug', '=', $request->slug)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Blogs Slug is already exists.'
            ]);

        }

            $blogs = DB::table('blog_tbl')
            ->insertGetId([
                'title' => $request -> blogs_title,
                'content' => $request -> blogs_content,
                'language' => $request -> language,
                'author_id' => 1,
                'category_id' => $request -> category_id,                
                'slug' => $request -> slug,
                'description' => $request -> description,               
                'meta_description' => $request -> meta_description,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach($request -> file_objects as $file){
                DB::table('blog_files_tbl')
                ->insert([
                    'blog_id' => $blogs,
                    'path' => $file['file_name'],
                    'file_type' => $file['file_type'],
                    'description' => $file['description'],
                    'activate' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),


                ]);
            }
            

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

    public function editBlogs(Request $request){
        try{

            if($request -> slug != $request -> oldSlug){
                $checkDuplicate = DB::table('blog_tbl')
                ->where('slug', '=', $request->slug)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Blog Slug is already exists.'
                    ]); 
                }
        
            }

            $blogs = DB::table('blog_tbl')
         ->where('id', '=', $request -> id)
        ->update([
            'title' => $request -> news_title,
            'content' => $request -> news_content,
            'language' => $request -> language,
            'category_id' => $request -> category_id, 
            'slug' => $request -> slug,
            'description' => $request -> description,                             
            'updated_at' => now()]);

                  if ($blogs) {
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

    public function activateBlogs(Request $request){
        $editBlogs = DB::table('blog_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editBlogs) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }

}
