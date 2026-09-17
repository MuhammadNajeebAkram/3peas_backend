<?php

namespace App\Http\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class AiQuestionSourceService
{
    /** Inspect everything before persisting. ZIP entries are read, never extracted to disk. */
    public function prepare(?string $text, array $uploads): array
    {
        $items = [];
        if (filled($text)) {
            $items[] = $this->inspect('source-text.txt', $text);
        }
        foreach ($uploads as $upload) {
            if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                $this->invalid('A source upload failed.');
            }
            if ($upload->getSize() > config('ai_questions.max_file_bytes')) {
                $this->invalid('Each upload must be at most 8 MB.');
            }
            if (strtolower($upload->getClientOriginalExtension()) === 'zip') {
                $items = array_merge($items, $this->readZip($upload));
            } else {
                $items[] = $this->inspect($upload->getClientOriginalName(), file_get_contents($upload->getRealPath()));
            }
            $this->checkLimits($items);
        }
        $this->checkLimits($items);

        return $items;
    }

    private function readZip(UploadedFile $upload): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->invalid('ZIP support is unavailable. Upload the individual files instead.');
        }
        $zip = new ZipArchive;
        if ($zip->open($upload->getRealPath(), ZipArchive::RDONLY) !== true) {
            $this->invalid('The ZIP file could not be read.');
        }
        try {
            if ($zip->numFiles > 30) {
                $this->invalid('The ZIP contains too many entries.');
            }
            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', $stat['name']);
                if (str_starts_with($name, '/') || preg_match('/(^|\/)\.\.(\/|$)|:|[\x00-\x1f]/', $name)) {
                    $this->invalid('ZIP entries must use safe relative filenames.');
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000 || ($stat['encryption_method'] ?? 0) !== 0) {
                    $this->invalid('Encrypted files and symbolic links are not supported in ZIP files.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                    continue;
                }
                $total += $stat['size'];
                if ($stat['size'] > config('ai_questions.max_file_bytes') || $total > config('ai_questions.max_total_bytes')
                    || $stat['size'] / max(1, $stat['comp_size']) > 200) {
                    $this->invalid('The ZIP exceeds the expanded file-size or compression limits.');
                }
                if (count($entries) >= config('ai_questions.max_files')) {
                    $this->invalid('Use at most 10 source files.');
                }
                $entries[] = ['index' => $i, 'name' => $name];
            }
            usort($entries, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            $items = [];
            foreach ($entries as $entry) {
                $bytes = $zip->getFromIndex($entry['index'], config('ai_questions.max_file_bytes') + 1);
                if ($bytes === false) {
                    $this->invalid('A ZIP entry could not be read.');
                }
                $items[] = $this->inspect($entry['name'], $bytes);
            }
            if ($items === []) {
                $this->invalid('The ZIP has no supported source files.');
            }

            return $items;
        } finally {
            $zip->close();
        }
    }

    private function inspect(string $name, string $bytes): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, ['txt', 'png', 'jpg', 'jpeg', 'webp', 'pdf'], true)) {
            $this->invalid('Sources must be TXT, PNG, JPEG, WebP or PDF. Nested ZIP files are not supported.');
        }
        if ($bytes === '' || strlen($bytes) > config('ai_questions.max_file_bytes')) {
            $this->invalid('Source files must be nonempty and at most 8 MB each.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($extension === 'txt') {
            if (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0") || mb_strlen($bytes) > config('ai_questions.max_text_chars') || trim($bytes) === '') {
                $this->invalid('Text sources must be nonempty UTF-8 text, at most 50,000 characters.');
            }
            $mime = 'text/plain';
        } elseif ($extension === 'pdf') {
            if ($mime !== 'application/pdf' || ! str_starts_with($bytes, '%PDF-')) {
                $this->invalid('A PDF source does not contain a valid PDF header.');
            }
        } else {
            $size = @getimagesizefromstring($bytes);
            if (! $size || ! in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
                || config('ai_questions.max_image_pixels') < $size[0] * $size[1]) {
                $this->invalid('Images must be PNG, JPEG or WebP with at most 8 million pixels.');
            }
            $mime = $size['mime'];
        }

        return ['name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 200),
            'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
    }

    private function checkLimits(array $items): void
    {
        if (count($items) > config('ai_questions.max_files') || array_sum(array_column($items, 'size')) > config('ai_questions.max_total_bytes')) {
            $this->invalid('Use at most 10 source files and 12 MB total expanded content.');
        }
        if (array_sum(array_map(fn ($item) => $item['mime'] === 'text/plain' ? mb_strlen($item['bytes']) : 0, $items)) > config('ai_questions.max_text_chars')) {
            $this->invalid('Combined text sources must be at most 50,000 characters.');
        }
    }

    public function store(array $items): array
    {
        $disk = Storage::disk(config('ai_questions.source_disk'));
        $prefix = 'ai-question-sources/'.Str::uuid();
        $sources = [];
        try {
            foreach ($items as $index => $item) {
                $id = 'source-'.($index + 1);
                $path = $prefix.'/'.$id;
                if (! $disk->put($path, $item['bytes'])) {
                    throw new \RuntimeException('Source storage failed.');
                }
                unset($item['bytes']);
                $sources[] = $item + ['id' => $id, 'path' => $path, 'disk' => config('ai_questions.source_disk')];
            }
        } catch (\Throwable $exception) {
            $disk->deleteDirectory($prefix);
            throw $exception;
        }

        return $sources;
    }

    public function input(array $sources): array
    {
        $text = [];
        $attachments = [];
        foreach ($sources as $source) {
            $bytes = Storage::disk($source['disk'])->get($source['path']);
            if ($source['mime'] === 'text/plain') {
                $text[] = ['source_id' => $source['id'], 'text' => $bytes];
            } else {
                $attachments[] = ['source_id' => $source['id'], 'name' => $source['name'],
                    'mime' => $source['mime'], 'data' => base64_encode($bytes)];
            }
        }

        return ['texts' => $text, 'attachments' => $attachments];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['files' => $message]);
    }
}
