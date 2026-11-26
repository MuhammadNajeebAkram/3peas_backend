<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Exceptions\ReceiptExtractionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QwenReceiptExtractionProvider implements ReceiptExtractionProviderInterface
{
    public function key(): string
    {
        return 'qwen';
    }

    public function label(): string
    {
        return 'Qwen';
    }

    public function extract(string $ocrText, string $model, string $apiKey, array $options): array
    {
        $baseUrl = rtrim((string) config('services.receipt_extraction_ai.providers.qwen.base_url', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'), '/');
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? 25)));
        $maxOutputTokens = max(150, min(1000, (int) ($options['max_output_tokens'] ?? 400)));

        $schemaInstruction = (string) $options['instructions']
            .' Return ONLY a valid JSON object matching this exact structure: '
            .'{"transaction_id":string|null,"sender_name":string|null,"amount":number|null,"transaction_date":string|null,"payment_method":string|null,'
            .'"confidence":{"transaction_id":number|null,"sender_name":number|null,"amount":number|null,"transaction_date":number|null,"payment_method":number|null}}.';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $schemaInstruction],
                ['role' => 'user', 'content' => "<untrusted_receipt_ocr_text>\n".$ocrText."\n</untrusted_receipt_ocr_text>"],
            ],
            'max_tokens' => $maxOutputTokens,
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException) {
            Log::warning('Receipt extraction Qwen request timed out or failed to connect.');

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        } catch (\Throwable) {
            Log::warning('Receipt extraction Qwen request failed unexpectedly.');

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        }

        if ($response->status() === 429) {
            Log::warning('Receipt extraction Qwen rate limit reached.', ['status' => 429]);

            throw new ReceiptExtractionException(
                'AI_RATE_LIMITED',
                'Receipt scanning is busy right now. Please try again shortly or enter the details manually.',
                429
            );
        }

        if (! $response->successful()) {
            Log::warning('Receipt extraction Qwen returned error status.', ['status' => $response->status()]);

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new ReceiptExtractionException(
                'AI_INVALID_RESPONSE',
                'Could not interpret receipt details automatically. Please enter the payment details manually.',
                502
            );
        }

        return $this->decodeJson($this->stripCodeFences($content));
    }

    public function testConnection(string $model, string $apiKey, array $options = []): array
    {
        $baseUrl = rtrim((string) config('services.receipt_extraction_ai.providers.qwen.base_url', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'), '/');
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? 15)));
        $start = hrtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'ping'],
                    ],
                    'max_tokens' => 1,
                ]);
        } catch (ConnectionException) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Could not reach Qwen within the configured timeout.',
                503
            );
        } catch (\Throwable) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Connection test to Qwen failed unexpectedly.',
                503
            );
        }

        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ReceiptExtractionException(
                'PROVIDER_AUTH_FAILED',
                'Qwen rejected the configured API credentials for this environment.',
                502
            );
        }

        if ($response->status() === 404) {
            throw new ReceiptExtractionException(
                'MODEL_NOT_FOUND',
                'The specified Qwen model was not found or is not accessible with the configured credentials.',
                422
            );
        }

        if ($response->status() === 400) {
            $errorBody = $response->json();
            $errorCode = strtolower((string) ($errorBody['error']['code'] ?? $errorBody['code'] ?? ''));
            $errorText = strtolower((string) ($errorBody['error']['message'] ?? $errorBody['message'] ?? ''));

            if (str_contains($errorCode, 'model') || str_contains($errorText, 'model')) {
                throw new ReceiptExtractionException(
                    'MODEL_NOT_FOUND',
                    'The specified Qwen model was not found or is not accessible with the configured credentials.',
                    422
                );
            }

            throw new ReceiptExtractionException(
                'PROVIDER_BAD_REQUEST',
                'Qwen rejected the connection test request. Verify the model configuration and account permissions.',
                422
            );
        }

        if ($response->status() === 429) {
            throw new ReceiptExtractionException(
                'AI_RATE_LIMITED',
                'Qwen rate limit reached during connection test. Please try again shortly.',
                429
            );
        }

        if (! $response->successful()) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Qwen returned an unexpected error during the connection test.',
                503
            );
        }

        return [
            'ok' => true,
            'message' => 'Connected to Qwen and verified model generation access (1-token probe).',
            'latency_ms' => $latencyMs,
        ];
    }

    private function stripCodeFences(string $text): string
    {
        $trimmed = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    private function decodeJson(string $rawJsonText): array
    {
        try {
            $decoded = json_decode($rawJsonText, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ReceiptExtractionException(
                'AI_INVALID_RESPONSE',
                'Could not interpret receipt details automatically. Please enter the payment details manually.',
                502
            );
        }

        if (! is_array($decoded)) {
            throw new ReceiptExtractionException(
                'AI_INVALID_RESPONSE',
                'Could not interpret receipt details automatically. Please enter the payment details manually.',
                502
            );
        }

        return $decoded;
    }
}
