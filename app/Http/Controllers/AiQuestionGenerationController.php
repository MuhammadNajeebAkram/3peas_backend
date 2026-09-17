<?php

namespace App\Http\Controllers;

use App\Exceptions\AiGenerationException;
use App\Http\Services\AiQuestionDiagramService;
use App\Http\Services\AiQuestionGenerationService;
use App\Models\AiQuestionGeneration;
use App\Models\AiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AiQuestionGenerationController extends Controller
{
    public function generate(Request $request, AiQuestionGenerationService $service)
    {
        $data = $request->validate([
            'topic_id' => ['required', 'integer', 'min:1'],
            'question_type' => ['required', 'integer', 'min:1'],
            'question_presentation_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'scenario_based' => ['sometimes', 'boolean'],
            'count' => ['required', 'integer', 'between:1,'.config('ai_questions.max_questions')],
            'language' => ['required', Rule::in(['en', 'ur', 'both'])],
            'difficulty' => ['sometimes', 'integer', 'between:1,5'],
            'marks' => ['sometimes', 'integer', 'between:1,100'],
            'cognitive_domain' => ['sometimes', 'integer', 'exists:cognitive_domain_tbl,id'],
            'topic_content' => ['sometimes', 'nullable', 'integer', 'exists:topic_content_type_tbl,id'],
            'ai_provider_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_providers,id'],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'prompt' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source_text' => ['sometimes', 'nullable', 'string', 'max:'.config('ai_questions.max_text_chars')],
            'include_diagrams' => ['sometimes', 'boolean'],
            'files' => ['sometimes', 'array', 'max:'.config('ai_questions.max_files')],
            'files.*' => ['required', 'file', 'max:'.(int) (config('ai_questions.max_file_bytes') / 1024)],
        ]);
        $lock = Cache::lock('ai-question-generation:'.$request->user()->id, 210);
        abort_unless($lock->get(), 409, 'Question generation is already in progress.');
        try {
            $generation = $service->generate($request, $data);

            return response()->json(['success' => 1, 'generation' => $this->representation($generation)], 201);
        } catch (AiGenerationException $exception) {
            return response()->json(['success' => 0, 'message' => $exception->getMessage(),
                'ai_request_id' => $exception->requestId,
                'generation_id' => AiQuestionGeneration::where('user_id', $request->user()->id)->where('ai_request_id', $exception->requestId)->value('id'),
            ], $exception->httpStatus);
        } finally {
            $lock->release();
        }
    }

    public function show(Request $request, int $id, AiQuestionGenerationService $service)
    {
        return response()->json(['success' => 1, 'generation' => $this->representation($service->owned($request, $id))]);
    }

    public function update(Request $request, int $id, AiQuestionGenerationService $service)
    {
        $generation = $service->owned($request, $id);
        $data = $request->validate(['draft' => ['required', 'array:scenario,questions,warnings']]);

        return response()->json(['success' => 1, 'generation' => $this->representation($service->edit($generation, $data['draft']))]);
    }

    public function save(Request $request, int $id, AiQuestionGenerationService $service)
    {
        $generation = $service->owned($request, $id, 'questions.create');
        $data = $request->validate([
            'reviewed' => ['required', 'accepted'],
            'indices' => ['required', 'array', 'min:1', 'max:'.config('ai_questions.max_questions')],
            'indices.*' => ['required', 'integer', 'min:0', 'distinct'],
        ]);

        return response()->json(['success' => 1, 'saved_questions' => (object) $service->save($request, $generation, $data['indices']),
            'message' => 'Selected questions saved as inactive drafts.']);
    }

    public function source(Request $request, int $id, string $sourceId, AiQuestionGenerationService $service)
    {
        $generation = $service->owned($request, $id);
        $source = collect($generation->sources)->firstWhere('id', $sourceId);
        abort_unless($source, 404);

        return Storage::disk($source['disk'])->download($source['path'], $sourceId.'.'.match ($source['mime']) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'txt',
        }, ['Content-Type' => $source['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function previewDiagram(Request $request, int $id, AiQuestionGenerationService $service, AiQuestionDiagramService $diagrams)
    {
        $generation = $service->owned($request, $id);
        $data = $request->validate(['diagram' => ['required', 'array:kind,alt,source_id,crop,elements']]);

        return response($diagrams->render($data['diagram'], $generation->sources))->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function representation(AiQuestionGeneration $generation): array
    {
        $data = $generation->only(['id', 'ai_request_id', 'status', 'context', 'instructions', 'draft', 'scenario_group_id', 'created_at']);
        $record = $generation->ai_request_id ? AiRequest::withTrashed()->find($generation->ai_request_id) : null;
        $data['provider'] = $record?->provider;
        $data['model_id'] = $record?->ai_model_id;
        $data['model_key'] = $record?->requested_model;
        $data['saved_questions'] = (object) ($generation->saved_questions ?? []);
        $data['sources'] = collect($generation->sources)->map(fn ($source) => collect($source)->except(['path', 'disk'])->all() + [
            'download_url' => url('/api/admin/auth/question/generations/'.$generation->id.'/sources/'.$source['id']),
        ])->all();

        return $data;
    }
}
