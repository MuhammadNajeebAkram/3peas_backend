# AI administration API

Base URL: `/api/admin/auth`. All endpoints use the existing admin JWT cookie or
bearer token and role permission middleware. CRUD does not call external AI APIs.
The connection-test endpoint contacts the selected provider's model-list API.
See [Question explanations](question-explanation-api.md) for generation and saving.
See [AI question creation](ai-question-generation-api.md) for reviewed question and
answer drafts from text, images, PDFs and ZIP sources.

## Routes and permissions

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/ai-providers/all` | `ai-providers.view` |
| GET | `/ai-providers/{id}` | `ai-providers.view` |
| POST | `/ai-providers/add` | `ai-providers.create` |
| POST | `/ai-providers/update/{id}` | `ai-providers.update` |
| DELETE | `/ai-providers/delete/{id}` | `ai-providers.delete` |
| POST | `/ai-providers/test/{id}` | `ai-providers.test` |
| GET | `/ai-models/all` | `ai-models.view` |
| GET | `/ai-models/active` | `ai-models.view` |
| GET | `/ai-models/{id}` | `ai-models.view` |
| POST | `/ai-models/add` | `ai-models.create` |
| POST | `/ai-models/update/{id}` | `ai-models.update` |
| DELETE | `/ai-models/delete/{id}` | `ai-models.delete` |
| GET | `/ai-requests/all` | `ai-requests.view` |
| GET | `/ai-requests/{id}` | `ai-requests.view` |
| POST | `/ai-requests/add` | `ai-requests.create` |
| POST | `/ai-requests/update/{id}` | `ai-requests.update` |
| DELETE | `/ai-requests/delete/{id}` | `ai-requests.delete` |

Success detail responses contain `success: 1` and `ai_provider`, `ai_model` or `ai_request`.
Create returns HTTP 201; other successful operations return 200. Validation
returns 422, missing/deleted records 404, missing authentication 401, and missing
permissions 403. Updates are partial: omitted fields retain their values.

## Providers

The migration creates OpenAI (enabled) and Gemini (disabled), maps existing models
including soft-deleted rows to provider IDs, and preserves request-history snapshots.
Unknown legacy providers are preserved disabled, without an adapter.

Example POST `/ai-providers/update/{id}`:

```json
{
  "name": "Google Gemini",
  "is_active": true,
  "settings": {"timeout": 45, "max_output_tokens": 1800}
}
```

Create requires `key` (`openai` or `gemini`, unique) and `name`. The key is immutable.
Only installed adapters may be created; a database row does not install an integration.
`settings` allows only `timeout` (1–90 seconds) and `max_output_tokens` (500–4000).
Supplying settings replaces the settings object; null resets to environment defaults.
API keys and arbitrary API URLs are not accepted. Configure `OPENAI_API_KEY` (or
the existing `OPEN_AI_API_KEY`) and `GEMINI_API_KEY` through `config/services.php`.
Responses include `is_supported` and `is_configured` booleans, never credentials.

`GET /ai-providers/all` supports `search` (name/key), `is_active`, `page` and
`per_page` (1–100, default 25). It returns an `ai_providers` paginator and
`supported_providers` keys. List/detail responses include `models_count`.

Disabling the provider of the default model returns 422: first switch or clear
the default model. Deletion returns 409 if any models, including soft-deleted
ones, reference the provider; the foreign key also restricts deletion.

`POST /ai-providers/test/{id}` is limited to 10 requests per minute and has its
own permission. It works on disabled providers so admins can test before enabling.
Missing credentials/adapter returns 422; upstream failures return 502 with a
sanitized message and, when available, `http_status`. Success verifies model-list
access only, not generation access to a particular model. It does not create a
generation usage record or incur a generation request.

## Models

Example POST `/ai-models/add`:

```json
{
  "ai_provider_id": 1,
  "name": "GPT-5.4 Mini",
  "model_key": "gpt-5.4-mini",
  "description": "Default explanation model",
  "is_active": true,
  "is_default": true,
  "currency": "USD"
}
```

Required fields: `ai_provider_id`, `name`, `model_key`. Obtain the provider ID
from `/ai-providers/all`; do not assume IDs. The old `provider` input is rejected.
Responses include the nested `provider` relationship. Optional prices are
`input_price_per_million`, `cached_input_price_per_million`, and
`output_price_per_million`. Enter verified prices; null means unknown, not free.
`supports_images` is a boolean (default true); set false for text-only models.
Currency is three uppercase letters. Prices and costs serialize as decimal strings.

Provider/model-key pairs are unique, including soft-deleted models. Adding a
model configures the application; it does not verify provider account access.
Setting `is_default: true` unsets the previous default in a transaction. A unique
database constraint prevents multiple defaults. The default must be active; to
deactivate it, submit both `is_active: false` and `is_default: false`. No default
is also allowed. Deleting a default clears its default flag.

GET `/ai-models/all` accepts `search` (name/key), `ai_provider_id`, `is_active` (0/1),
`page`, and `per_page` (1–100, default 25). The response's `ai_models` property
is a Laravel paginator. `/active` returns an array of active models whose providers
are enabled, supported and configured. A default model requires an active supported
provider. Missing credentials prevent generation even if the model is default.

## Request records

Example POST `/ai-requests/add`:

```json
{
  "ai_model_id": 1,
  "purpose": "question_explanation",
  "subject_type": "exam_question",
  "subject_id": 123,
  "language": "en",
  "prompt_version": "explanation-v1",
  "status": "pending",
  "request_parameters": {"max_output_tokens": 1000},
  "metadata": {"notes": "Manual tracking record"}
}
```

Only `ai_model_id` and `purpose` are required. The model must be active.
`subject_type` and `subject_id` must both be populated or both null; they are
generic references, not foreign keys or dynamically resolved PHP class names.
They may identify any future feature's record. Omit them for standalone tasks.

The backend sets `user_id` from the authenticated admin, `trigger_type` to `user`,
`record_source` to `admin`, `environment` from application configuration, and
captures provider, requested model, and pricing snapshot from the chosen model.
These fields cannot be supplied or changed through this API. Model selection,
operation ID, and attempt number are immutable after creation.

`operation_id` defaults to a generated UUID and `attempt_number` to 1. Supply the
same operation UUID with a new attempt number for a retry. The pair is unique,
including deleted records. Regenerations should normally use new operation IDs.

Optional result fields on creation/update:

- `status`: `pending`, `successful`, `failed`, `incomplete`, `refused`, `cancelled`.
- `response_status`, `returned_model`, `response_payload` (JSON object/array).
- `input_tokens`, `cached_input_tokens`, `output_tokens`, `reasoning_tokens`,
  `total_tokens`: nonnegative integers or null.
- `estimated_cost`: nonnegative decimal or null; this CRUD API does not calculate
  or verify charges. `pricing_snapshot` preserves the original model prices.
- `provider_request_id`, `provider_response_id`, `duration_ms`, `http_status`.
- `error_code`, `error_message`, `completed_at` (date/time).

When supplied, total tokens must equal input plus output tokens. Cached input is
a subset of input; reasoning is a subset of output. Unavailable usage stays null.
A pending record cannot have a completion time.

GET `/ai-requests/all` accepts exact filters `user_id`, `ai_model_id`, `purpose`,
`subject_type`, `subject_id`, `provider`, `status`, `operation_id`, inclusive
`date_from`/`date_to` (`YYYY-MM-DD`), and `page`/`per_page` (1–100, default 25).
The `ai_requests` property is a Laravel paginator. Lists exclude large JSON
request parameters, metadata, and response payloads; detail responses include them.

## History and security

Both DELETE endpoints soft-delete rows. Standard lists/details omit deleted
rows. Deleting models preserves associated request history and model snapshots.
Hard deletion of a referenced user or model nulls its foreign key without deleting
the request. Request model relationships can still load soft-deleted models.

Request CRUD permits privileged corrections to manually created records. These records are explicitly
marked `record_source: admin`; they are not verified provider telemetry or an
immutable billing ledger. Existing admin activity middleware records successful
mutations when its log table is installed. Restrict create/update/delete permissions
to trusted administrators. Future provider integration should log directly through
the Eloquent model/shared service and set `record_source: provider`.

Do not submit secrets in payloads or free text. Nested credential/header keys are
rejected, but free text cannot be guaranteed secret-free. API credentials remain
in server configuration. Question generation, automatic usage logging, cost
estimation, and saving reviewed explanations are described in the
[question explanation API guide](question-explanation-api.md).

## Installation

Run only these feature migrations if other pending project migrations should wait:

```sh
php artisan migrate --path=database/migrations/2026_09_15_000001_create_ai_models_table.php --path=database/migrations/2026_09_15_000002_create_ai_requests_table.php --path=database/migrations/2026_09_16_000001_create_ai_providers_table.php --path=database/migrations/2026_09_16_000002_add_ai_model_image_capability.php
php artisan db:seed --class=AiPermissionsSeeder
```

The targeted seeder is idempotent and grants twelve CRUD permissions plus
`ai-providers.test`, `questions.generate-explanation` and `questions.generate` to `super_admin`
without removing existing grants. `LmsPermissionsSeeder` also includes these
permissions for normal project setup. Assign other roles through the existing
role-permission API. Model records/prices are managed by the admin CRUD API.

Deploy the API and admin client change together: model writes now require
`ai_provider_id`, and the `provider` response is an object. The migration preserves
existing models and the selected default; it does not run `AiModelSeeder` or change
model pricing. `AiProviderSeeder` can restore missing supported provider entries
without changing existing settings. Gemini starts disabled with no seeded models:
configure its key, enable the provider, and add model IDs available to your account
with verified pricing. There is no automatic cross-provider fallback.

Provider-generated records cannot be edited through CRUD (409). Generation now
records provider attempts automatically. The seeder also adds
`questions.generate-explanation`; see the explanation API guide for required permissions.

## Verification

Tests use only an isolated in-memory SQLite database:

```sh
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit tests/Feature/AiAdminCrudTest.php
```

Omit the extension flag when PDO SQLite is already enabled.
