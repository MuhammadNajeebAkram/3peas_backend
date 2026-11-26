<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Exceptions\ReceiptExtractionException;

interface ReceiptExtractionProviderInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * Send untrusted OCR text to the provider and return decoded raw extraction JSON array.
     *
     * @param  array{timeout?:int,max_output_tokens?:int,instructions:string,schema:array}  $options
     * @throws ReceiptExtractionException
     */
    public function extract(string $ocrText, string $model, string $apiKey, array $options): array;

    /**
     * Test provider connectivity and model availability without processing real receipts.
     *
     * @param  array{timeout?:int}  $options
     * @return array{ok:bool,message:string,latency_ms:int}
     * @throws ReceiptExtractionException
     */
    public function testConnection(string $model, string $apiKey, array $options = []): array;
}
