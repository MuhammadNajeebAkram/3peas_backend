<?php

namespace App\Http\Services;

use App\Exceptions\ReceiptExtractionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PaymentReceiptOcrService
{
    public function extractText(UploadedFile $file, string $detectedMime): string
    {
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! is_readable($realPath)) {
            throw new ReceiptExtractionException(
                'FILE_UNREADABLE',
                'The uploaded receipt could not be read. Please upload the file again or enter details manually.',
                422
            );
        }

        $rawText = $detectedMime === 'application/pdf'
            ? $this->extractFromPdf($realPath)
            : $this->extractFromImage($realPath);

        $normalized = $this->normalizeText($rawText);

        if (mb_strlen($normalized) < 5) {
            throw new ReceiptExtractionException(
                'OCR_EMPTY_TEXT',
                'No readable text was found on the receipt. Please enter the payment details manually.',
                422
            );
        }

        return $normalized;
    }

    protected function extractFromImage(string $filePath): string
    {
        $binary = (string) config('services.receipt_ocr.tesseract_binary', 'tesseract');
        $lang = (string) config('services.receipt_ocr.tesseract_lang', 'eng');

        return $this->runTesseract($binary, $filePath, $lang);
    }

    protected function extractFromPdf(string $filePath): string
    {
        $maxPages = max(1, min(5, (int) config('services.receipt_ocr.max_pdf_pages', 2)));
        $pdftotext = (string) config('services.receipt_ocr.pdftotext_binary', 'pdftotext');

        $embeddedText = $this->tryCommand([
            $pdftotext,
            '-f', '1',
            '-l', (string) $maxPages,
            '-nopgbrk',
            $filePath,
            '-',
        ]);

        if ($embeddedText !== null && mb_strlen($this->normalizeText($embeddedText)) >= 20) {
            return $embeddedText;
        }

        return $this->renderPdfAndRunOcr($filePath, $maxPages);
    }

    protected function renderPdfAndRunOcr(string $filePath, int $maxPages): string
    {
        $pdftoppm = (string) config('services.receipt_ocr.pdftoppm_binary', 'pdftoppm');
        $tesseract = (string) config('services.receipt_ocr.tesseract_binary', 'tesseract');
        $lang = (string) config('services.receipt_ocr.tesseract_lang', 'eng');

        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'tst-receipt-ocr-'.Str::uuid();

        if (! @mkdir($tempDir, 0700, true) && ! is_dir($tempDir)) {
            throw new ReceiptExtractionException(
                'OCR_FAILED',
                'We could not process this PDF receipt right now. Please enter the payment details manually.',
                503
            );
        }

        try {
            $prefix = $tempDir.DIRECTORY_SEPARATOR.'page';
            $renderResult = $this->tryCommand([
                $pdftoppm,
                '-png',
                '-r', '200',
                '-f', '1',
                '-l', (string) $maxPages,
                $filePath,
                $prefix,
            ]);

            if ($renderResult === null) {
                throw new ReceiptExtractionException(
                    'OCR_UNAVAILABLE',
                    'PDF receipt scanning is not available right now. Please enter the payment details manually.',
                    503
                );
            }

            $pages = glob($prefix.'*.png') ?: [];
            sort($pages);

            if ($pages === []) {
                throw new ReceiptExtractionException(
                    'OCR_EMPTY_TEXT',
                    'No readable pages were found in the uploaded PDF. Please enter the payment details manually.',
                    422
                );
            }

            $chunks = [];
            foreach ($pages as $pageImage) {
                $chunks[] = $this->runTesseract($tesseract, $pageImage, $lang);
            }

            return implode("\n", $chunks);
        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    protected function runTesseract(string $binary, string $imagePath, string $lang): string
    {
        $timeout = max(5, min(60, (int) config('services.receipt_ocr.timeout', 20)));
        $process = new Process([
            $binary,
            $imagePath,
            'stdout',
            '-l',
            $lang,
            '--psm',
            '6',
        ]);
        $process->setTimeout($timeout);

        try {
            $process->mustRun();

            return $process->getOutput();
        } catch (ProcessTimedOutException) {
            throw new ReceiptExtractionException(
                'OCR_TIMEOUT',
                'Reading the receipt took too long. Please try again or enter the details manually.',
                503
            );
        } catch (ProcessFailedException|\Throwable) {
            $exitCode = $process->getExitCode();
            $isMissingBinary = $exitCode === 127 || $exitCode === 9009 || $exitCode === 1 && stripos($process->getErrorOutput(), 'not recognized') !== false;

            throw new ReceiptExtractionException(
                $isMissingBinary ? 'OCR_UNAVAILABLE' : 'OCR_FAILED',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        }
    }

    protected function tryCommand(array $command): ?string
    {
        $timeout = max(5, min(60, (int) config('services.receipt_ocr.timeout', 20)));
        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeText(string $text): string
    {
        // Strip non-printable control characters while preserving newlines and tabs
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $cleaned = preg_replace("/\r\n|\r/", "\n", $cleaned) ?? '';
        $cleaned = preg_replace('/[ \t]+/', ' ', $cleaned) ?? '';
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned) ?? '';
        $cleaned = trim($cleaned);

        $maxChars = max(500, min(10000, (int) config('services.receipt_ocr.max_text_chars', 4000)));
        if (mb_strlen($cleaned) > $maxChars) {
            $cleaned = mb_substr($cleaned, 0, $maxChars);
        }

        return $cleaned;
    }

    private function cleanupTempDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
