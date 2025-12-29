<?php
namespace App\Http\Services;

use App\Models\News;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Intervention\Image\Laravel\Facades\Image;

class NewsService
{
    protected $awsUploadService;
    public function __construct()
    {
        // Initialization code can go here
        $this->awsUploadService = new AwsUploadService();
    }

    public function saveNews($validatedData)
{
    set_time_limit(300);
    DB::beginTransaction();
    try {
        $processedContent = $this->processContentImages($validatedData['news_content']);

        $featuredImage = $validatedData['featured_image'] ?? null;
        $breakingNewsImage = $validatedData['breaking_news_image'] ?? null;
        
        $featuredImagePath = null;
        $breakingNewsImagePath = null;
        $thumbnailImagePath = null;
        $ogImagePath = null;

        // Create news record first (without image paths)
        $news = News::create([
            'title' => $validatedData['news_title'],
            'content' => $processedContent,
            'language' => $validatedData['language'],
            'category_id' => $validatedData['category_id'],
            'has_attachment' => $validatedData['have_file'] ?? 0,
            'slug' => $validatedData['slug'],
            'description' => $validatedData['description'] ?? null,
            'meta_description' => $validatedData['meta_description'] ?? null,
            'activate' => 1,
            'published_at' => $validatedData['published_at'] ?? now(),
            'priority_score' => $validatedData['priority_score'] ?? 30,
            'is_breaking_news' => $validatedData['is_breaking_news'] ?? false,
            'expires_at' => $validatedData['expires_at'] ?? null,
            'status' => $validatedData['status'] ?? 'draft',
            'meta_title' => $validatedData['meta_title'] ?? null,
            'url_link' => $validatedData['url_link'] ?? null,
            'ticker_text' => $validatedData['ticker'] ?? null,
        ]);

        // Upload breaking news image FIRST
        if ($breakingNewsImage && $breakingNewsImage->isValid()) {
            Log::info('Uploading breaking news image for news ID: ' . $news->id);
            $fileName = 'news-' . $news->id . '-breaking-news-' . substr(md5(uniqid()), 0, 6) . '.' . $breakingNewsImage->getClientOriginalExtension();
            $breakingNewsImagePath = $this->awsUploadService->uploadFileToS3(
                $breakingNewsImage,
                $breakingNewsImage->getClientOriginalExtension(),
                'news/breaking_news_images/' . date('Y') . '/' . date('m'),
                $fileName
            );
            Log::info('Breaking news image uploaded. S3 Path: ' . $breakingNewsImagePath);
        }

        // Upload featured image and generate thumbnail/OG
        if ($featuredImage && $featuredImage->isValid()) {
            $fileName = 'news-' . $news->id . '-featured-' . substr(md5(uniqid()), 0, 6) . '.' . $featuredImage->getClientOriginalExtension();
            $featuredImagePath = $this->awsUploadService->uploadFileToS3(
                $featuredImage,
                $featuredImage->getClientOriginalExtension(),
                'news/featured_images/' . date('Y') . '/' . date('m'),
                $fileName
            );

            // Generate thumbnail
            try {
                $thumbnailImage = Image::read($featuredImage->getRealPath())
                    ->cover(400, 300)
                    ->toWebp(80);
                
                $thumbFileName = 'news-' . $news->id . '-thumbnail-' . substr(md5(uniqid()), 0, 6) . '.webp';
                $thumbnailImagePath = $this->awsUploadService->uploadFileToS3(
                    $thumbnailImage->toString(),
                    'webp',
                    'news/thumbnail_images/' . date('Y') . '/' . date('m'),
                    $thumbFileName
                );
            } catch (\Exception $e) {
                Log::warning('Failed to generate thumbnail', ['error' => $e->getMessage()]);
            }

            // Generate OG image
            try {
                $ogImage = Image::read($featuredImage->getRealPath())
                    ->cover(1200, 630)
                    ->toWebp(85);
                
                $ogFileName = 'news-' . $news->id . '-og-' . substr(md5(uniqid()), 0, 6) . '.webp';
                $ogImagePath = $this->awsUploadService->uploadFileToS3(
                    $ogImage->toString(),
                    'webp',
                    'news/og_images/' . date('Y') . '/' . date('m'),
                    $ogFileName
                );
            } catch (\Exception $e) {
                Log::warning('Failed to generate OG image', ['error' => $e->getMessage()]);
            }
        }

         $updateResult = $news->update([
            'featured_image' => $featuredImagePath,
            'breaking_news_image' => $breakingNewsImagePath,
            'thumbnail_image' => $thumbnailImagePath,
            'og_image' => $ogImagePath,
        ]);

        Log::info('Update result', [
            'success' => $updateResult,
            'news_id' => $news->id
        ]);

        // Refresh the model to get updated data
        $news = $news->fresh();

        // Handle file attachments
        if (isset($validatedData['files']) && is_array($validatedData['files'])) {
            $year = date('Y');
            $month = date('m');
            
            foreach ($validatedData['files'] as $file) {
                if ($file->isValid()) {
                    $mimeType = $file->getClientMimeType();
                    $extension = $file->getClientOriginalExtension();
                    
                    $s3Directory = 'news/attachments/' . $year . '/' . $month;
                    
                    $filename = time() . '_' . uniqid() . '.' . $extension;
                    $s3Key = $this->awsUploadService->uploadFileToS3(
                        $file,
                        $extension,
                        $s3Directory,
                        $filename
                    );

                    if ($s3Key) {
                        $news->attachments()->create([
                            'file_name' => $filename,
                            'path' => $s3Key,
                            'file_type' => $mimeType,
                            'size_kb' => round($file->getSize() / 1024, 2),
                        ]);
                    }
                }
            }
        }

        DB::commit();

        return response()->json([
            'success' => 1,
            'message' => 'News saved successfully',
            'data' => $news->fresh()->load('attachments'),
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        Log::error('Database error in saveNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('General exception in saveNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
}

    public function getLatestNewsPaginatedTitles($request, $perPage = 3)
    {
        try{

        
        $news = News::where('activate', 1)
        ->where('published_at', '<=', now())        
        ->select(['id', 'title', 'slug', 'description', 'published_at', 'has_attachment', 'language'])
        ->with('attachments')
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => 1,
            'data' => $news,
        ]);
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

    public function getBreakingNews(){
        try{
        $breakingNews = News::where('activate', 1)
        ->where('is_breaking_news', 1)
        ->where('published_at', '<=', now())
        ->where('expires_at', '>', now())        
        ->where('status', 'published')
        ->select(['id', 'title', 'slug', 'description', 'published_at', 'has_attachment', 'language', 'featured_image', 'breaking_news_image'])
        ->with('attachments')
            ->orderBy('published_at', 'desc')
            ->get();

        return response()->json([
            'success' => 1,
            'data' => $breakingNews,
        ]);
        }
        catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getBreakingNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getBreakingNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
}

public function getFeaturedNews($limit = 5){
        try{
        $featuredNews = News::where('activate', 1)
        ->where('published_at', '<=', now())        
        ->where('status', 'published')
        ->where('is_breaking_news', 0)
        ->select(['id', 'title', 'slug', 'description', 'published_at', 'has_attachment', 'language', 'featured_image', 'thumbnail_image'])
        ->with('attachments')
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => 1,
            'data' => $featuredNews,
        ]);
        }
        catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getFeaturedNews', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getFeaturedNews', [
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


    private function processContentImages($content)
{
    if (empty($content)) {
        return $content;
    }
    $year = date('Y');
    $month = date('m');

    $s3Directory = 'news/content_images/' . $year . '/' . $month;

    // Pattern to match base64 images in img tags
    $pattern = '/<img[^>]+src="data:image\/(png|jpg|jpeg|gif|webp);base64,([^"]+)"[^>]*>/i';

    $processedContent = preg_replace_callback($pattern, function ($matches) use ($s3Directory) {
        try {
            $imageType = $matches[1];
            $base64Data = $matches[2];
            
            // Decode base64 image
            $imageData = base64_decode($base64Data);
            
            if ($imageData === false) {
                Log::warning('Failed to decode base64 image in content');
                return $matches[0]; // Return original if decode fails
            }

            $s3Key = $this->awsUploadService->uploadFileToS3($imageData, $imageType, $s3Directory);

            // Generate unique filename
           /* $filename = time() . '_' . uniqid() . '.' . $imageType;
            $s3Key = $s3Directory . '/' . $filename;

            // Upload to S3
            $uploaded = Storage::disk('s3')->put(
                $s3Key,
                $imageData,
                'public'
            );*/

            $cdnURL = env('CDN_URL');
            if ($s3Key){
                 $s3Url = rtrim($cdnURL, '/') . '/' . ltrim($s3Key, '/');
                    return str_replace(
                    'src="data:image/' . $imageType . ';base64,' . $base64Data . '"',
                    'src="' . $s3Url . '"',
                    $matches[0]
                );

            }
            else{
                Log::warning('Failed to upload content image to S3', ['fileDirectory' => $s3Directory]);
                return $matches[0]; // Return original if upload fails
            }

            

        } catch (\Exception $e) {
            Log::error('Error processing content image', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $matches[0]; // Return original on error
        }
    }, $content);

    // Also handle regular image URLs (http/https) if you want to download and re-upload them
    $processedContent = $this->processExternalImages($processedContent, $s3Directory);

    return $processedContent;
}

/**
 * Process external image URLs and upload to S3 (optional)
 */
private function processExternalImages($content, $s3Directory)
{
    // Pattern to match external image URLs
    $pattern = '/<img[^>]+src="(https?:\/\/[^"]+\.(png|jpg|jpeg|gif|webp))"[^>]*>/i';

    $processedContent = preg_replace_callback($pattern, function ($matches) use ($s3Directory) {
        try {
            $imageUrl = $matches[1];
            $extension = $matches[2];

            // Skip if already on your S3
            if (strpos($imageUrl, Storage::disk('s3')->url('')) !== false) {
                return $matches[0];
            }

            // Download the image
            $imageData = @file_get_contents($imageUrl);
            
            if ($imageData === false) {
                Log::warning('Failed to download external image', ['url' => $imageUrl]);
                return $matches[0];
            }

            // Generate unique filename
            $filename = time() . '_' . uniqid() . '.' . $extension;
            $s3Key = $s3Directory . '/' . $filename;

            // Upload to S3
            $uploaded = Storage::disk('s3')->put(
                $s3Key,
                $imageData,
                'public'
            );

            if ($uploaded) {
                // Get the S3 URL
                $s3Url = Storage::disk('s3')->url($s3Key);
                
                // Replace the external URL with S3 URL
                return str_replace(
                    'src="' . $imageUrl . '"',
                    'src="' . $s3Url . '"',
                    $matches[0]
                );
            } else {
                Log::warning('Failed to upload external image to S3', ['url' => $imageUrl]);
                return $matches[0];
            }

        } catch (\Exception $e) {
            Log::error('Error processing external image', [
                'url' => $matches[1],
                'error' => $e->getMessage(),
            ]);
            return $matches[0];
        }
    }, $content);

    return $processedContent;
}
}