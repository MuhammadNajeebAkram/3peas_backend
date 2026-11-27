<?php

namespace App\Http\Controllers;

use App\Exceptions\AiGenerationException;
use App\Http\Services\QuestionExplanationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class QuestionExplanationController extends Controller
{
    public function generate(Request $request, QuestionExplanationService $service)
    {
        $data = $request->validate([
            'question_id' => ['required', 'integer', 'min:1'],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_provider_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:ai_providers,id'],
            'language' => ['required', Rule::in(['en', 'ur', 'both'])],
        ]);
        $context = $service->context($data['question_id']);
        $service->authorize($request, $context, 'questions.view', 'questions.generate-explanation');
        $model = $service->model($data['model_id'] ?? null, $data['ai_provider_id'] ?? null);
        // Global question-level cache lock prevents concurrent single/bulk generation for the same question.
        $lock = Cache::lock(
            QuestionExplanationService::questionLockKey((int) $data['question_id']),
            QuestionExplanationService::QUESTION_LOCK_SECONDS
        );
        abort_unless($lock->get(), 409, 'An explanation is already being generated for this question.');
        try {
            $record = $service->generate($request, $context, $model, $data['language'], 'single');

            return response()->json(['success' => 1, 'data' => array_merge([
                'ai_request_id' => $record->id, 'question_id' => $data['question_id'],
                'model_id' => $model->id, 'language' => $data['language'],
                'ai_provider_id' => $model->ai_provider_id, 'provider' => $record->provider,
            ], $record->response_payload)]);
        } catch (AiGenerationException $exception) {
            return response()->json(['success' => 0, 'message' => $exception->getMessage(),
                'ai_request_id' => $exception->requestId], $exception->httpStatus);
        } finally {
            $lock->release();
        }
    }

    public function save(Request $request, QuestionExplanationService $service)
    {
        $data = $request->validate([
            'question_id' => ['required', 'integer', 'min:1'],
            'ai_request_id' => ['required', 'integer', 'min:1'],
            'language' => ['required', Rule::in(['en', 'ur', 'both'])],
            'explanation' => [Rule::requiredIf(in_array($request->input('language'), ['en', 'both'], true)),
                Rule::prohibitedIf($request->input('language') === 'ur'), 'string', 'max:350'],
            'explanation_um' => [Rule::requiredIf(in_array($request->input('language'), ['ur', 'both'], true)),
                Rule::prohibitedIf($request->input('language') === 'en'), 'string', 'max:350'],
        ]);
        $context = $service->context((int) $data['question_id']);
        $service->authorize($request, $context, 'questions.update');
        $saved = $service->saveApprovedDraft(
            $request->user(),
            (int) $data['question_id'],
            (int) $data['ai_request_id'],
            $data['language'],
            $data
        );

        return response()->json(['success' => 1, 'message' => 'Explanation saved.',
            'data' => array_merge(['question_id' => $data['question_id'], 'language' => $data['language']], $saved)]);
    }
}
