<?php

namespace App\Http\Services;

use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateAttachmentStorageService
{
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'msi',
        'sh', 'bash', 'ps1', 'vbs', 'js', 'mjs', 'cjs', 'html', 'htm', 'svg',
    ];

    private const OCTET_STREAM_ALLOWED_EXTENSIONS = [
        'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip',
    ];

    public function __construct(
        private readonly TeacherSubjectResourceService $resourceService
    ) {
    }

    /**
     * Validate a batch of uploaded attachments against count, size, extension, and MIME allowlists.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function validateBatch(array $files, int $existingCount, int $maxCount, ?int $maxFileKb = null): void
    {
        if (count($files) === 0) {
            return;
        }

        if (($existingCount + count($files)) > $maxCount) {
            $this->resourceService->abortJson(
                422,
                'attachment_limit_exceeded',
                "You can attach at most {$maxCount} file(s)."
            );
        }

        $maxKb = $maxFileKb ?? (int) config('teacher_portal.attachments.max_file_kb', 10240);
        $maxBytes = $maxKb * 1024;
        $allowedExtensions = array_map('strtolower', (array) config('teacher_portal.attachments.allowed_extensions', []));
        $allowedMimes = array_map('strtolower', (array) config('teacher_portal.attachments.allowed_mime_types', []));

        foreach ($files as $file) {
            if (!($file instanceof UploadedFile) || !$file->isValid()) {
                $this->resourceService->abortJson(
                    422,
                    'invalid_attachment_upload',
                    'One or more uploaded attachments are invalid or corrupted.'
                );
            }

            $size = (int) $file->getSize();
            if ($size <= 0 || $size > $maxBytes) {
                $this->resourceService->abortJson(
                    422,
                    'attachment_too_large',
                    "Each attachment must be greater than 0 bytes and at most {$maxKb} KB."
                );
            }

            $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ''));
            if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true) || !in_array($extension, $allowedExtensions, true)) {
                $this->resourceService->abortJson(
                    422,
                    'attachment_type_not_allowed',
                    'Unsupported file extension. Allowed formats: ' . implode(', ', $allowedExtensions) . '.'
                );
            }

            $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: ''));
            if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
                $this->resourceService->abortJson(
                    422,
                    'attachment_mime_not_allowed',
                    'The uploaded file MIME type is not permitted.'
                );
            }

            if ($mime === 'application/octet-stream' && !in_array($extension, self::OCTET_STREAM_ALLOWED_EXTENSIONS, true)) {
                $this->resourceService->abortJson(
                    422,
                    'attachment_mime_not_allowed',
                    'Generic binary stream MIME type is not permitted for this file extension.'
                );
            }

            if ($extension === 'pdf' && $mime !== 'application/pdf') {
                $this->resourceService->abortJson(422, 'attachment_mime_not_allowed', 'PDF file MIME mismatch.');
            }

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && !str_starts_with($mime, 'image/')) {
                $this->resourceService->abortJson(422, 'attachment_mime_not_allowed', 'Image file MIME mismatch.');
            }
        }
    }

    /**
     * Store a validated file privately on S3 (when configured and reachable) or private local disk.
     *
     * @return array{original_name:string, mime_type:string, extension:string, size_bytes:int, storage_disk:string, storage_key:string}
     */
    public function storePrivateFile(UploadedFile $file, string $directory): array
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
        $originalName = $this->sanitizeOriginalFilename((string) $file->getClientOriginalName(), $extension);
        $mimeType = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'));
        $sizeBytes = (int) $file->getSize();

        $safeDir = trim(str_replace(['..', '\\'], ['', '/'], $directory), '/');
        $key = $safeDir . '/' . Str::uuid()->toString() . '.' . $extension;

        $disk = $this->resolveWritableDisk();
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            $this->resourceService->abortJson(500, 'attachment_read_failed', 'Unable to read uploaded attachment.');
        }

        if ($disk === 's3') {
            try {
                Storage::disk('s3')->put($key, $contents, ['visibility' => 'private']);

                return [
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'size_bytes' => $sizeBytes,
                    'storage_disk' => 's3',
                    'storage_key' => $key,
                ];
            } catch (\Throwable) {
                // Fall back to private local disk if S3 is unavailable locally.
            }
        }

        Storage::disk('local')->put($key, $contents);

        return [
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $sizeBytes,
            'storage_disk' => 'local',
            'storage_key' => $key,
        ];
    }

    public function deleteStoredFile(?string $disk, ?string $key): void
    {
        if (!$disk || !$key || !in_array($disk, ['s3', 'local'], true)) {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($key)) {
                Storage::disk($disk)->delete($key);
            }
        } catch (\Throwable) {
            // Best-effort cleanup; never fail business transition because an orphan file cleanup failed.
        }
    }

    public function streamStoredFile(
        string $disk,
        string $key,
        string $originalName,
        string $mimeType,
        bool $inlineIfSafe = false
    ): Response|StreamedResponse {
        if (!in_array($disk, ['s3', 'local'], true)) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Attachment storage location not found.');
        }

        try {
            if (!Storage::disk($disk)->exists($key)) {
                $this->resourceService->abortJson(404, 'attachment_not_found', 'Attachment file could not be found.');
            }
            $bytes = Storage::disk($disk)->get($key);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Attachment file could not be read.');
        }

        if ($bytes === null) {
            $this->resourceService->abortJson(404, 'attachment_not_found', 'Attachment file is empty or unavailable.');
        }

        $safeName = str_replace(['"', "\r", "\n"], ['', '', ''], $originalName ?: 'attachment');
        $isSafePreviewMime = $inlineIfSafe && (
            $mimeType === 'application/pdf' ||
            in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
        );
        $disposition = $isSafePreviewMime ? 'inline' : 'attachment';

        return response($bytes, 200, [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'Content-Disposition' => "{$disposition}; filename=\"{$safeName}\"",
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    private function resolveWritableDisk(): string
    {
        $configured = strtolower((string) config('filesystems.default', 'local'));
        if ($configured === 's3' && !app()->runningUnitTests() && !empty(config('filesystems.disks.s3.bucket'))) {
            return 's3';
        }

        return 'local';
    }

    private function sanitizeOriginalFilename(string $rawName, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $rawName));
        $cleaned = preg_replace('/[^\w\s.\-()]/u', '_', $base) ?: ('attachment.' . $extension);

        return Str::limit(trim($cleaned), 240, '');
    }
}
