<?php

namespace App\Http\Controllers;

use App\Exceptions\AiGenerationException;
use App\Http\Services\QuestionExplanationService;
use App\Models\AiRequest;
use App\Models\ExamQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // Shared cache locks protect concurrent clicks while keeping deliberate regeneration possible.
        $lock = Cache::lock('question-explanation:'.$request->user()->id.':'.$data['question_id'], 210);
        abort_unless($lock->get(), 409, 'An explanation is already being generated for this question.');
        try {
            $record = $service->generate($request, $context, $model, $data['language']);

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
        $context = $service->context($data['question_id']);
        $service->authorize($request, $context, 'questions.update');
        foreach ($service->fields($data['language']) as $field) {
            $service->validateExplanation($data[$field], $field);
        }
        $saved = DB::transaction(function () use ($request, $data, $service) {
            $question = ExamQuestion::lockForUpdate()->findOrFail($data['question_id']);
            $record = AiRequest::lockForUpdate()->findOrFail($data['ai_request_id']);
            abort_unless($record->purpose === 'question_explanation' && $record->subject_type === 'exam_question'
                && (int) $record->subject_id === (int) $data['question_id'] && $record->language === $data['language']
                && $record->status === 'successful' && $record->record_source === 'provider', 422, 'The AI draft does not match this question and language.');
            $context = $service->context($data['question_id']);
            $service->authorize($request, $context, 'questions.update');
            abort_unless(hash_equals((string) data_get($record->metadata, 'context_hash', ''), $service->fingerprint($context)),
                409, 'The question or options changed after generation. Generate a new explanation.');
            $hashes = $service->explanationHashes($context);
            foreach ($service->fields($data['language']) as $field) {
                abort_unless(hash_equals((string) data_get($record->metadata, 'explanation_hashes.'.$field, ''), $hashes[$field]),
                    409, 'The saved explanation changed after generation. Reload and generate a new draft.');
                $question->$field = $data[$field];
            }
            $question->save();
            $record->metadata = array_merge($record->metadata ?? [], ['saved_by' => $request->user()->id, 'saved_at' => now()->toIso8601String()]);
            $record->save();

            return $question->only($service->fields($data['language']));
        });

        return response()->json(['success' => 1, 'message' => 'Explanation saved.',
            'data' => array_merge(['question_id' => $data['question_id'], 'language' => $data['language']], $saved)]);
    }
}
