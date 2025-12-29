<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class AwsUploadService
{
    /**
     * Upload file to S3
     * 
     * @param mixed $file - Can be UploadedFile object or string (file content/data)
     * @param string $fileType - File extension
     * @param string $s3Directory - Directory path in S3
     * @param string|null $fileName - Optional custom filename
     * @return string|null - Returns S3 key on success, null on failure
     */
    public function uploadFileToS3($file, $fileType, $s3Directory, $fileName = null)
    {
        try {
            // Generate a unique filename if not provided
            if (!$fileName) {
                $fileName = time() . '_' . uniqid() . '.' . $fileType;
            }
            
            // Ensure the filename has the correct extension
            if (!str_ends_with($fileName, '.' . $fileType)) {
                $fileName .= '.' . $fileType;
            }
            
            // Build the full S3 key
            $s3Key = rtrim($s3Directory, '/') . '/' . $fileName;

            // Check if $file is an UploadedFile object
            if ($file instanceof UploadedFile) {
                // Handle UploadedFile - read its contents
                $fileContent = file_get_contents($file->getRealPath());
            } elseif (is_string($file)) {
                // $file is already file content (string/binary data)
                $fileContent = $file;
            } else {
                Log::error('Invalid file type for upload', [
                    'type' => gettype($file),
                    'class' => is_object($file) ? get_class($file) : 'N/A'
                ]);
                return null;
            }

            // Upload to S3 with file content (not file object)
            $uploaded = Storage::disk('s3')->put($s3Key, $fileContent);

            if ($uploaded) {
                Log::info('File uploaded to S3 successfully', [
                    's3_key' => $s3Key,
                    'size' => strlen($fileContent)
                ]);
                return $s3Key;
            }

            Log::warning('Failed to upload file to S3', ['s3_key' => $s3Key]);
            return null;

        } catch (\Exception $e) {
            Log::error('Error uploading file to S3', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'directory' => $s3Directory,
                'fileName' => $fileName
            ]);
            return null;
        }
    }

    /**
     * Get public URL for S3 file (with CDN support)
     * 
     * @param string $s3Key - S3 key/path
     * @return string - Public URL
     */
    public function getS3Url($s3Key)
    {
        $cdnUrl = env('CDN_URL');
        
        if ($cdnUrl) {
            return rtrim($cdnUrl, '/') . '/' . ltrim($s3Key, '/');
        }
        
        return Storage::disk('s3')->url($s3Key);
    }
}