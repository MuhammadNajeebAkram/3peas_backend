<?php

namespace App\Http\Services;

use App\Exceptions\AiGenerationException;
use App\Http\Services\Ai\ProviderRegistry;
use App\Models\AiModel;
use App\Models\AiRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

class AiService
{
    public function __construct(private ProviderRegistry $providers) {}

    /** Each outbound attempt gets its own row, including retries and unknown usage. */
    public function generate(AiModel $model, array $payload, array $context, callable $validate): AiRequest
    {
        $outputName = ($context['purpose'] ?? null) === 'question_generation' ? 'question draft' : 'explanation';
        $model->load('provider');
        abort_unless($model->is_active && $model->provider->is_active, 422, 'The model and provider must be active.');
        abort_unless($model->provider->is_configured, 503, 'The AI provider adapter or API key is not configured.');
        $adapter = $this->providers->for($model->provider);
        $operationId = (string) Str::uuid();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $record = AiRequest::create(array_merge($context, [
                'ai_model_id' => $model->id, 'provider' => $model->provider->key,
                'requested_model' => $model->model_key, 'operation_id' => $operationId,
                'attempt_number' => $attempt, 'record_source' => 'provider',
                'trigger_type' => 'user', 'environment' => app()->environment(),
                'status' => 'pending', 'pricing_snapshot' => $model->pricingSnapshot(),
                // No key, headers, or question/image contents are persisted here.
                'request_parameters' => ['model' => $model->model_key, 'max_output_tokens' => $payload['max_output_tokens'], 'schema' => $payload['schema']],
            ]));
            $start = hrtime(true);
            try {
                $response = $adapter->generate($model, $payload);
            } catch (ConnectionException $exception) {
                $record->update([
                    'status' => 'failed', 'error_code' => 'connection_error',
                    'error_message' => 'The AI provider could not be reached or the request timed out. Usage is unknown.',
                    'duration_ms' => $this->elapsed($start), 'completed_at' => now(),
                ]);
                // Never retry an ambiguous timeout: the provider may have processed it.
                throw new AiGenerationException('The AI provider could not be reached or timed out. Please try again.', $record->id, 504);
            } catch (\Throwable $exception) {
                $record->update([
                    'status' => 'failed', 'error_code' => 'transport_error',
                    'error_message' => 'The provider request could not be completed. Usage is unknown.',
                    'duration_ms' => $this->elapsed($start), 'completed_at' => now(),
                ]);
                throw new AiGenerationException('The provider request could not be completed.', $record->id);
            }
            $body = $response->json();
            try {
                $body = $adapter->normalize(is_array($body) ? $body : []);
            } catch (\Throwable $exception) {
                $record->update(['status' => 'failed', 'error_code' => 'invalid_response',
                    'error_message' => 'The provider returned an invalid response. Usage is unknown.',
                    'http_status' => $response->status(), 'duration_ms' => $this->elapsed($start), 'completed_at' => now()]);
                throw new AiGenerationException('The provider returned an invalid response.', $record->id);
            }
            $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
            $record->fill([
                'http_status' => $response->status(), 'duration_ms' => $this->elapsed($start),
                'provider_request_id' => $this->identifier($response->header('x-request-id')),
                'provider_response_id' => $this->identifier($body['id'] ?? null),
                'returned_model' => $this->identifier($body['model'] ?? null, 150),
                'response_status' => $this->identifier($body['status'] ?? null, 50),
                'input_tokens' => $this->tokens($usage['input_tokens'] ?? null),
                'cached_input_tokens' => $this->tokens(data_get($usage, 'input_tokens_details.cached_tokens')),
                'output_tokens' => $this->tokens($usage['output_tokens'] ?? null),
                'reasoning_tokens' => $this->tokens(data_get($usage, 'output_tokens_details.reasoning_tokens')),
                'total_tokens' => $this->tokens($usage['total_tokens'] ?? null),
                'completed_at' => now(),
            ]);
            $record->estimated_cost = $this->estimateCost($record, $usage);
            // Preserve usage detail (including any future provider fields) for audit.
            $record->metadata = array_merge($record->metadata ?? [], ['usage' => $usage]);
            if (! $response->successful()) {
                $message = $response->status() === 404
                    ? 'The selected AI model is unavailable for this API account or endpoint. Select another model or update its model key in AI model settings.'
                    : 'The AI provider could not generate a valid '.$outputName.'. Check the request log and model access.';
                $record->fill(['status' => 'failed', 'error_code' => 'provider_http_'.$response->status(),
                    'error_message' => $response->status() === 404 ? $message : 'The AI provider returned HTTP '.$response->status().'.'])->save();
                if ($attempt < 2 && ($response->status() === 429 || $response->serverError())) {
                    usleep(500000);

                    continue;
                }
                throw new AiGenerationException($message, $record->id);
            }
            $text = '';
            $refused = false;
            foreach (is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
                if (($item['type'] ?? null) !== 'message') {
                    continue;
                }
                foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                    $refused = $refused || ($part['type'] ?? null) === 'refusal';
                    if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                        $text .= $part['text'];
                    }
                }
            }
            if ($refused || ($body['status'] ?? null) !== 'completed') {
                $status = $refused ? 'refused' : 'incomplete';
                $record->fill(['status' => $status, 'error_code' => $status,
                    'error_message' => 'The AI provider did not return a complete '.$outputName.'.'])->save();
                throw new AiGenerationException('The AI provider did not return a complete '.$outputName.'. Please try again.', $record->id);
            }
            try {
                $draft = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                $validate($draft);
            } catch (\JsonException|\Illuminate\Validation\ValidationException $exception) {
                $record->fill(['status' => 'failed', 'error_code' => 'invalid_output',
                    'error_message' => 'The response did not satisfy the '.$outputName.' format or length limits.'])->save();
                throw new AiGenerationException('The generated '.$outputName.' did not meet the format or length limits. Please regenerate.', $record->id);
            }
            $record->fill(['status' => 'successful', 'response_payload' => $draft])->save();

            return $record;
        }
        throw new \LogicException('AI attempt loop ended unexpectedly.');
    }

    private function tokens(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function identifier(mixed $value, int $limit = 255): ?string
    {
        return is_string($value) ? mb_substr($value, 0, $limit) : null;
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1000000);
    }

    private function estimateCost(AiRequest $record, array $usage): ?string
    {
        $prices = $record->pricing_snapshot;
        if ($record->input_tokens === null || $record->output_tokens === null || $record->cached_input_tokens === null
            || $record->cached_input_tokens > $record->input_tokens
            || data_get($usage, 'input_tokens_details.cache_write_tokens', 0) > 0) {
            return null;
        }
        $uncached = $record->input_tokens - $record->cached_input_tokens;
        $cost = 0;
        foreach (['input_price_per_million' => $uncached,
            'cached_input_price_per_million' => $record->cached_input_tokens,
            'output_price_per_million' => $record->output_tokens] as $field => $tokens) {
            if ($tokens > 0 && ($prices[$field] ?? null) === null) {
                return null;
            }
            $cost += $tokens * (float) ($prices[$field] ?? 0) / 1000000;
        }

        return number_format($cost, 8, '.', '');
    }
}
