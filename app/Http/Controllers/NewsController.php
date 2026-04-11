<?php

namespace App\Http\Controllers;

use App\Http\Services\AwsUploadService;
use App\Models\News;
use App\Models\NewsMedia;
use App\Models\NewsTicker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NewsController extends Controller
{
    protected $newsService;
    protected $awsUploadService;

    public function __construct()
    {
        $this->newsService = new \App\Http\Services\NewsService();
        $this->awsUploadService = new AwsUploadService();
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

    public function getAllNewsForAdmin(Request $request)
    {
        try {
            $query = News::query()
                ->with(['category:id,name'])
                ->orderByDesc('created_at');

            if ($request->filled('language')) {
                $query->where('language', $request->language);
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled('status')) {
                $status = strtolower((string) $request->status);

                if ($status === 'published') {
                    $query->where('is_published', true);
                } elseif ($status === 'draft') {
                    $query->where('is_published', false);
                }
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($builder) use ($search) {
                    $builder->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('summary', 'like', "%{$search}%");
                });
            }

            $news = $query->get()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'summary' => $item->summary,
                    'language' => $item->language,
                    'news_type' => $item->news_type,
                    'category_id' => $item->category_id,
                    'category_name' => $item->category?->name,
                    'featured_image' => $item->featured_image,
                    'thumbnail_image' => $item->thumbnail_image,
                    'is_breaking' => (bool) $item->is_breaking,
                    'is_featured' => (bool) $item->is_featured,
                    'is_published' => (bool) $item->is_published,
                    'is_activated' => (bool) $item->is_activated,
                    'published_at' => optional($item->published_at)->toDateTimeString(),
                    'created_at' => optional($item->created_at)->toDateTimeString(),
                    'updated_at' => optional($item->updated_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => 1,
                'news' => $news,
            ]);
        } catch (\Throwable $e) {
            Log::error('getAllNewsForAdmin failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve admin news list.',
                'error' => $e->getMessage(),
            ], 500);
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

    public function getPublishableFeturedNewsForWeb(Request $request)
    {
        try {
            $limit = (int) ($request->get('limit', 6));
            $limit = $limit > 0 ? min($limit, 20) : 6;
            $now = now();

            $news = News::query()
                ->with(['category:id,name'])
                ->where('is_published', true)
                ->where('is_featured', true)
                ->where('is_activated', true)
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('published_at')
                        ->orWhere('published_at', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', $now);
                })
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'slug' => $item->slug,
                        'summary' => $item->summary,
                        'language' => $item->language,
                        'category_id' => $item->category_id,
                        'category_name' => $item->category?->name,
                        'featured_image' => $item->featured_image,
                        'thumbnail_image' => $item->thumbnail_image,
                        'is_breaking' => (bool) $item->is_breaking,
                        'published_at' => optional($item->published_at)->toDateTimeString(),
                        'created_at' => optional($item->created_at)->toDateTimeString(),
                    ];
                });

            return response()->json([
                'success' => 1,
                'data' => $news,
            ]);
        } catch (\Throwable $e) {
            Log::error('getPublishableFeturedNewsForWeb failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve featured news.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPublishedEducationalNewsForWeb(Request $request)
    {
        try {
            $limit = (int) ($request->get('limit', 12));
            $limit = $limit > 0 ? min($limit, 50) : 12;
            $now = now();

            $query = News::query()
                ->with(['category:id,name'])
                ->where('is_published', true)
                ->where('is_activated', true)
                ->where('news_type', 'educational')
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('published_at')
                        ->orWhere('published_at', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', $now);
                })
                ->orderByDesc('published_at')
                ->orderByDesc('created_at');

            if ($request->filled('language')) {
                $query->where('language', $request->language);
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled('news_type')) {
                $query->where('news_type', $request->news_type);
            }

            $news = $query->limit($limit)->get()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'summary' => $item->summary,
                    'language' => $item->language,
                    'news_type' => $item->news_type,
                    'category_id' => $item->category_id,
                    'category_name' => $item->category?->name,
                    'featured_image' => $item->featured_image,
                    'thumbnail_image' => $item->thumbnail_image,
                    'published_at' => optional($item->published_at)->toDateTimeString(),
                    'created_at' => optional($item->created_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => 1,
                'data' => $news,
            ]);
        } catch (\Throwable $e) {
            Log::error('getPublishedEducationalNewsForWeb failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve educational news.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getEventNewsForWeb(Request $request)
    {
        try {
            $limit = (int) ($request->get('limit', 12));
            $limit = $limit > 0 ? min($limit, 50) : 12;
            $now = now();

            $query = News::query()
                ->with(['category:id,name'])
                ->where('is_published', true)
                ->where('is_activated', true)
                ->where('news_type', 'event')
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('published_at')
                        ->orWhere('published_at', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', $now);
                })
                ->orderByDesc('published_at')
                ->orderByDesc('created_at');

            if ($request->filled('language')) {
                $query->where('language', $request->language);
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            $news = $query->limit($limit)->get()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'summary' => $item->summary,
                    'language' => $item->language,
                    'news_type' => $item->news_type,
                    'category_id' => $item->category_id,
                    'category_name' => $item->category?->name,
                    'featured_image' => $item->featured_image,
                    'thumbnail_image' => $item->thumbnail_image,
                    'event_date' => $item->event_date,
                    'location' => $item->location,
                    'published_at' => optional($item->published_at)->toDateTimeString(),
                    'created_at' => optional($item->created_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => 1,
                'data' => $news,
            ]);
        } catch (\Throwable $e) {
            Log::error('getEventNewsForWeb failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve event news.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getVideoNewsForWeb(Request $request)
    {
        try {
            $limit = (int) ($request->get('limit', 12));
            $limit = $limit > 0 ? min($limit, 50) : 12;
            $now = now();

            $query = News::query()
                ->with(['category:id,name'])
                ->where('is_published', true)
                ->where('is_activated', true)
                ->where('news_type', 'video')
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('published_at')
                        ->orWhere('published_at', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', $now);
                })
                ->orderByDesc('published_at')
                ->orderByDesc('created_at');

            if ($request->filled('language')) {
                $query->where('language', $request->language);
            }

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            $news = $query->limit($limit)->get()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'summary' => $item->summary,
                    'language' => $item->language,
                    'news_type' => $item->news_type,
                    'category_id' => $item->category_id,
                    'category_name' => $item->category?->name,
                    'featured_image' => $item->featured_image,
                    'thumbnail_image' => $item->thumbnail_image,
                    'video_url' => $item->video_url,
                    'published_at' => optional($item->published_at)->toDateTimeString(),
                    'created_at' => optional($item->created_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => 1,
                'data' => $news,
            ]);
        } catch (\Throwable $e) {
            Log::error('getVideoNewsForWeb failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve video news.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getNewsDetailForWebBySlug($slug)
    {
        try {
            $now = now();

            $news = News::query()
                ->with(['category:id,name', 'media'])
                ->where('slug', $slug)
                ->where('is_published', true)
                ->where('is_activated', true)
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('published_at')
                        ->orWhere('published_at', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', $now);
                })
                ->firstOrFail();

            $media = $news->media->map(function ($item) {
                return [
                    'id' => $item->id,
                    'media_type' => $item->media_type,
                    'media_url' => $item->media_url,
                    'thumbnail_url' => $item->thumbnail_url,
                    'caption' => $item->caption,
                    'alt_text' => $item->alt_text,
                    'display_order' => $item->display_order,
                ];
            })->values();

            return response()->json([
                'success' => 1,
                'data' => [
                    'id' => $news->id,
                    'title' => $news->title,
                    'slug' => $news->slug,
                    'summary' => $news->summary,
                    'content' => $news->content,
                    'language' => $news->language,
                    'news_type' => $news->news_type,
                    'category_id' => $news->category_id,
                    'category_name' => $news->category?->name,
                    'featured_image' => $news->featured_image,
                    'thumbnail_image' => $news->thumbnail_image,
                    'og_image' => $news->og_image,
                    'video_url' => $news->video_url,
                    'published_at' => optional($news->published_at)->toDateTimeString(),
                    'created_at' => optional($news->created_at)->toDateTimeString(),
                    'media' => $media,
                    'documents' => [],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'News not found.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('getNewsDetailForWebBySlug failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to load news detail.',
                'error' => $e->getMessage(),
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

    public function saveNewsFromAdmin(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:news,slug',
                'summary' => 'nullable|string',
                'content' => 'required|string',
                'category_id' => 'required|integer|exists:news_categories,id',
                'language' => 'required|in:en,ur',
                'news_type' => 'nullable|in:article,educational,event,video,gallery,announcement',
                'institute_id' => 'nullable|integer',
                'event_date' => 'nullable|date',
                'location' => 'nullable|string|max:255',
                'featured_image' => 'nullable|string|max:255',
                'thumbnail_image' => 'nullable|string|max:255',
                'og_image' => 'nullable|string|max:255',
                'video_url' => 'nullable|url',
                'published_at' => 'nullable|date',
                'expires_at' => 'nullable|date',
                'display_order' => 'nullable|integer|min:0',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:255',
                'meta_keywords' => 'nullable|string|max:255',
                'is_breaking' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'is_published' => 'nullable|boolean',
                'is_activated' => 'nullable|boolean',
                'media' => 'nullable|array',
                'media.*.media_type' => 'required_with:media|in:image,video',
                'media.*.media_url' => 'required_with:media|string|max:255',
                'media.*.thumbnail_url' => 'nullable|string|max:255',
                'media.*.caption' => 'nullable|string|max:255',
                'media.*.alt_text' => 'nullable|string|max:255',
                'media.*.display_order' => 'nullable|integer|min:0',
                'ticker_text' => 'nullable|string|max:255',
                'ticker_link' => 'nullable|url',
                'ticker_start_time' => 'nullable|date',
                'ticker_end_time' => 'nullable|date',
            ]);

            /** @var \App\Models\User|null $admin */
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            DB::beginTransaction();

            

            $slug = $validated['slug'] ?? Str::slug($validated['title']);
            $isPublished = (bool) ($validated['is_published'] ?? false);

            $news = News::create([
                'title' => $validated['title'],
                'slug' => $slug,
                'summary' => $validated['summary'] ?? null,
                'content' => $validated['content'],
                'category_id' => $validated['category_id'],
                'language' => $validated['language'],
                'news_type' => $validated['news_type'] ?? 'article',
                'institute_id' => $validated['institute_id'] ?? null,
                'event_date' => $validated['event_date'] ?? null,
                'location' => $validated['location'] ?? null,
                'featured_image' => $validated['featured_image'] ?? null,
                'thumbnail_image' => $validated['thumbnail_image'] ?? null,
                'og_image' => $validated['og_image'] ?? null,
                'video_url' => $validated['video_url'] ?? null,
                'published_at' => $isPublished
                    ? ($validated['published_at'] ?? now())
                    : ($validated['published_at'] ?? null),
                'expires_at' => $validated['expires_at'] ?? null,
                'display_order' => $validated['display_order'] ?? 0,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'meta_keywords' => $validated['meta_keywords'] ?? null,
                'is_breaking' => (bool) ($validated['is_breaking'] ?? false),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'is_published' => $isPublished,
                'is_activated' => array_key_exists('is_activated', $validated) ? (bool) $validated['is_activated'] : true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            foreach ($validated['media'] ?? [] as $index => $mediaItem) {
                NewsMedia::create([
                    'news_id' => $news->id,
                    'media_type' => $mediaItem['media_type'],
                    'media_url' => $mediaItem['media_url'],
                    'thumbnail_url' => $mediaItem['thumbnail_url'] ?? null,
                    'caption' => $mediaItem['caption'] ?? null,
                    'alt_text' => $mediaItem['alt_text'] ?? null,
                    'display_order' => $mediaItem['display_order'] ?? $index,
                    'is_active' => true,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]);
            }

            if (!empty($validated['ticker_text'])) {
                NewsTicker::create([
                    'news_id' => $news->id,
                    'ticker_text' => $validated['ticker_text'],
                    'ticker_link' => $validated['ticker_link'] ?? null,
                    'start_time' => $validated['ticker_start_time'] ?? null,
                    'end_time' => $validated['ticker_end_time'] ?? null,
                    'is_active' => true,
                    'display_order' => 0,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'News saved successfully.',
                'data' => $news->fresh()->load(['media', 'tickers']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('saveNewsFromAdmin failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to save news.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getNewsForAdminById($id)
    {
        try {
            $news = News::with(['media', 'tickers'])->findOrFail($id);

            $ticker = $news->tickers->first();

            return response()->json([
                'success' => 1,
                'data' => [
                    'id' => $news->id,
                    'category_id' => $news->category_id,
                    'title' => $news->title,
                    'slug' => $news->slug,
                    'summary' => $news->summary,
                    'content' => $news->content,
                    'news_type' => $news->news_type,
                    'language' => $news->language,
                    'institute_id' => $news->institute_id,
                    'event_date' => $news->event_date,
                    'location' => $news->location,
                    'featured_image' => $news->featured_image,
                    'thumbnail_image' => $news->thumbnail_image,
                    'og_image' => $news->og_image,
                    'video_url' => $news->video_url,
                    'is_breaking' => (bool) $news->is_breaking,
                    'is_featured' => (bool) $news->is_featured,
                    'is_published' => (bool) $news->is_published,
                    'published_at' => $news->published_at?->format('Y-m-d\TH:i'),
                    'expires_at' => optional($news->expires_at)->format('Y-m-d'),
                    'display_order' => $news->display_order,
                    'meta_title' => $news->meta_title,
                    'meta_description' => $news->meta_description,
                    'meta_keywords' => $news->meta_keywords,
                    'ticker_text' => $ticker?->ticker_text,
                    'ticker_link' => $ticker?->ticker_link,
                    'ticker_start_time' => optional($ticker?->start_time)->format('Y-m-d\TH:i'),
                    'ticker_end_time' => optional($ticker?->end_time)->format('Y-m-d\TH:i'),
                    'media' => $news->media->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'media_type' => $item->media_type,
                            'media_url' => $item->media_url,
                            'thumbnail_url' => $item->thumbnail_url,
                            'caption' => $item->caption,
                            'alt_text' => $item->alt_text,
                            'display_order' => $item->display_order,
                        ];
                    })->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('getNewsForAdminById failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to load news details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateNewsFromAdmin(Request $request, $id)
    {
        try {
            $news = News::with(['media', 'tickers'])->findOrFail($id);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:news,slug,' . $news->id,
                'summary' => 'nullable|string',
                'content' => 'required|string',
                'category_id' => 'required|integer|exists:news_categories,id',
                'language' => 'required|in:en,ur',
                'news_type' => 'nullable|in:article,educational,event,video,gallery,announcement',
                'institute_id' => 'nullable|integer',
                'event_date' => 'nullable|date',
                'location' => 'nullable|string|max:255',
                'featured_image' => 'nullable|string|max:255',
                'thumbnail_image' => 'nullable|string|max:255',
                'og_image' => 'nullable|string|max:255',
                'video_url' => 'nullable|url',
                'published_at' => 'nullable|date',
                'expires_at' => 'nullable|date',
                'display_order' => 'nullable|integer|min:0',
                'meta_title' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:255',
                'meta_keywords' => 'nullable|string|max:255',
                'is_breaking' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'is_published' => 'nullable|boolean',
                'is_activated' => 'nullable|boolean',
                'media' => 'nullable|array',
                'media.*.media_type' => 'required_with:media|in:image,video',
                'media.*.media_url' => 'required_with:media|string|max:255',
                'media.*.thumbnail_url' => 'nullable|string|max:255',
                'media.*.caption' => 'nullable|string|max:255',
                'media.*.alt_text' => 'nullable|string|max:255',
                'media.*.display_order' => 'nullable|integer|min:0',
                'ticker_text' => 'nullable|string|max:255',
                'ticker_link' => 'nullable|url',
                'ticker_start_time' => 'nullable|date',
                'ticker_end_time' => 'nullable|date',
            ]);

            /** @var \App\Models\User|null $admin */
            $admin = Auth::guard('api')->user();

            if (!$admin) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            DB::beginTransaction();

            $slug = $validated['slug'] ?? Str::slug($validated['title']);
            $isPublished = (bool) ($validated['is_published'] ?? false);

            $oldContent = $news->content;

            $news->update([
                'title' => $validated['title'],
                'slug' => $slug,
                'summary' => $validated['summary'] ?? null,
                'content' => $validated['content'],
                'category_id' => $validated['category_id'],
                'language' => $validated['language'],
                'news_type' => $validated['news_type'] ?? 'article',
                'institute_id' => $validated['institute_id'] ?? null,
                'event_date' => $validated['event_date'] ?? null,
                'location' => $validated['location'] ?? null,
                'featured_image' => $validated['featured_image'] ?? null,
                'thumbnail_image' => $validated['thumbnail_image'] ?? null,
                'og_image' => $validated['og_image'] ?? null,
                'video_url' => $validated['video_url'] ?? null,
                'published_at' => $isPublished
                    ? ($validated['published_at'] ?? $news->published_at ?? now())
                    : ($validated['published_at'] ?? $news->published_at),
                'expires_at' => $validated['expires_at'] ?? null,
                'display_order' => $validated['display_order'] ?? 0,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'meta_keywords' => $validated['meta_keywords'] ?? null,
                'is_breaking' => (bool) ($validated['is_breaking'] ?? false),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'is_published' => $isPublished,
                'is_activated' => array_key_exists('is_activated', $validated)
                    ? (bool) $validated['is_activated']
                    : $news->is_activated,
                'updated_by' => $admin->id,
            ]);

            $this->deleteRemovedContentImages($oldContent, $validated['content']);

            $news->media()->delete();

            foreach ($validated['media'] ?? [] as $index => $mediaItem) {
                NewsMedia::create([
                    'news_id' => $news->id,
                    'media_type' => $mediaItem['media_type'],
                    'media_url' => $mediaItem['media_url'],
                    'thumbnail_url' => $mediaItem['thumbnail_url'] ?? null,
                    'caption' => $mediaItem['caption'] ?? null,
                    'alt_text' => $mediaItem['alt_text'] ?? null,
                    'display_order' => $mediaItem['display_order'] ?? $index,
                    'is_active' => true,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]);
            }

            $news->tickers()->delete();

            if (!empty($validated['ticker_text'])) {
                NewsTicker::create([
                    'news_id' => $news->id,
                    'ticker_text' => $validated['ticker_text'],
                    'ticker_link' => $validated['ticker_link'] ?? null,
                    'start_time' => $validated['ticker_start_time'] ?? null,
                    'end_time' => $validated['ticker_end_time'] ?? null,
                    'is_active' => true,
                    'display_order' => 0,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'News updated successfully.',
                'data' => $news->fresh()->load(['media', 'tickers']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('updateNewsFromAdmin failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update news.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    private function deleteRemovedContentImages(?string $oldContent, ?string $newContent): void
    {
        $oldKeys = $this->extractManagedContentImageKeys($oldContent);
        $newKeys = $this->extractManagedContentImageKeys($newContent);
        $removedKeys = array_diff($oldKeys, $newKeys);

        foreach ($removedKeys as $key) {
            $this->awsUploadService->deleteFileFromS3($key);
        }
    }

    private function extractManagedContentImageKeys(?string $content): array
    {
        if (!$content) {
            return [];
        }

        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches);
        $urls = $matches[1] ?? [];
        $keys = [];

        foreach ($urls as $url) {
            $key = $this->extractManagedContentImageKey($url);

            if ($key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function extractManagedContentImageKey(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = trim($value);
        $path = $normalized;

        if (filter_var($normalized, FILTER_VALIDATE_URL)) {
            $parsedPath = parse_url($normalized, PHP_URL_PATH);
            $path = is_string($parsedPath) ? ltrim($parsedPath, '/') : '';
        }

        if (!$path) {
            return null;
        }

        $cdnUrl = env('CDN_URL');

        if ($cdnUrl && str_starts_with($normalized, rtrim($cdnUrl, '/') . '/')) {
            $path = ltrim(substr($normalized, strlen(rtrim($cdnUrl, '/') . '/')), '/');
        }

        if (!str_starts_with($path, 'news/content/')) {
            return null;
        }

        return $path;
    }

    private function processEditorContentImages(string $content, int $newsId): string
    {
        $directory = 'news/content_images/' . date('Y') . '/' . date('m');
        $pattern = '/<img[^>]+src="data:image\/(png|jpg|jpeg|gif|webp);base64,([^"]+)"[^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) use ($directory, $newsId) {
            $extension = strtolower($matches[1]);
            $binary = base64_decode($matches[2]);

            if ($binary === false) {
                return $matches[0];
            }

            $fileName = 'news-' . $newsId . '-content-' . Str::random(10) . '.' . $extension;
            $key = $this->awsUploadService->uploadFileToS3($binary, $extension, $directory, $fileName);

            if (!$key) {
                return $matches[0];
            }

            $url = $this->awsUploadService->getS3Url($key);

            return str_replace(
                'src="data:image/' . $matches[1] . ';base64,' . $matches[2] . '"',
                'src="' . $url . '"',
                $matches[0]
            );
        }, $content) ?? $content;
    }

    private function uploadNewsAsset($file, string $directory): ?string
    {
        if (!$file || !$file->isValid()) {
            return null;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $fileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFileName = Str::slug($fileName ?: 'news-file') . '-' . Str::random(8) . '.' . $extension;
        $key = $this->awsUploadService->uploadFileToS3(
            $file,
            $extension,
            $directory . '/' . date('Y') . '/' . date('m'),
            $safeFileName
        );

        if (!$key) {
            return null;
        }

        return $this->awsUploadService->getS3Url($key);
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

    public function presignMediaUpload(Request $request)
    {
        Log::info('Entered NewsController@presignMediaUpload with request data: ', $request->all());
        try {
            $validated = $request->validate([
                'fileName' => ['required', 'string', 'max:255'],
                'contentType' => ['nullable', 'string', 'max:100'],
                'size' => ['nullable', 'integer', 'min:1', 'max:10485760'],
                'folder' => ['nullable', 'string', 'max:255'],
                'visibility' => ['nullable', 'in:public,private'],
                'expiresInMinutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            ]);

            $originalFileName = $validated['fileName'];
            $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));

            if (empty($extension)) {
                return response()->json([
                    'message' => 'File extension is required to generate a presigned URL.',
                ], 422);
            }

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'mp4'];

            if (!in_array($extension, $allowedExtensions, true)) {
                return response()->json([
                    'message' => 'Unsupported file type.',
                ], 422);
            }

            $folder = trim($validated['folder'] ?? 'news', '/');
            $expiresInMinutes = $validated['expiresInMinutes'] ?? 5;
            $baseName = pathinfo($originalFileName, PATHINFO_FILENAME);
            $safeBaseName = Str::slug($baseName ?: 'media-file');
            $generatedFileName = $safeBaseName . '-' . Str::random(8);

            $upload = $this->awsUploadService->getPresignedUploadUrl(
                $extension,
                $folder . '/' . date('Y') . '/' . date('m'),
                $generatedFileName,
                $expiresInMinutes,
                [
                    'ContentType' => $validated['contentType'] ?? null,
                ]
            );

            if (!$upload) {
                return response()->json([
                    'message' => 'Failed to generate presigned upload URL.',
                ], 500);
            }

            return response()->json([
                'key' => $upload['key'],
                'uploadUrl' => $upload['upload_url'],
                'fileUrl' => $upload['public_url'],
                'cdnUrl' => $upload['public_url'],
                'headers' => $upload['headers'] ?? [],
                'expiresAt' => $upload['expires_at'] ?? null,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('presignMediaUpload failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to generate presigned upload URL.',
                'error' => $e->getMessage(),
            ], 500);
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
