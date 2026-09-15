<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
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
            'provider' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AiModel::query();
        if (isset($filters['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
                ->orWhere('model_key', 'like', '%'.$filters['search'].'%'));
        }
        foreach (['provider', 'is_active'] as $field) {
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
            ->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function show(int $id)
    {
        return response()->json(['success' => 1, 'ai_model' => AiModel::findOrFail($id)]);
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
            'provider' => [$required, 'required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'name' => [$required, 'required', 'string', 'max:255'],
            'model_key' => [$required, 'required', 'string', 'max:150',
                Rule::unique('ai_models', 'model_key')->where('provider', $request->input('provider', $model?->provider))->ignore($model?->id)],
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
            // Validate the full pair, including provider-only updates.
            if (AiModel::withTrashed()->where('provider', $model->provider)->where('model_key', $model->model_key)
                ->when($model->exists, fn ($q) => $q->where('id', '!=', $model->id))->exists()) {
                throw ValidationException::withMessages(['model_key' => 'This provider and model key already exist.']);
            }
            if ($model->is_default) {
                AiModel::where('is_default', true)->when($model->exists, fn ($q) => $q->where('id', '!=', $model->id))
                    ->update(['is_default' => false]);
            }
            $model->save();

            return $model->refresh();
        }, 3);
    }
}
