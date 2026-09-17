<?php

namespace App\Http\Controllers;

use App\Http\Services\Ai\ProviderRegistry;
use App\Models\AiProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiProviderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AiProvider::withCount('models');
        if (isset($filters['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('key', 'like', '%'.$filters['search'].'%'));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return response()->json(['success' => 1, 'supported_providers' => AiProvider::SUPPORTED,
            'ai_providers' => $query->orderBy('id')->paginate($filters['per_page'] ?? 25)]);
    }

    public function show(int $id)
    {
        return response()->json(['success' => 1, 'ai_provider' => AiProvider::withCount('models')->findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return response()->json(['success' => 1, 'ai_provider' => AiProvider::create($data)->refresh()], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validated($request, true);
        $provider = DB::transaction(function () use ($id, $data) {
            $provider = AiProvider::lockForUpdate()->findOrFail($id);
            abort_if(isset($data['is_active']) && $data['is_active'] && ! $provider->is_supported, 422, 'This provider has no installed adapter.');
            abort_if(isset($data['is_active']) && ! $data['is_active'] && $provider->models()->where('is_default', true)->exists(),
                422, 'Select another default model or unset the current default before disabling this provider.');
            $provider->update($data);

            return $provider;
        });

        return response()->json(['success' => 1, 'ai_provider' => $provider]);
    }

    public function destroy(int $id)
    {
        DB::transaction(function () use ($id) {
            $provider = AiProvider::lockForUpdate()->findOrFail($id);
            abort_if($provider->models()->withTrashed()->exists(), 409, 'This provider has models. Disable it instead.');
            $provider->delete();
        });

        return response()->json(['success' => 1, 'message' => 'AI provider deleted.']);
    }

    public function testConnection(int $id, ProviderRegistry $registry)
    {
        $provider = AiProvider::findOrFail($id);
        abort_unless($provider->is_configured, 422, 'The provider adapter or API key is not configured.');
        try {
            $response = $registry->for($provider)->testConnection($provider);
        } catch (\Throwable $exception) {
            return response()->json(['success' => 0, 'message' => 'Provider connection failed.'], 502);
        }

        return response()->json(['success' => $response->successful() ? 1 : 0,
            'http_status' => $response->status(),
            'message' => $response->successful() ? 'Connection successful. Model generation access is not verified.' : 'Provider connection failed.'],
            $response->successful() ? 200 : 502);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'key' => $updating ? ['prohibited'] : ['required', Rule::in(AiProvider::SUPPORTED), Rule::unique('ai_providers', 'key')],
            'name' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array:timeout,max_output_tokens'],
            'settings.timeout' => ['sometimes', 'integer', 'between:1,90'],
            'settings.max_output_tokens' => ['sometimes', 'integer', 'between:500,4000'],
            'api_key' => ['prohibited'],
        ]);
    }
}
