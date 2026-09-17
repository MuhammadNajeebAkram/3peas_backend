<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiModelController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:150'],
            'ai_provider_id' => ['sometimes', 'integer', 'exists:ai_providers,id'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AiModel::with('provider');
        if (isset($filters['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
                ->orWhere('model_key', 'like', '%'.$filters['search'].'%'));
        }
        foreach (['ai_provider_id', 'is_active'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }

        return response()->json(['success' => 1, 'ai_models' => $query
            ->orderByDesc('is_default')->orderBy('name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)]);
    }

    public function active()
    {
        return response()->json(['success' => 1, 'ai_models' => AiModel::where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true)->whereIn('key', array_filter(AiProvider::SUPPORTED, fn ($key) => filled(config('services.'.$key.'.api_key')))))
            ->with('provider')
            ->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function show(int $id)
    {
        return response()->json(['success' => 1, 'ai_model' => AiModel::with('provider')->findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $model = $this->persist(new AiModel, $data);

        return response()->json(['success' => 1, 'message' => 'AI model created.', 'ai_model' => $model], 201);
    }

    public function update(Request $request, int $id)
    {
        $model = AiModel::findOrFail($id);
        $data = $this->validated($request, $model);

        return response()->json(['success' => 1, 'message' => 'AI model updated.',
            'ai_model' => $this->persist($model, $data)]);
    }

    public function destroy(int $id)
    {
        DB::transaction(function () use ($id) {
            $model = AiModel::lockForUpdate()->findOrFail($id);
            $model->update(['is_default' => false, 'is_active' => false]);
            $model->delete();
        });

        return response()->json(['success' => 1, 'message' => 'AI model deleted.']);
    }

    private function validated(Request $request, ?AiModel $model = null): array
    {
        $required = $model ? 'sometimes' : 'required';

        return $request->validate([
            'ai_provider_id' => [$required, 'required', 'integer', 'exists:ai_providers,id'],
            'provider' => ['prohibited'],
            'supports_images' => ['sometimes', 'boolean'],
            'name' => [$required, 'required', 'string', 'max:255'],
            'model_key' => [$required, 'required', 'string', 'max:150',
                Rule::unique('ai_models', 'model_key')->where('ai_provider_id', $request->input('ai_provider_id', $model?->ai_provider_id))->ignore($model?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'input_price_per_million' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999.999999'],
            'cached_input_price_per_million' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999.999999'],
            'output_price_per_million' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999.999999'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
        ]);
    }

    private function persist(AiModel $model, array $data): AiModel
    {
        return DB::transaction(function () use ($model, $data) {
            $provider = AiProvider::lockForUpdate()->findOrFail($data['ai_provider_id'] ?? $model->ai_provider_id);
            // Serialize updates of existing rows; the unique default_slot also protects an empty table.
            AiModel::orderBy('id')->lockForUpdate()->get(['id']);
            if ($model->exists) {
                $model->refresh();
            }
            $model->fill($data);
            if (! $model->exists) {
                $model->is_active = $data['is_active'] ?? true;
                $model->is_default = $data['is_default'] ?? false;
            }
            if ($model->is_default && ! $model->is_active) {
                throw ValidationException::withMessages(['is_default' => 'The default model must be active. Unset is_default to deactivate it.']);
            }
            if ($model->is_default && (! $provider->is_active || ! $provider->is_supported)) {
                throw ValidationException::withMessages(['ai_provider_id' => 'The default model requires an active supported provider.']);
            }
            // Validate the full pair, including provider-only updates.
            if (AiModel::withTrashed()->where('ai_provider_id', $model->ai_provider_id)->where('model_key', $model->model_key)
                ->when($model->exists, fn ($q) => $q->where('id', '!=', $model->id))->exists()) {
                throw ValidationException::withMessages(['model_key' => 'This provider and model key already exist.']);
            }
            if ($model->is_default) {
                AiModel::where('is_default', true)->when($model->exists, fn ($q) => $q->where('id', '!=', $model->id))
                    ->update(['is_default' => false]);
            }
            $model->save();

            return $model->refresh()->load('provider');
        }, 3);
    }
}
