<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiQuestionDraftService
{
    public function __construct(private AiQuestionDiagramService $diagrams) {}

    public function schema(): array
    {
        $string = ['type' => ['string', 'null']];
        $diagram = $this->object([
            'kind' => ['type' => 'string', 'enum' => ['drawing', 'source_crop']], 'alt' => ['type' => 'string'],
            'source_id' => $string, 'crop' => ['type' => 'array', 'items' => ['type' => 'number']],
            'elements' => ['type' => 'array', 'items' => $this->object([
                'type' => ['type' => 'string', 'enum' => ['line', 'arrow', 'rectangle', 'ellipse', 'text']],
                'x1' => ['type' => 'number'], 'y1' => ['type' => 'number'],
                'x2' => ['type' => 'number'], 'y2' => ['type' => 'number'], 'label' => $string,
            ])],
        ]);
        $diagram['type'] = ['object', 'null'];
        $references = ['type' => 'array', 'items' => $this->object([
            'source_id' => ['type' => 'string'], 'page' => ['type' => ['integer', 'null']], 'note' => ['type' => 'string'],
        ])];
        $scenario = $this->object(['text' => $string, 'text_um' => $string, 'diagram' => $diagram, 'source_references' => $references]);
        $scenario['type'] = ['object', 'null'];

        return $this->object([
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            'scenario' => $scenario,
            'questions' => ['type' => 'array', 'items' => $this->object([
                'question' => $string, 'question_um' => $string, 'answer' => $string, 'answer_um' => $string,
                'explanation' => $string, 'explanation_um' => $string,
                'question_diagram' => $diagram, 'answer_diagram' => $diagram,
                'options' => ['type' => 'array', 'items' => $this->object([
                    'text' => $string, 'text_um' => $string, 'is_correct' => ['type' => 'boolean'], 'diagram' => $diagram,
                ])],
                'source_references' => $references, 'needs_review' => ['type' => 'boolean'], 'review_reason' => $string,
            ])],
        ]);
    }

    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function validate(mixed $draft, array $context, array $sources): void
    {
        if (! is_array($draft)) {
            $this->invalid('The question draft must be an object.');
        }
        Validator::make($draft, [
            'questions' => ['required', 'array', 'size:'.$context['count']],
            'questions.*' => ['required', 'array:question,question_um,answer,answer_um,explanation,explanation_um,question_diagram,answer_diagram,options,source_references,needs_review,review_reason'],
            'scenario' => ['present', 'nullable', 'array:text,text_um,diagram,source_references'],
            'warnings' => ['present', 'array', 'max:10'], 'warnings.*' => ['string', 'max:1000'],
        ])->validate();
        if (! array_is_list($draft['questions'])) {
            $this->invalid('Questions must be an ordered list.');
        }
        if ((bool) $context['scenario_based'] !== ($draft['scenario'] !== null)) {
            $this->invalid('The scenario must match the requested presentation.');
        }
        if ($draft['scenario'] !== null) {
            $this->textPair($draft['scenario'], 'text', 'text_um', $context['language'], true, 12000);
            $this->diagram($draft['scenario'], 'diagram', $sources, $context);
            $this->references($draft['scenario']['source_references'] ?? null, $sources);
        }
        $seen = [];
        foreach ($draft['questions'] as $question) {
            $flagged = ($question['needs_review'] ?? false) === true;
            Validator::make($question, [
                'needs_review' => ['required', 'boolean'], 'review_reason' => ['present', 'nullable', 'string', 'max:1000'],
                'options' => ['present', 'array', $context['is_mcq'] ? ($flagged ? 'max:4' : 'size:4') : 'size:0'],
                'options.*' => ['array:text,text_um,is_correct,diagram'],
                'options.*.is_correct' => ['required', 'boolean'],
            ])->validate();
            if (! is_bool($question['needs_review'])) {
                $this->invalid('needs_review must be a JSON boolean.');
            }
            if ($question['needs_review'] && blank($question['review_reason'])) {
                $this->invalid('Flagged questions must explain what needs review.');
            }
            $this->textPair($question, 'question', 'question_um', $context['language'], true, 12000);
            $this->textPair($question, 'answer', 'answer_um', $context['language'], ! $context['is_mcq'] && ! $question['needs_review'], 24000);
            $this->textPair($question, 'explanation', 'explanation_um', $context['language'], $context['is_mcq'] && ! $question['needs_review'], 2000);
            $this->diagram($question, 'question_diagram', $sources, $context);
            $this->diagram($question, 'answer_diagram', $sources, $context);
            $this->references($question['source_references'] ?? null, $sources);
            $identity = mb_strtolower(trim(($question['question'] ?? '').'|'.($question['question_um'] ?? '')));
            if (! $flagged && isset($seen[$identity])) {
                $this->invalid('Duplicate questions were generated. Regenerate or edit the drafts.');
            }
            $seen[$identity] = true;
            if ($context['is_mcq']) {
                $correctCount = count(array_filter($question['options'], fn ($option) => ($option['is_correct'] ?? null) === true));
                if ((! $flagged && $correctCount !== 1) || ($flagged && ($correctCount > 1 || ! in_array(count($question['options']), [0, 4], true)))) {
                    $this->invalid('Each MCQ must have exactly one correct option.');
                }
                $optionsSeen = [];
                foreach ($question['options'] as $option) {
                    if (! is_bool($option['is_correct'])) {
                        $this->invalid('is_correct must be a JSON boolean.');
                    }
                    $this->diagram($option, 'diagram', $sources, $context);
                    $this->textPair($option, 'text', 'text_um', $context['language'], $option['diagram'] === null, 3000);
                    foreach (array_filter([$context['language'] !== 'ur' ? 'text' : null, $context['language'] !== 'en' ? 'text_um' : null]) as $field) {
                        $key = $field.'|'.mb_strtolower(trim($option[$field] ?? '')).'|'.json_encode($option['diagram']);
                        if (isset($optionsSeen[$key])) {
                            $this->invalid('MCQ options must be distinct in each requested language.');
                        }
                        $optionsSeen[$key] = true;
                    }
                }
            }
        }
    }

    private function textPair(array $item, string $en, string $ur, string $language, bool $required, int $max): void
    {
        foreach ([$en => $language !== 'ur', $ur => $language !== 'en'] as $field => $requested) {
            if (! array_key_exists($field, $item)) {
                $this->invalid('Missing draft field: '.$field);
            }
            $value = $item[$field];
            if ((! $requested && $value !== null) || ($required && $requested && blank($value))
                || ($value !== null && (! is_string($value) || mb_strlen($value) > $max))) {
                $this->invalid('Invalid or missing '.$field.' for the requested language.');
            }
            if (is_string($value) && (preg_match('/<\/?[a-z][^>]*>/i', $value) || str_contains($value, '```') || str_contains($value, "\0"))) {
                $this->invalid('Draft text must be plain text with LaTeX, not HTML or code blocks.');
            }
        }
    }

    private function diagram(array $item, string $field, array $sources, array $context): void
    {
        if (! array_key_exists($field, $item) || ($item[$field] !== null && ! is_array($item[$field]))) {
            $this->invalid('Invalid diagram field: '.$field);
        }
        if (! $context['include_diagrams'] && $item[$field] !== null) {
            $this->invalid('Diagrams were not requested.');
        }
        $this->diagrams->validate($item[$field], $sources);
    }

    private function references(mixed $references, array $sources): void
    {
        Validator::make(['references' => $references], [
            'references' => ['present', 'array', 'max:20', $sources ? 'min:1' : 'size:0'],
            'references.*' => ['array:source_id,page,note'],
            'references.*.source_id' => ['required', Rule::in(array_column($sources, 'id'))],
            'references.*.page' => ['present', 'nullable', 'integer', 'between:1,1000'],
            'references.*.note' => ['required', 'string', 'max:500'],
        ])->validate();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['draft' => $message]);
    }
}
