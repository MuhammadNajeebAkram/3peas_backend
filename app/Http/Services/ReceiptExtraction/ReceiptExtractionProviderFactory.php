<?php

namespace App\Http\Services\ReceiptExtraction;

use App\Exceptions\ReceiptExtractionException;

class ReceiptExtractionProviderFactory
{
    /**
     * @var array<string, class-string<ReceiptExtractionProviderInterface>>
     */
    private const PROVIDERS = [
        'openai' => OpenAIReceiptExtractionProvider::class,
        'gemini' => GeminiReceiptExtractionProvider::class,
        'qwen' => QwenReceiptExtractionProvider::class,
    ];

    /**
     * @return array<int, string>
     */
    public function supportedProviderKeys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function resolve(string $provider): ReceiptExtractionProviderInterface
    {
        $normalized = strtolower(trim($provider));
        $class = self::PROVIDERS[$normalized] ?? null;

        if ($class === null) {
            throw new ReceiptExtractionException(
                'UNSUPPORTED_PROVIDER',
                'The selected AI provider is not supported for receipt extraction.',
                503
            );
        }

        return app($class);
    }
}
