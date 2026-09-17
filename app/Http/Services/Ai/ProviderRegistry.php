<?php

namespace App\Http\Services\Ai;

use App\Models\AiProvider;

class ProviderRegistry
{
    public function for(AiProvider $provider): ProviderAdapter
    {
        return match ($provider->key) {
            'openai' => app(OpenAiProvider::class),
            'gemini' => app(GeminiProvider::class),
            default => abort(422, 'Unsupported AI provider.'),
        };
    }
}
