<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Exceptions\ReceiptExtractionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIReceiptExtractionProvider implements ReceiptExtractionProviderInterface
{
    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function extract(string $ocrText, string $model, string $apiKey, array $options): array
    {
        $baseUrl = rtrim((string) config('services.receipt_extraction_ai.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? 25)));
        $maxOutputTokens = max(150, min(1000, (int) ($options['max_output_tokens'] ?? 400)));

        $payload = [
            'model' => $model,
            'store' => false,
            'instructions' => (string) $options['instructions'],
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => "<untrusted_receipt_ocr_text>\n".$ocrText."\n</untrusted_receipt_ocr_text>",
                        ],
                    ],
                ],
            ],
            'max_output_tokens' => $maxOutputTokens,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'payment_receipt_extraction',
                    'strict' => true,
                    'schema' => $options['schema'],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post($baseUrl.'/responses', $payload);
        } catch (ConnectionException) {
            Log::warning('Receipt extraction OpenAI request timed out or failed to connect.');

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        } catch (\Throwable) {
            Log::warning('Receipt extraction OpenAI request failed unexpectedly.');

            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
                503
            );
        }

        if ($response->status() === 429) {
            Log::warning('Receipt extraction OpenAI rate limit reached.', ['status' => 429]);

            throw new ReceiptExtractionException(
                'AI_RATE_LIMITED',
                'Receipt scanning is busy right now. Please try again shortly or enter the details manually.',
                429
            );
        }

        if (! $response->successful()) {
            Log::warning('Receipt extraction OpenAI returned error status.', ['status' => $response->status()]);

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
        $baseUrl = rtrim((string) config('services.receipt_extraction_ai.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? 15)));
        $start = hrtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->get($baseUrl.'/models/'.rawurlencode($model));
        } catch (ConnectionException) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Could not reach OpenAI within the configured timeout.',
                503
            );
        } catch (\Throwable) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'Connection test to OpenAI failed unexpectedly.',
                503
            );
        }

        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ReceiptExtractionException(
                'PROVIDER_AUTH_FAILED',
                'OpenAI rejected the configured API credentials for this environment.',
                502
            );
        }

        if ($response->status() === 404) {
            throw new ReceiptExtractionException(
                'MODEL_NOT_FOUND',
                'The specified OpenAI model was not found or is not accessible with the configured credentials.',
                422
            );
        }

        if ($response->status() === 429) {
            throw new ReceiptExtractionException(
                'AI_RATE_LIMITED',
                'OpenAI rate limit reached during connection test. Please try again shortly.',
                429
            );
        }

        if (! $response->successful()) {
            throw new ReceiptExtractionException(
                'AI_UNAVAILABLE',
                'OpenAI returned an unexpected error during the connection test.',
                503
            );
        }

        return [
            'ok' => true,
            'message' => 'Connected to OpenAI and verified model metadata access. (Note: this checks API authentication and model availability, not generation quota.)',
            'latency_ms' => $latencyMs,
        ];
    }

    private function extractOutputText(array $body): ?string
    {
        if (isset($body['status']) && $body['status'] !== 'completed') {
            return null;
        }

        $text = '';
        $refused = false;

        foreach (is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? null) === 'refusal') {
                    $refused = true;
                }
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        if ($refused) {
            return null;
        }

        if ($text !== '') {
            return trim($text);
        }

        if (is_string($body['output_text'] ?? null) && trim($body['output_text']) !== '') {
            return trim($body['output_text']);
        }

        return null;
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
