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
        Log::info('Entered NewsController@saveNews with request data: ', $request->all());
        try{
             $validatedData = $request->validate([
            'news_title' => 'required|string|max:255',
            'slug' => 'required|string|unique:news_tbl,slug',
            'news_content' => 'required|string',
            'language' => 'required|string',
            'description' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:news_category_tbl,id',
            'have_file' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'files.*' => 'nullable|file|mimes:jpeg,png,jpg,pdf,doc,docx,mp4,mov,avi', // Max 5MB per file
            'featured_image' => 'nullable|file|mimes:jpeg,png,jpg',
            'breaking_news_image' => 'nullable|file|mimes:jpeg,png,jpg',            
            'priority_score' => 'nullable|integer',
            'is_breaking_news' => 'nullable|boolean',
            'expires_at' => 'nullable|date',
            'status' => 'nullable|string',
            'meta_title' => 'nullable|string',
            'url_link' => 'nullable|url',
            'ticker' => 'nullable|string',
        ]);

        return $this->newsService->saveNews($validatedData);
        

        }catch(\Illuminate\Validation\ValidationException $e){
             Log::error('validation error in saveNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
            $errors = $e->errors();
            if(isset($errors['slug'])){
                foreach($errors['slug'] as $msg){
                    if(strpos($msg, 'unique') !== false || strpos(strtolower($msg), 'already exists') !== false){
                        return response()->json([
                            'success' => 2,
                            'message' => 'Duplicate entry: Slug already exists.'
                        ], 409);
                    }
                }
            }   
            return response()->json([
                'success' => 0,
                'message' => 'Validation error: ' . $e->getMessage(),
            ], 422);
        }
        catch(\Exception $e){
             Log::error('Database error in saveNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
            return response()->json([
                'success' => 0,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);

        }
       
    }
    public function getBreakingNews(Request $request){

       return $this->newsService->getBreakingNews();
    }
    public function getFeaturedNews(Request $request){

       return $this->newsService->getFeaturedNews();
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
        $perPage = $request->input('per_page', 3);
        return $this->newsService->getLatestNewsPaginatedTitles($perPage);
      /*  try{
            $perPage = $request->input('per_page', 3); // Default to 3 if not provided
            
            $news = $this->newsService->getLatestNewsPaginatedTitles($perPage);
            $newsData = $news->getData();

            if($newsData->success == 1){
                return response()->json([
                'success' => 1,
                'news' => $newsData->data
            ]);
            }
            else
            {
                 return response()->json([
                'success' => 0,
                'message' => $newsData->message,
            ]);
            }
            

           

        }
        catch(\Exception $e){
            Log::error('Exception in NewsController@getPaginatedNewsTitles: ', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => 0,
                'news' => 'Failed to retrieve news'], 500);

        }*/
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
