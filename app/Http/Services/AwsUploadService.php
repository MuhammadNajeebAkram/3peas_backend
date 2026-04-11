<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class AwsUploadService
{
    /**
     * Build S3 key prefix based on environment.
     *
     * @return string
     */
    protected function getEnvPrefix(): string
    {
        $configuredPrefix = env('AWS_UPLOAD_PREFIX');

        if (!empty($configuredPrefix)) {
            return trim($configuredPrefix, '/');
        }

        return app()->environment('production') ? 'prod' : 'dev';
    }
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
            
            // Build the full S3 key with env prefix
            $envPrefix = $this->getEnvPrefix();
            $s3Key = trim($envPrefix, '/')
                . '/'
                . ltrim(rtrim($s3Directory, '/'), '/')
                . '/'
                . $fileName;

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
     * Generate a presigned S3 upload URL for direct client uploads.
     *
     * @param string $fileType - File extension
     * @param string $s3Directory - Directory path in S3
     * @param string|null $fileName - Optional custom filename
     * @param int $expiresInMinutes - URL expiration in minutes
     * @param array $options - Additional S3 upload options
     * @return array|null - Returns upload metadata on success, null on failure
     */
    public function getPresignedUploadUrl($fileType, $s3Directory, $fileName = null, $expiresInMinutes = 5, array $options = [])
    {
        try {
            if (!$fileName) {
                $fileName = time() . '_' . uniqid() . '.' . $fileType;
            }

            if (!str_ends_with($fileName, '.' . $fileType)) {
                $fileName .= '.' . $fileType;
            }

            $envPrefix = $this->getEnvPrefix();
            $s3Key = trim($envPrefix, '/')
                . '/'
                . ltrim(rtrim($s3Directory, '/'), '/')
                . '/'
                . $fileName;

            $uploadOptions = array_merge([
                'ContentType' => $options['ContentType'] ?? $this->guessContentType($fileType),
            ], $options);

            ['url' => $url, 'headers' => $headers] = Storage::disk('s3')->temporaryUploadUrl(
                $s3Key,
                now()->addMinutes($expiresInMinutes),
                $uploadOptions,
            );

            return [
                'key' => $s3Key,
                'upload_url' => $url,
                'headers' => $headers,
                'public_url' => $this->getS3Url($s3Key),
                'expires_at' => now()->addMinutes($expiresInMinutes)->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Error generating presigned upload URL', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'directory' => $s3Directory,
                'fileName' => $fileName,
            ]);

            return null;
        }
    }

    /**
     * Delete file from S3 by key.
     *
     * @param string|null $s3Key
     * @return bool
     */
    public function deleteFileFromS3($s3Key)
    {
        if (!$s3Key) {
            return false;
        }

        try {
            if (!Storage::disk('s3')->exists($s3Key)) {
                return false;
            }

            return Storage::disk('s3')->delete($s3Key);
        } catch (\Exception $e) {
            Log::error('Error deleting file from S3', [
                'error' => $e->getMessage(),
                'key' => $s3Key,
            ]);

            return false;
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

    /**
     * Guess content type from file extension for presigned uploads.
     *
     * @param string $fileType
     * @return string
     */
    protected function guessContentType($fileType)
    {
        return match (strtolower($fileType)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }
}

