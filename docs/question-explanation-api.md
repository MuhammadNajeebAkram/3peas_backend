# Admin question explanations

## Permissions

Both endpoints require the existing admin JWT cookie or bearer token.

| Method | Endpoint | Permissions |
| --- | --- | --- |
| POST | `/api/admin/auth/question/generate-explanation` | `questions.view` **and** `questions.generate-explanation` |
| POST | `/api/admin/auth/question/save-explanation` | `questions.update` |

The existing role scopes for subject, class, book, topic, etc. apply to each
required question permission. The model dropdown uses the existing
`GET /api/admin/auth/ai-models/active` endpoint (`ai-models.view`).

Install the new generation permission with:

```sh
php artisan db:seed --class=AiPermissionsSeeder
```

It is granted to Super Admin; assign it to other roles through the permission API.
No additional database migration is needed for explanations.

## Configuration

```dotenv
OPEN_AI_API_KEY=your-server-side-key
OPENAI_TIMEOUT=45
OPENAI_MAX_OUTPUT_TOKENS=1800
```

`OPENAI_API_KEY` is also accepted if `OPEN_AI_API_KEY` is absent. Keys are read
through `config/services.php`, never returned or stored in AI request logs.
Rebuild Laravel's config cache after changing environment settings in deployments
that use cached configuration.

## Generate

```json
{
  "question_id": 123,
  "model_id": 1,
  "language": "both"
}
```

- `language` is required: `en`, `ur`, or `both`.
- `model_id` is optional: omitted/null uses the active default OpenAI model.
- The backend loads the saved question, options, marked answer, subject, class,
  topic, and shared scenario. Save edits to the question/options before generating.
- Editor equations stored in `data-latex`/`data-tex` attributes or Quill's
  `ql-formula` nodes are extracted as LaTeX before removing HTML. This applies to
  questions, options, and scenarios in both languages; rendered math previews
  are replaced by the source formula once. Raw LaTeX delimiters are preserved.
- Only MCQs with at least two nonempty options and exactly one correct answer
  are accepted. Missing required context is rejected before an API call.
- Statement/scenario/option images in HTTPS URLs or HTML `<img src>` are sent
  as image inputs. URLs must be accessible to OpenAI. SVG and relative paths are
  rejected. Questions marked as requiring diagrams need an available image.

Example successful response (illustrative IDs):

```json
{
  "success": 1,
  "data": {
    "ai_request_id": 456,
    "question_id": 123,
    "model_id": 1,
    "language": "both",
    "explanation": "Using \\(F = ma\\), the force is \\(2 \\times 3 = 6\\,\\text{N}\\).",
    "explanation_um": "فارمولے \\(F = ma\\) کے مطابق قوت \\(6\\,\\text{N}\\) ہے۔",
    "needs_review": false,
    "review_reason": null
  }
}
```

For `en`, only `explanation` is returned. For `ur`, only `explanation_um` is
returned. Prompt version `question-explanation-v3` identifies math-aware context extraction and asks for natural, schoolteacher-style
writing matched to the question's class/grade and subject, using familiar words and
clear everyday Urdu when requested. If the class is missing, it uses general
school-level language. It avoids robotic introductions, unnecessary jargon, and
literal English-to-Urdu phrasing while preserving factual accuracy. Tone is a model
instruction and remains subject to admin review.

Each explanation is instructed to use 1–2 short sentences. The backend
enforces at most 350 characters, 40 whitespace-separated words, and two text
lines per language. Physical wrapping still depends on frontend width and font;
do not truncate rendered math to force exactly two visual lines.

Only the correct answer is explained. Incorrect-option explanations are not
requested or returned. Question content is treated as data, not model instructions.
If the marked answer appears incorrect or ambiguous, a successful generation can
return `needs_review: true`, a brief `review_reason`, and null explanation fields.
The administrator must review the issue and supply an appropriate explanation
before saving, or correct the question and regenerate.

### LaTeX

Explanations contain plain text and LaTeX, without HTML or Markdown code fences.
Use MathJax/KaTeX with inline delimiters `\(` / `\)` and display delimiters
`\[` / `\]`. JSON doubles backslashes; JSON parsing restores the original
LaTeX. Store and submit the parsed strings without manual escaping or unescaping.
Backend validation checks paired delimiters; the frontend renders the equations.

### Request logging and failures

Every outbound attempt creates an `ai_requests` row before contacting OpenAI:

- `purpose: question_explanation`, `subject_type: exam_question`, related question ID.
- Authenticated `user_id`, selected model, language, prompt version, pricing snapshot.
- `record_source: provider`, operation UUID, attempt number, duration and provider IDs.
- Reported input/cached/output/reasoning/total tokens, estimated cost, result status.
- Generated draft, context fingerprint, original explanation hashes, and usage detail.

The generation uses the Responses API with strict JSON schema and `store: false`.
No API authorization headers or raw question/image inputs are saved in the log.
The log stores the generated draft and usage, not OpenAI's full raw response.

Generation is limited to 10 requests per minute per authenticated user. A shared
cache lock rejects concurrent generation for the same user/question. Configure a
shared Laravel cache store across backend instances. The frontend should disable
Generate while a request is pending. Deliberate regeneration makes a new operation.

HTTP 429 and 5xx responses are retried once with a short delay. Each attempt has
its own log row under the same operation UUID. Timeouts are not automatically
retried because provider processing/charges may be unknown. Missing usage stays
null. Estimates use the saved prices, counting cached tokens within input and
reasoning tokens within output. Estimates remain null if usage/prices are missing
or separate cache-write billing is reported; they are not billing invoices.

Errors:

- 401: unauthenticated; 403: missing permission or outside assigned question scopes.
- 404: question/request not found; 422: invalid input or unavailable model/context.
- 409: generation already running or stale/mismatched saved content.
- 429: application generation rate limit.
- 503: API key missing; 504: provider connection timeout/error.
- 502: provider rejected/failed/refused/incomplete request or invalid generated format.

Provider failures include `ai_request_id` in the error response. They do not
return raw provider errors or save partial output as a question explanation.

## Review and save

Show the generated text in editable fields. Generation never changes the question.

```json
{
  "question_id": 123,
  "ai_request_id": 456,
  "language": "both",
  "explanation": "Using \\(F = ma\\), the force is \\(6\\,\\text{N}\\).",
  "explanation_um": "فارمولے \\(F = ma\\) کے مطابق قوت \\(6\\,\\text{N}\\) ہے۔"
}
```

Saving requires a successful provider draft for the same question and exact
language selection. Send only `explanation` for `en`, only `explanation_um` for
`ur`, or both fields for `both`. The other language is preserved. The same length
and LaTeX rules apply to admin edits.

The backend rejects saving if the question/options/context or relevant saved
explanation changed after generation. Reload and generate a new draft after a
409. Saving records `saved_by` and `saved_at` in the request metadata and retains
the original generated draft. Provider logs cannot be edited through generic CRUD.

## Tests

```sh
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit tests/Feature/QuestionExplanationTest.php tests/Feature/AiAdminCrudTest.php
```

Tests use an isolated in-memory database and fake provider responses, covering
language selection, LaTeX, permissions/scopes, snapshots, cost, retries, timeouts,
refusals, missing context, stale drafts, and saving each language.
