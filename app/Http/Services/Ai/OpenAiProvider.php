<?php

namespace App\Http\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements ProviderAdapter
{
    private function client(AiProvider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::withToken(config('services.openai.api_key'))->acceptJson()->asJson()
            ->connectTimeout(10)->timeout(max(1, min(90, $timeout ?? $provider->setting('timeout', 45))))
            ->withOptions(['allow_redirects' => false]);
    }

    public function generate(AiModel $model, array $input): Response
    {
        $content = [['type' => 'input_text', 'text' => $input['text']]];
        foreach ($input['images'] as $image) {
            $content[] = ['type' => 'input_image', 'image_url' => $image];
        }
        foreach ($input['attachments'] ?? [] as $file) {
            $content[] = ['type' => 'input_text', 'text' => 'Source ID: '.$file['source_id']];
            $data = 'data:'.$file['mime'].';base64,'.$file['data'];
            $content[] = $file['mime'] === 'application/pdf'
                ? ['type' => 'input_file', 'filename' => $file['source_id'].'.pdf', 'file_data' => $data]
                : ['type' => 'input_image', 'image_url' => $data];
        }

        return $this->client($model->provider, $input['timeout'] ?? null)->post('https://api.openai.com/v1/responses', [
            'model' => $model->model_key, 'store' => false, 'instructions' => $input['instructions'],
            'input' => [['role' => 'user', 'content' => $content]],
            'max_output_tokens' => $input['max_output_tokens'],
            'text' => ['format' => ['type' => 'json_schema', 'name' => $input['schema_name'] ?? 'question_explanation', 'strict' => true, 'schema' => $input['schema']]],
        ]);
    }

    public function normalize(array $body): array
    {
        return $body;
    }

    public function testConnection(AiProvider $provider): Response
    {
        return $this->client($provider)->get('https://api.openai.com/v1/models');
    }
}
