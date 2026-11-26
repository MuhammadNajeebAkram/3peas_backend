<?php

namespace Tests\Unit;

use App\Exceptions\ReceiptExtractionException;
use App\Http\Services\PaymentReceiptOcrService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PaymentReceiptOcrServiceTest extends TestCase
{
    public function test_normalize_text_strips_control_chars_collapses_whitespace_and_truncates(): void
    {
        config(['services.receipt_ocr.max_text_chars' => 500]);

        $service = new class extends PaymentReceiptOcrService
        {
            protected function extractFromImage(string $filePath): string
            {
                return "Meezan\x00 Bank\t\tTransfer\r\n\r\n\r\n".str_repeat('A', 600);
            }
        };

        $file = UploadedFile::fake()->createWithContent('receipt.jpg', "\xFF\xD8\xFF\xD9");
        $text = $service->extractText($file, 'image/jpeg');

        $this->assertStringNotContainsString("\x00", $text);
        $this->assertStringStartsWith("Meezan Bank Transfer\n\n", $text);
        $this->assertSame(500, mb_strlen($text));
    }

    public function test_empty_ocr_text_throws_safe_422_exception(): void
    {
        $service = new class extends PaymentReceiptOcrService
        {
            protected function extractFromImage(string $filePath): string
            {
                return "  \n\t ";
            }
        };

        $file = UploadedFile::fake()->createWithContent('receipt.jpg', "\xFF\xD8\xFF\xD9");

        try {
            $service->extractText($file, 'image/jpeg');
            $this->fail('Expected OCR_EMPTY_TEXT exception.');
        } catch (ReceiptExtractionException $e) {
            $this->assertSame('OCR_EMPTY_TEXT', $e->errorCode());
            $this->assertSame(422, $e->statusCode());
        }
    }

    public function test_missing_tesseract_binary_throws_safe_503_exception(): void
    {
        config(['services.receipt_ocr.tesseract_binary' => 'nonexistent_tesseract_binary_xyz']);

        $service = new PaymentReceiptOcrService;
        $file = UploadedFile::fake()->createWithContent('receipt.jpg', "\xFF\xD8\xFF\xD9");

        try {
            $service->extractText($file, 'image/jpeg');
            $this->fail('Expected OCR_UNAVAILABLE or OCR_FAILED exception.');
        } catch (ReceiptExtractionException $e) {
            $this->assertContains($e->errorCode(), ['OCR_UNAVAILABLE', 'OCR_FAILED']);
            $this->assertSame(503, $e->statusCode());
            $this->assertStringNotContainsString('nonexistent_tesseract_binary_xyz', $e->getMessage());
        }
    }
}
