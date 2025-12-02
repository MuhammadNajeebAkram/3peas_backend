<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NewsController extends Controller
{
    protected $newsService;
    public function __construct()
    {
        $this->newsService = new \App\Http\Services\NewsService();
    }
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
                'language',
                'description',
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

    public function getActiveAllNewsArchive(Request $request){
        try{
            $news = DB::table('news_tbl')
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
            ->get();

            return response()->json([
                'success' => 1,
                'news' => $news,               
                
                
            ]);

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),               
                
                
            ]);

        }
    }

    public function getActiveTopNewsTitle(Request $request){
        try{
            $news = DB::table('news_tbl')
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
            ->limit(4)
            ->get();

            $news_image = null;
            
            if ($news->isNotEmpty()){
                $id = $news->first()->id;
                $news_image = DB::table('news_files_tbl')
                ->where('news_id', '=', $id) 
                ->where('file_type', 'like', 'image%')               
                ->select('path', 'file_type', 'description')
                ->get();
                
            }

            foreach ($news as $item) {
                $news_id = $item->id;
        
                $news_doc = DB::table('news_files_tbl')
                    ->where('news_id', '=', $news_id) 
                    ->whereNot('file_type', 'like', '%image%')
                    ->whereNot('file_type', 'like', '%video%')               
                    ->select('path', 'file_type', 'description')
                    ->get();
        
                $item->files = $news_doc;
            }

            
            


           

            return response()->json([
                'success' => 1,
                'news' => $news,
                'news_image' => $news_image,
                
                
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news',
                'news_image' => 'Failed to retrieve news image',
                'news_doc' => 'Failed to retrieve news document',
            ], 500);

        }
    }

    public function getNewsContentById($id){
        try{
            $news = DB::table('news_tbl')
            ->where('id', '=', $id)
            ->select([
                'title',
                'content',
                'language',
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
    public function getNewsContentBySlug($slug){
        try{
            $news = DB::table('news_tbl')
            ->where('slug', '=', $slug)
            ->select([
                'id',
                'title',
                'content',
                'language',
                'description',
                DB::raw('DATE(created_at) as Date')
            ])            
            ->get();

            $id = $news->first()-> id;
            $news_files = DB::table('news_files_tbl')
            ->where('news_id', '=', $id)
            ->whereNot('file_type', 'like', '%image%')
            ->whereNot('file_type', 'like', '%video%')  
            ->select(['path'])
            ->get();

            
            $news_images = DB::table('news_files_tbl')
            ->where('news_id', '=', $id)
            ->where('file_type', 'like', '%image%')             
            ->select(['path', 'description'])
            ->get();

           

            return response()->json([
                'success' => 1,
                'news' => $news,
                'news_files' => $news_files,
                'news_images' => $news_images,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news',
                'news_files' => 'Failed to retrieve news files',
                'news_images' => 'Failed to retrieve news images',
                'error' => $e], 500);

        }
    }

    public function saveNews(Request $request){
        try{

            // Check for duplicate subject name
        $checkDuplicate = DB::table('news_tbl')
        ->where('slug', '=', $request->slug)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'News slug is already exists.'
            ]);

        }

            $news = DB::table('news_tbl')
            ->insertGetId([
                'title' => $request -> news_title,
                'content' => $request -> news_content,
                'language' => $request -> language,
                'category_id' => $request -> category_id,
                'haveFile' => $request -> have_file, 
                'slug' => $request -> slug,
                'description' => $request -> description,               
                'meta_description' => $request -> meta_description,
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach($request -> file_objects as $file){
                DB::table('news_files_tbl')
                ->insert([
                    'news_id' => $news,
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
            'language' => $request -> language,
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

        if ($editNews) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }

    //---------------- New News Module -----------------//
    public function getPaginatedNewsTitles(Request $request){
        try{
            $perPage = $request->input('per_page', 3); // Default to 3 if not provided
            
            $news = $this->newsService->getLatestNewsPaginatedTitles($perPage);
            

            return response()->json([
                'success' => 1,
                'data' => $news
            ]);

        }
        catch(\Exception $e){
            Log::error('Exception in NewsController@getPaginatedNewsTitles: ', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news'], 500);

        }
    }   
    public function getNewsDetailBySlug($slug){
        try{
            $newsItem = $this->newsService->getNewsBySlug($slug);
            

            return response()->json([
                'success' => 1,
                'data' => $newsItem
            ]);

        }
        catch(\Exception $e){
            Log::error('Exception in NewsController@getNewsDetailsBySlug: ', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news'], 500);

        }
    }

    public function getAllSlugs(){
        $slugs = $this->newsService->getAllSlugs();
        $slugsData = $slugs->getData();

        if($slugsData->success == 1){
            return response()->json([
                'success' => 1,
                'data' => $slugsData->data,
            ]);
        }
        else{
             return response()->json([
                'success' => 0,
                'message' => $slugsData->message,
            ]);
        }
        
    }
}
