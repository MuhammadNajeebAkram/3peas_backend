<?php

namespace App\Http\Controllers;

use App\Http\Services\BulkQuestionExplanationQueryService;
use App\Http\Services\BulkQuestionExplanationService;
use App\Http\Services\QuestionExplanationService;
use App\Models\AiExplanationBatch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BulkQuestionExplanationController extends Controller
{
    public function preview(
        Request $request,
        BulkQuestionExplanationQueryService $queryService,
        QuestionExplanationService $explanationService
    ) {
        $data = $request->validate($this->filterValidationRules() + [
            'language' => ['sometimes', 'nullable', Rule::in(['en', 'ur', 'both'])],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_provider_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:ai_providers,id'],
            'batch_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.config('ai_bulk_explanations.max_batch_size', 100)],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $model = null;
        if (! empty($data['model_id']) || ! empty($data['ai_provider_id'])) {
            $model = $explanationService->model(
                isset($data['model_id']) ? (int) $data['model_id'] : null,
                isset($data['ai_provider_id']) ? (int) $data['ai_provider_id'] : null
            );
        } else {
            try {
                $model = $explanationService->model(null, null);
            } catch (\Illuminate\Validation\ValidationException) {
                $model = null;
            }
        }

        $preview = $queryService->preview(
            $request->user(),
            $data,
            $model,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 25)
        );

        return response()->json([
            'success' => 1,
            'data' => $preview,
        ]);
    }

    public function createBatch(Request $request, BulkQuestionExplanationService $service)
    {
        $maxSize = (int) config('ai_bulk_explanations.max_batch_size', 100);
        $data = $request->validate($this->filterValidationRules() + [
            'language' => ['required', Rule::in(['en', 'ur', 'both'])],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_provider_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:ai_providers,id'],
            'batch_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$maxSize],
            'overwrite_existing' => ['sometimes', 'boolean'],
        ]);

        $batch = $service->createBatch($request->user(), $data);

        return response()->json([
            'success' => 1,
            'message' => 'Bulk explanation batch queued for draft generation.',
            'data' => $batch,
        ], 201);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([
                AiExplanationBatch::STATUS_QUEUED,
                AiExplanationBatch::STATUS_RUNNING,
                AiExplanationBatch::STATUS_COMPLETED,
                AiExplanationBatch::STATUS_FAILED,
                AiExplanationBatch::STATUS_CANCELLED,
            ])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $user->loadMissing('role');
        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $user->role?->name)));

        $query = AiExplanationBatch::with(['model:id,name,model_key,ai_provider_id', 'provider:id,name,key'])
            ->orderByDesc('id');

        if ($roleName !== 'super_admin') {
            $query->where('created_by', $user->id);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $batches = $query->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'success' => 1,
            'data' => $batches,
        ]);
    }

    public function show(Request $request, AiExplanationBatch $batch, BulkQuestionExplanationService $service)
    {
        $service->authorizeBatchAccess($request->user(), $batch);
        if (in_array($batch->status, [AiExplanationBatch::STATUS_QUEUED, AiExplanationBatch::STATUS_RUNNING], true)) {
            $batch = $service->reconcileBatch($batch, false)['batch'];
        }
        $batch->load(['model:id,name,model_key,ai_provider_id', 'provider:id,name,key']);

        return response()->json([
            'success' => 1,
            'data' => $batch,
        ]);
    }

    public function reconcile(Request $request, AiExplanationBatch $batch, BulkQuestionExplanationService $service)
    {
        $service->authorizeBatchAccess($request->user(), $batch);
        $includeStaleDispatched = (bool) $request->boolean('include_stale_dispatched', false);
        $result = $service->reconcileBatch($batch, $includeStaleDispatched);

        return response()->json([
            'success' => 1,
            'message' => 'Batch reconciliation completed.',
            'data' => $result,
        ]);
    }

    public function items(Request $request, AiExplanationBatch $batch, BulkQuestionExplanationService $service)
    {
        $service->authorizeBatchAccess($request->user(), $batch);

        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([
                'pending', 'processing', 'generated', 'saved', 'needs_review', 'failed', 'skipped',
            ])],
            'needs_review' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $batch->items()->orderBy('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (array_key_exists('needs_review', $data) && $data['needs_review'] !== null) {
            $query->where('needs_review', (bool) $data['needs_review']);
        }

        return response()->json([
            'success' => 1,
            'data' => $query->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function apply(Request $request, AiExplanationBatch $batch, BulkQuestionExplanationService $service)
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $result = $service->applyDrafts($request->user(), $batch, $data['item_ids']);

        return response()->json([
            'success' => 1,
            'message' => 'Approved explanation drafts saved.',
            'data' => $result,
        ]);
    }

    public function retryFailed(Request $request, AiExplanationBatch $batch, BulkQuestionExplanationService $service)
    {
        $updated = $service->retryFailedItems($request->user(), $batch);

        return response()->json([
            'success' => 1,
            'message' => 'Failed batch items queued for retry.',
            'data' => $updated,
        ]);
    }

    public function cancel(Request $request, AiExplanationBatch $batch, BulkQuestionExplanationService $service)
    {
        $updated = $service->cancelBatch($request->user(), $batch);

        return response()->json([
            'success' => 1,
            'message' => 'Bulk explanation batch cancelled.',
            'data' => $updated,
        ]);
    }

    private function filterValidationRules(): array
    {
        return [
            'curriculum_board_id' => ['sometimes', 'nullable'],
            'class_id' => ['sometimes', 'nullable'],
            'subject_id' => ['sometimes', 'nullable'],
            'book_id' => ['sometimes', 'nullable'],
            'unit_id' => ['sometimes', 'nullable'],
            'topic_id' => ['sometimes', 'nullable'],
            'question_presentation_type_id' => ['sometimes', 'nullable'],
            'has_diagram' => ['sometimes', 'nullable', 'boolean'],
            'activate' => ['sometimes', 'nullable', 'boolean'],
            'status' => ['sometimes', 'nullable'],
            'missing_filter' => ['sometimes', 'nullable', Rule::in(['missing_en', 'missing_ur', 'missing_both', 'missing_any'])],
            'question_ids' => ['sometimes', 'nullable', 'array'],
            'question_ids.*' => ['integer', 'min:1'],
        ];
    }
}
