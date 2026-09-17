<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\AiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiRequestController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'ai_model_id' => ['sometimes', 'integer', 'min:1'],
            'purpose' => ['sometimes', 'string', 'max:100'],
            'subject_type' => ['sometimes', 'string', 'max:100'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(AiRequest::STATUSES)],
            'operation_id' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AiRequest::with(['user:id,name', 'aiModel:id,name,model_key']);
        foreach (['user_id', 'ai_model_id', 'purpose', 'subject_type', 'subject_id', 'provider', 'status', 'operation_id'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        // Large payloads are available only through the detail endpoint.
        $columns = array_diff((new AiRequest)->getFillable(), ['metadata', 'request_parameters', 'response_payload']);
        $query->select(array_merge(['id', 'created_at', 'updated_at'], array_values($columns)));

        return response()->json(['success' => 1, 'ai_requests' => $query->orderByDesc('id')->paginate($filters['per_page'] ?? 25)]);
    }

    public function show(int $id)
    {
        return response()->json(['success' => 1, 'ai_request' => AiRequest::with(['user:id,name', 'aiModel:id,name,model_key'])->findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $model = AiModel::where('is_active', true)->findOrFail($data['ai_model_id']);
        $data['user_id'] = $request->user()->id;
        $data['provider'] = $model->provider->key;
        $data['requested_model'] = $model->model_key;
        $data['pricing_snapshot'] = $model->pricingSnapshot();
        $data['environment'] = app()->environment();
        $data['trigger_type'] = 'user';
        $data['record_source'] = 'admin';
        $data['operation_id'] = $data['operation_id'] ?? (string) Str::uuid();
        $data['attempt_number'] = $data['attempt_number'] ?? 1;

        if (AiRequest::withTrashed()->where('operation_id', $data['operation_id'])->where('attempt_number', $data['attempt_number'])->exists()) {
            throw ValidationException::withMessages(['attempt_number' => 'This operation attempt already exists.']);
        }
        $record = new AiRequest($data);
        $this->validateConsistency($record);
        $record->save();

        return response()->json(['success' => 1, 'message' => 'AI request record created.', 'ai_request' => $record->refresh()], 201);
    }

    public function update(Request $request, int $id)
    {
        $record = AiRequest::findOrFail($id);
        abort_if($record->record_source === 'provider', 409, 'Provider-generated request records cannot be edited through CRUD.');
        $record->fill($this->validated($request, true));
        $this->validateConsistency($record);
        $record->save();

        return response()->json(['success' => 1, 'message' => 'AI request record updated.', 'ai_request' => $record->refresh()]);
    }

    public function destroy(int $id)
    {
        AiRequest::findOrFail($id)->delete();

        return response()->json(['success' => 1, 'message' => 'AI request record deleted.']);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $rules = [
            'ai_model_id' => $updating ? ['prohibited'] : ['required', 'integer', Rule::exists('ai_models', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'purpose' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'max:100'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'language' => ['sometimes', 'nullable', 'string', 'max:20'],
            'prompt_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'request_parameters' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'response_payload' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::in(AiRequest::STATUSES)],
            'response_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'returned_model' => ['sometimes', 'nullable', 'string', 'max:150'],
            'estimated_cost' => ['sometimes', 'nullable', 'numeric', 'between:0,9999999999.99999999'],
            'provider_request_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'provider_response_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'http_status' => ['sometimes', 'nullable', 'integer', 'between:100,599'],
            'error_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'error_message' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'operation_id' => $updating ? ['prohibited'] : ['sometimes', 'uuid'],
            'attempt_number' => $updating ? ['prohibited'] : ['sometimes', 'integer', 'between:1,1000000'],
        ];
        foreach (['user_id', 'provider', 'requested_model', 'pricing_snapshot', 'environment', 'trigger_type', 'record_source', 'created_at', 'updated_at', 'deleted_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens', 'duration_ms'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'between:0,9007199254740991'];
        }
        $data = $request->validate($rules);
        foreach (['request_parameters', 'metadata', 'response_payload'] as $field) {
            $this->rejectCredentials($data[$field] ?? [], $field);
        }

        return $data;
    }

    private function rejectCredentials(array $payload, string $path): void
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (in_array($normalized, ['apikey', 'openaiapikey', 'geminiapikey', 'anthropicapikey', 'authorization', 'password', 'secret', 'accesstoken', 'refreshtoken', 'headers'], true)) {
                throw ValidationException::withMessages([$path => 'Credentials and HTTP headers must not be stored in AI records.']);
            }
            if (is_array($value)) {
                $this->rejectCredentials($value, $path);
            }
        }
    }

    private function validateConsistency(AiRequest $record): void
    {
        if (($record->subject_type === null) !== ($record->subject_id === null)) {
            throw ValidationException::withMessages(['subject_id' => 'Provide both subject_type and subject_id, or leave both empty.']);
        }
        foreach (['cached_input_tokens' => 'input_tokens', 'reasoning_tokens' => 'output_tokens'] as $subset => $total) {
            if ($record->$subset !== null && $record->$total !== null && $record->$subset > $record->$total) {
                throw ValidationException::withMessages([$subset => "Must not exceed {$total}."]);
            }
        }
        if ($record->input_tokens !== null && $record->output_tokens !== null && $record->total_tokens !== null
            && $record->total_tokens !== $record->input_tokens + $record->output_tokens) {
            throw ValidationException::withMessages(['total_tokens' => 'Must equal input_tokens plus output_tokens; cached and reasoning tokens are subsets.']);
        }
        if ($record->completed_at !== null && $record->exists && $record->completed_at->lt($record->created_at)) {
            throw ValidationException::withMessages(['completed_at' => 'Must not precede request creation.']);
        }
        if (($record->status ?? 'pending') === 'pending' && $record->completed_at !== null) {
            throw ValidationException::withMessages(['completed_at' => 'A pending request cannot have a completion time.']);
        }
    }
}
