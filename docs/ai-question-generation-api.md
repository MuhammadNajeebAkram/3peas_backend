# AI question creation

Base: `/api/admin/auth/question`. These endpoints use the existing admin JWT authentication.
Manual question creation remains available. AI creates persistent review drafts; only
an explicit reviewed save writes questions, answers, options and scenario groups.

## Permissions and routes

| Method | Path | Permission |
| --- | --- | --- |
| POST | `/generate` | `questions.generate` |
| GET | `/generations/{id}` | `questions.generate` |
| POST | `/generations/{id}/update` | `questions.generate` |
| GET | `/generations/{id}/sources/{sourceId}` | `questions.generate` |
| POST | `/generations/{id}/diagram-preview` | `questions.generate` |
| POST | `/generations/{id}/save` | `questions.create` |

Draft/source access is restricted to the creator, including for Super Admin. Role
scopes are checked for generation and rechecked for review and saving. A user who
can generate does not automatically gain permission to create questions. Generation
is limited to five calls per minute, with one in-flight generation per administrator.
Diagram previews are limited to 30 calls per minute.

## Generate from text

```json
{
  "topic_id": 181,
  "question_type": 1,
  "count": 2,
  "language": "both",
  "ai_provider_id": 2,
  "model_id": 7,
  "difficulty": 3,
  "marks": 1,
  "include_diagrams": false,
  "source_text": "Paste the lesson or learning material here.",
  "prompt": "Use everyday examples and test understanding rather than recall."
}
```

IDs above are examples: obtain real IDs from the existing classification/model APIs.

Required: `topic_id`, `question_type`, `count` (1–5), `language` (`en`, `ur`, `both`).
The backend derives unit, book, subject, class and curriculum board from the topic;
clients cannot override the hierarchy. The question type is an active record from
`question_type_tbl`, including custom types. Its `is_mcq` flag determines whether
four options and a correct option or a written model answer are required.

Optional fields:

| Field | Behavior |
| --- | --- |
| `ai_provider_id`, `model_id` | Same provider/model selection as explanation generation; mismatches rejected |
| `source_text` | UTF-8 lesson text, up to 50,000 characters |
| `files[]` | Image, PDF, TXT or ZIP uploads; see below |
| `prompt` | Additional administrator instructions, up to 5,000 characters |
| `difficulty` | Integer 1–5; default 3 |
| `marks` | Integer 1–100; default 1 for MCQ, 2 for written questions |
| `cognitive_domain` | Existing cognitive-domain ID; default 1 |
| `topic_content` | Existing `topic_content_type_tbl` ID |
| `question_presentation_type_id` | Active presentation type |
| `scenario_based` | Default false; shared scenarios require an MCQ type and a presentation type allowing multiple MCQs |
| `include_diagrams` | Default false; allows question, answer, option and scenario diagrams |

The prompt is optional. Without it, the backend builds the task from the selected
context and sources. Without any sources, AI uses context/general knowledge and
returns empty source references. Use lesson sources for curriculum-grounded work.
A batch has one question type; request separate batches for different types.

## Images, PDFs and ZIP bundles

Use `multipart/form-data` with the same fields and ordered `files[]` uploads.
Send booleans as `1`/`0`. Text, images and PDFs may be combined in one request.

```sh
curl -X POST "$BASE/api/admin/auth/question/generate" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Accept: application/json" \
  -F "topic_id=181" -F "question_type=2" -F "count=2" -F "language=en" \
  -F "ai_provider_id=2" -F "model_id=7" \
  -F "prompt=Write short questions with concise model answers." \
  -F "files[]=@page-01.jpg" -F "files[]=@lesson.pdf"
```

- Accepts PNG, JPEG, WebP, PDF and UTF-8 TXT. ZIPs may contain those formats only.
- Maximum 8 MB per upload/expanded file, 12 MB total expanded content, and 10 sources
  including pasted text. Combined text sources are limited to 50,000 characters.
- Images must be no more than 8 million pixels. Use cropped lesson excerpts or
  short PDFs, rather than entire textbooks. PDFs are sent to the selected model
  directly; there is no local OCR or PDF-to-image conversion.
- Upload order is preserved. ZIP entries use natural filename order (`page2` before
  `page10`). ZIP directory paths are never extracted to the filesystem.
- Nested ZIPs, encrypted entries, symlinks, traversal paths, unsupported files and
  excessive expansion are rejected. `__MACOSX` metadata and `.DS_Store` are ignored.
- Images/PDFs require a model marked `supports_images`. All models must also support
  the selected provider's structured JSON generation and relevant file input.
- Sources remain private on the configured source disk. Owner-authenticated download
  links are returned; the provider receives inline data, not a public source URL.
- A source manifest supplies IDs and hashes. Each grounded question cites source IDs
  and, for PDFs, physical page numbers. These are AI-produced references for review,
  not independently verified page citations.

Provider file formats follow the official [OpenAI file-input API](https://developers.openai.com/api/docs/guides/file-inputs)
and [Gemini document-input API](https://ai.google.dev/gemini-api/docs/generate-content/document-processing).

## Generation response and review

HTTP 201 returns `success: 1` and `generation` with:

- `id`, `status: ready`, `ai_request_id`, `provider`, `model_id`, `model_key`.
- Resolved `context`, optional administrator `instructions`, source manifest with
  authenticated `download_url` values, and `created_at`.
- `draft: {warnings: [], scenario: null|object, questions: [...]}`.
- `saved_questions`: an object mapping zero-based draft indices to saved question IDs.

Each question contains these fields, including nulls for unused languages:

```json
{
  "question": "What is the SI unit of force?",
  "question_um": null,
  "answer": null,
  "answer_um": null,
  "explanation": "Force is measured in newtons.",
  "explanation_um": null,
  "question_diagram": null,
  "answer_diagram": null,
  "options": [
    {"text": "Newton", "text_um": null, "is_correct": true, "diagram": null},
    {"text": "Joule", "text_um": null, "is_correct": false, "diagram": null},
    {"text": "Watt", "text_um": null, "is_correct": false, "diagram": null},
    {"text": "Pascal", "text_um": null, "is_correct": false, "diagram": null}
  ],
  "source_references": [{"source_id": "source-1", "page": null, "note": "Force units"}],
  "needs_review": false,
  "review_reason": null
}
```

Written questions have `options: []` and model answers in `answer`/`answer_um`.
A shared `scenario` has `text`, `text_um`, `diagram`, and `source_references`.
The existing shared-scenario data model is MCQ-only. For a written question based
on a passage, use `scenario_based: false` and ask for the passage in the question.

Render text fields as text/LaTeX, not arbitrary HTML. Show warnings and review
reasons prominently. The server validates shape, language fields, answer presence,
distinct options, one correct MCQ answer, source IDs, diagram specifications, and
within-batch duplicates. Human review is still required for correctness and source fidelity.
Unreadable/insufficient sources can produce flagged MCQs with no options or no
marked correct answer. These are review placeholders, not savable questions;
clearing the flag requires completing the normal answer and option validation.

To edit a batch, POST `{"draft": <complete edited draft>}` to
`/generations/{id}/update`. Preserve all fields, array ordering and the original count.
Clear a question's `needs_review` flag only after resolving the issue. Once any
question is saved, batch editing is blocked; use the normal question editor for
saved records or generate a fresh batch.

## Diagrams

Preview any diagram specification with POST `/generations/{id}/diagram-preview`:
`{"diagram": <specification>}`. The response is PNG bytes; the frontend can display
an authenticated fetch response as a Blob URL. This also previews edited diagrams.

Supported diagram specifications:

```json
{
  "kind": "source_crop",
  "alt": "The figure from the uploaded book page",
  "source_id": "source-1",
  "crop": [0.1, 0.2, 0.6, 0.4],
  "elements": []
}
```

`crop` is `[left, top, width, height]`, fractions in 0–1, wholly inside an uploaded
image. PDF figures cannot be cropped directly: upload an image of that page/figure,
or use a simple drawing. A crop is a proposed region and must be visually reviewed.

```json
{
  "kind": "drawing",
  "alt": "A rightward force arrow",
  "source_id": null,
  "crop": [],
  "elements": [
    {"type": "arrow", "x1": 100, "y1": 500, "x2": 800, "y2": 500, "label": null},
    {"type": "text", "x1": 400, "y1": 400, "x2": 400, "y2": 400, "label": "F"}
  ]
}
```

Drawings use at most 60 line, arrow, rectangle, ellipse or text elements. Coordinates
are 0–1000 on an 800×600 canvas; the origin is top-left. Rectangles and ellipses use
opposite bounding-box corners. Text uses `x1,y1` and ASCII labels up to 80 characters.
Urdu explanations remain in the text fields. This renderer creates basic schematics,
graphs and geometry figures; it is not a photographic or anatomical image generator.
Complex figures should use source crops or be flagged for review. Raw SVG/code is
never executed. All saved diagram assets are PNGs.

## Save selected questions

```json
{"reviewed": true, "indices": [0, 2]}
```

POST to `/generations/{id}/save`. Selected questions must be unflagged and valid.
The server checks the current classification and the administrator's create scope.
It saves through the existing question-creation workflow, including answers/options,
audit user fields, presentation type and shared scenario linkage.

All saved questions have **`status: draft` and `activate: false`**. Publishing remains
part of the existing review workflow. Repeating a save returns the existing IDs;
it never creates duplicates. Different subsets of the batch reuse one scenario.
The entire selected batch saves transactionally; failed saves roll back question
records and remove newly uploaded diagram assets. Request history remains intact.

The generation record retains the source manifest, original AI request reference,
edited draft and index-to-question mapping for traceability. Source files persist
with the generation; this version does not automatically expire them.

## Errors

- 401/403: authentication, permission or scope failure.
- 404: unknown generation, another administrator's generation or unknown source.
- 409: concurrent generation, classification changed, failed batch, or editing after save.
- 422: invalid inputs, unsafe uploads, mismatched model/provider, invalid edits or unresolved review flags.
- 502/504: provider failure, invalid AI output, refusal, truncation or connection failure.
- 503: missing provider credentials or required diagram-rendering capability.

Provider failures include `ai_request_id` and, when available, `generation_id`.
Usage is logged as `purpose: question_generation`. Upload bytes and prompts are not
stored in request parameters; private sources and administrator instructions reside
on the generation record/source disk. Refused or malformed output cannot be saved.

## Deployment and verification

```sh
php artisan migrate --path=database/migrations/2026_09_16_000003_create_ai_question_generations_table.php
php artisan db:seed --class=AiPermissionsSeeder
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter='AiAdminCrudTest|QuestionExplanationTest|AiQuestionGenerationTest'
```

Existing provider/model migrations must already be applied. The permission seeder
adds `questions.generate` to Super Admin without replacing other grants. Assign
other roles through the existing permission API.

PHP requires `fileinfo`, `zip`, and `gd` for the corresponding features. Configure
web-server request limits and PHP `upload_max_filesize`/`post_max_size` to accommodate
the documented limits. Do not point the source disk at a public directory.

`AI_QUESTION_MAX_OUTPUT_TOKENS` defaults to 12000 (bounded to 2000–24000), separate
from short explanation limits. Long/bilingual batches may need fewer questions.
`AI_QUESTION_TIMEOUT` defaults to 90 seconds (maximum 90); an explicit provider
`settings.timeout` takes precedence. A connection timeout is logged without an
automatic retry because provider processing and charges may be unknown.
`AI_QUESTION_DIAGRAM_DISK` defaults to `s3`, reusing the existing AWS upload service,
environment prefix and URL handling. For local public storage, set it to `public`
and run `php artisan storage:link`. Private sources use the local disk by default.

The frontend should provide source uploads, classification/provider/model selectors,
an optional prompt, draft editing, source download, diagram preview, and a reviewed
save action. No frontend application is included in this backend repository.
