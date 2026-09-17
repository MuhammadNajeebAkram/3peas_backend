<?php

namespace App\Http\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements ProviderAdapter
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/';

    private function client(AiProvider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])->acceptJson()->asJson()
            ->connectTimeout(10)->timeout(max(1, min(90, $timeout ?? $provider->setting('timeout', 45))))
            ->withOptions(['allow_redirects' => false]);
    }

    public function generate(AiModel $model, array $input): Response
    {
        $parts = [['text' => $input['text']]];
        foreach ($input['attachments'] ?? [] as $file) {
            $parts[] = ['text' => 'Source ID: '.$file['source_id']];
            $parts[] = ['inlineData' => ['mimeType' => $file['mime'], 'data' => $file['data']]];
        }
        foreach ($input['images'] as $image) {
            $mime = match (strtolower(pathinfo(parse_url($image, PHP_URL_PATH), PATHINFO_EXTENSION))) {
                'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', default => null,
            };
            $parts[] = ['fileData' => array_filter(['fileUri' => $image, 'mimeType' => $mime])];
        }

        return $this->client($model->provider, $input['timeout'] ?? null)->post(self::BASE.'models/'.rawurlencode($model->model_key).':generateContent', [
            'systemInstruction' => ['parts' => [['text' => $input['instructions']]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['maxOutputTokens' => $input['max_output_tokens'],
                'responseMimeType' => 'application/json', 'responseJsonSchema' => $input['schema']],
        ]);
    }

    public function normalize(array $body): array
    {
        $usage = $body['usageMetadata'] ?? [];
        $candidate = $body['candidates'][0] ?? [];
        $reason = $candidate['finishReason'] ?? null;
        $refused = filled($body['promptFeedback']['blockReason'] ?? null)
            || in_array($reason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'], true);
        $content = [];
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_string($part['text'] ?? null) && ! ($part['thought'] ?? false)) {
                $content[] = ['type' => 'output_text', 'text' => $part['text']];
            }
        }
        if ($refused) {
            $content[] = ['type' => 'refusal'];
        }
        $output = isset($usage['candidatesTokenCount'])
            ? $usage['candidatesTokenCount'] + ($usage['thoughtsTokenCount'] ?? 0) : null;

        return ['id' => $body['responseId'] ?? null, 'model' => $body['modelVersion'] ?? null,
            'status' => $reason === 'STOP' && ! $refused ? 'completed' : 'incomplete',
            'output' => [['type' => 'message', 'content' => $content]],
            'usage' => ['input_tokens' => $usage['promptTokenCount'] ?? null,
                'input_tokens_details' => ['cached_tokens' => isset($usage['promptTokenCount']) ? ($usage['cachedContentTokenCount'] ?? 0) : null],
                'output_tokens' => $output,
                'output_tokens_details' => ['reasoning_tokens' => isset($usage['candidatesTokenCount']) ? ($usage['thoughtsTokenCount'] ?? 0) : null],
                'total_tokens' => $usage['totalTokenCount'] ?? null,
                'provider_usage' => $usage],
        ];
    }

    public function testConnection(AiProvider $provider): Response
    {
        return $this->client($provider)->get(self::BASE.'models', ['pageSize' => 1]);
    }
}
