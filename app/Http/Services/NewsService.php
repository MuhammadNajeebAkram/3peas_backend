<?php
namespace App\Http\Services;

use App\Models\News;
use Illuminate\Support\Facades\Log;

class NewsService
{
    public function __construct()
    {
        // Initialization code can go here
    }

    public function getLatestNewsPaginatedTitles($perPage = 3)
    {
        try{

        
        $news = News::where('activate', 1)
        ->where('published_at', '<=', now())        
        ->select('id', 'title', 'slug', 'description', 'published_at', 'has_attachment', 'language')
        ->with('attachments')
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);

        return $news;
        }
        catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getLatestNewsPaginatedTitles', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getLatestNewsPaginatedTitles', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
           
        
    }
    public function getNewsBySlug($slug)
    {
        try{
        $newsItem = News::where('slug', $slug)
            ->with('attachments')
            ->first();

        return $newsItem;
        }
        catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getNewsBySlug', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getNewsBySlug', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
    }  
    
    public function getAllSlugs(){
        try{

            $slugs = News::query()
        ->select('slug')
        ->where('activate', 1)        
        ->get();

        $formattedSlugs = $slugs->map(function ($news) {
            return ['slug' => $news->slug];
        });

        return response()->json([
            'success' => 1,
            'data' => $formattedSlugs,
        ]);
        }catch (\Exception $e) {
        Log::error('Error fetching all news slugs for sitemap:', ['error' => $e->getMessage()]);
        return response()->json(['success' => 0, 'message' => $e->getMessage()], 500);
    }
        
    }
}