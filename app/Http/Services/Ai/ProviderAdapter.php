<?php

namespace App\Http\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Http\Client\Response;

interface ProviderAdapter
{
    public function generate(AiModel $model, array $input): Response;

    /** Normalize identity, usage, completion status and text for shared validation/logging. */
    public function normalize(array $body): array;

    public function testConnection(AiProvider $provider): Response;
}
