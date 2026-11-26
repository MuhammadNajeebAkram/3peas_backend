<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Exceptions\ReceiptExtractionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiReceiptExtractionProvider implements ReceiptExtractionProviderInterface
{
    public function key(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    public function extract(string $ocrText, string $model, string $apiKey, array $options): array
    {
        $baseUrl = rtrim((string) config('services.receipt_extraction_ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? 25)));
        $maxOutputTokens = max(150, min(1000, (int) ($options['max_output_tokens'] ?? 400)));

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => (string) $options['instructions']]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => "<untrusted_receipt_ocr_text>\n".$ocrText."\n</untrusted_receipt_ocr_text>"],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxOutputTokens,
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $options['schema'],
            ],
        ];

        $normalizedModel = $this->normalizeModelKey($model);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post($baseUrl.'/models/'.rawurlencode($normalizedModel).':generateContent', $payload);
        } catch (ConnectionException) {
            Log::warning('Receipt extraction Gemini request timed out or failed to connect.');

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        } catch (\Throwable) {
            Log::warning('Receipt extraction Gemini request failed unexpectedly.');

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        }

        if ($response->status() === 429) {
            Log::warning('Receipt extraction Gemini rate limit reached.', ['status' => 429]);

            throw new ReceiptExtractionException(
                'AI_RATE_LIMITED',
                'Receipt scanning is busy right now. Please try again shortly or enter the details manually.',
                429
            );
        }

        if (! $response->successful()) {
            Log::warning('Receipt extraction Gemini returned error status.', ['status' => $response->status()]);

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new ReceiptExtractionException(
                'AI_INVALID_RESPONSE',
                'Could not interpret receipt details automatically. Please enter the payment details manually.',
                502
            );
        }

        $rawJsonText = $this->extractOutputText($body);
        if ($rawJsonText === null) {
            throw new ReceiptExtractionException(
                'AI_INVALID_RESPONSE',
                'Could not interpret receipt details automatically. Please enter the payment details manually.',
                502
            );
        }

        return $this->decodeJson($rawJsonText);
    }

    public function testConnection(string $model, string $apiKey, array $options = []): array
    {
        $baseUrl = rtrim((string) config('services.receipt_extraction_ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? 15)));
        $normalizedModel = $this->normalizeModelKey($model);
        $start = hrtime(true);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->get($baseUrl.'/models/'.rawurlencode($normalizedModel));
        } catch (ConnectionException) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Could not reach Google Gemini within the configured timeout.',
                503
            );
        } catch (\Throwable) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Connection test to Google Gemini failed unexpectedly.',
                503
            );
        }

        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ReceiptExtractionException(
                'PROVIDER_AUTH_FAILED',
                'Google Gemini rejected the configured API credentials for this environment.',
                502
            );
        }

        if ($response->status() === 404) {
            throw new ReceiptExtractionException(
                'MODEL_NOT_FOUND',
                'The specified Google Gemini model was not found or is not accessible with the configured credentials.',
                422
            );
        }

        if ($response->status() === 429) {
            throw new ReceiptExtractionException(
                'AI_RATE_LIMITED',
                'Google Gemini rate limit reached during connection test. Please try again shortly.',
                429
            );
        }

        if (! $response->successful()) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Google Gemini returned an unexpected error during the connection test.',
                503
            );
        }

        return [
            'ok' => true,
            'message' => 'Connected to Google Gemini and verified model metadata access. (Note: this checks API authentication and model availability, not generation quota.)',
            'latency_ms' => $latencyMs,
        ];
    }

    public function normalizeModelKey(string $model): string
    {
        $trimmed = trim($model);

        return preg_replace('/^models\//i', '', $trimmed) ?? $trimmed;
    }

    private function extractOutputText(array $body): ?string
    {
        $candidate = $body['candidates'][0] ?? null;
        if (! is_array($candidate)) {
            return null;
        }

        $reason = $candidate['finishReason'] ?? null;
        $refused = filled($body['promptFeedback']['blockReason'] ?? null)
            || in_array($reason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'], true);

        if ($refused) {
            return null;
        }

        $text = '';
        foreach (is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && ! ($part['thought'] ?? false)) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
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
