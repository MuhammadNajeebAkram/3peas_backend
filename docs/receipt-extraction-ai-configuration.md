# Receipt Extraction AI — Provider, Model & Credential Configuration

This document describes how the environment-aware, Admin-controlled AI provider and model system works for **Student Payment Receipt Extraction** in The Students Times LMS.

## Architecture Overview

```text
Receipt Upload → File & Magic-Byte Validation → Local OCR (Tesseract / Poppler)
→ Read ai_feature_settings (scoped by feature='receipt_extraction' + APP_ENV)
→ Resolve Provider Adapter (OpenAI | Gemini | Qwen)
→ Resolve Environment-Aware API Credential
→ Provider Adapter Structured Extraction
→ Backend Normalization & Schema Validation
→ LMS Auto-Fill (editable by student; existing payment submission unchanged)
```

AI extraction is strictly read-only assistance for form auto-fill. It never creates payment requests, approves payments, posts ledger entries, or activates subscriptions.

---

## Standardized Environment-Aware Credential Resolution

`ReceiptExtractionCredentialResolver` dynamically constructs the required environment variable name from:
1. Runtime environment (`config('app.env')`) — never editable from Admin UI or database.
2. AI provider key from the `ai_providers` database table (normalized to uppercase, validated against `^[a-z][a-z0-9_]{1,49}$`).
3. Centrally registered AI feature key (`receipt_extraction` → `RECEIPT_EXTRACTION`).

### Naming Convention

- **Development** (`APP_ENV` in `local`, `development`, `testing`): `AI_{PROVIDER}_DEV_KEY` (shared across all AI features)
- **Staging** (`APP_ENV=staging`): `AI_{PROVIDER}_{FEATURE}_STAGING_KEY` (feature-specific)
- **Production** (`APP_ENV=production`): `AI_{PROVIDER}_{FEATURE}_PROD_KEY` (feature-specific)
- **Unknown `APP_ENV`**: fails closed with `503 UNSUPPORTED_ENVIRONMENT`.

### Resolution Matrix (Receipt Extraction)

| Environment | Provider | Feature | Resolved Variable |
| :--- | :--- | :--- | :--- |
| Development | `openai` | `receipt_extraction` | `AI_OPENAI_DEV_KEY` |
| Development | `gemini` | `receipt_extraction` | `AI_GEMINI_DEV_KEY` |
| Development | `qwen` | `receipt_extraction` | `AI_QWEN_DEV_KEY` |
| Staging | `openai` | `receipt_extraction` | `AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY` |
| Staging | `gemini` | `receipt_extraction` | `AI_GEMINI_RECEIPT_EXTRACTION_STAGING_KEY` |
| Staging | `qwen` | `receipt_extraction` | `AI_QWEN_RECEIPT_EXTRACTION_STAGING_KEY` |
| Production | `openai` | `receipt_extraction` | `AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY` |
| Production | `gemini` | `receipt_extraction` | `AI_GEMINI_RECEIPT_EXTRACTION_PROD_KEY` |
| Production | `qwen` | `receipt_extraction` | `AI_QWEN_RECEIPT_EXTRACTION_PROD_KEY` |

There is **no cross-environment fallback** between Development, Staging, and Production.

### Legacy Key Migration Guide

| Previous Variable | New Standardized Variable |
| :--- | :--- |
| `OPEN_AI_API_DEV_KEY` | `AI_OPENAI_DEV_KEY` |
| `GEMINI_API_DEV_KEY` | `AI_GEMINI_DEV_KEY` |
| `QWEN_API_DEV_KEY` | `AI_QWEN_DEV_KEY` |
| `OPEN_AI_STAGING_RECEIPT_EXTRACTION_KEY` | `AI_OPENAI_RECEIPT_EXTRACTION_STAGING_KEY` |
| `GEMINI_STAGING_RECEIPT_EXTRACTION_KEY` | `AI_GEMINI_RECEIPT_EXTRACTION_STAGING_KEY` |
| `QWEN_STAGING_RECEIPT_EXTRACTION_KEY` | `AI_QWEN_RECEIPT_EXTRACTION_STAGING_KEY` |
| `OPEN_AI_RECEIPT_EXTRACTION_KEY` | `AI_OPENAI_RECEIPT_EXTRACTION_PROD_KEY` |
| `GEMINI_RECEIPT_EXTRACTION_KEY` | `AI_GEMINI_RECEIPT_EXTRACTION_PROD_KEY` |
| `QWEN_RECEIPT_EXTRACTION_KEY` | `AI_QWEN_RECEIPT_EXTRACTION_PROD_KEY` |

---

## Database-Driven Provider & Model Selection

Provider and model dropdowns in the Admin Panel are populated directly from the existing `ai_providers` and `ai_models` tables (`ai_models.ai_provider_id -> ai_providers.id`):
- Only active `ai_providers` rows whose `key` is supported by `ReceiptExtractionProviderFactory` (`openai`, `gemini`, `qwen`) are selectable.
- Only active, non-deleted `ai_models` rows linked to the selected provider are selectable.
- Both Admin save/test endpoints and runtime extraction validate provider/model existence, active status, and relationship on the backend.

---

## Database Configuration & Environment Isolation

Settings are stored in `ai_feature_settings` with a unique key on `(feature, environment)`:
- `feature`: `receipt_extraction`
- `environment`: `development` or `production` (derived strictly from `APP_ENV`)
- `provider`: `openai`, `gemini`, or `qwen`
- `model`: provider-specific model ID (e.g., `gpt-6-luna`, `gemini-2.5-flash`, `qwen-plus`)
- `is_enabled`: boolean
- `timeout`: `5`–`60` seconds
- `max_output_tokens`: `150`–`1000`
- `updated_by`, `updated_at`

Every configuration update is audited in `ai_feature_setting_audits` (previous/new provider, model, enabled state, timeout, tokens, admin user ID, timestamp) without storing API keys or receipt data.

---

## Permissions & Seeding

Run the additive migration and permission seeder when deploying:

```bash
php artisan migrate --path=database/migrations/2026_11_26_000002_create_ai_feature_settings_table.php
php artisan db:seed --class=ReceiptExtractionAiPermissionSeeder
```

Permissions created:
- `receipt-extraction-ai.view` — View settings, runtime environment, provider key status, and audit history
- `receipt-extraction-ai.update` — Update provider, model, enabled status, timeout, and max output tokens
- `receipt-extraction-ai.test` — Run provider/model connection tests

---

## How Admins Switch Providers & Models

1. Open **Admin Panel → Settings → Receipt Extraction AI** (`/settings/receipt-extraction-ai`).
2. Confirm the read-only **Environment** badge (`Development` or `Production`) and check that the target provider shows **Key Configured**.
3. Choose the **AI Provider** (`OpenAI`, `Google Gemini`, or `Qwen`) and enter the exact **Model Identifier**.
4. Adjust **Request Timeout** and **Max Output Tokens** if needed.
5. Click **Test Connection** to verify credentials and model access (this does not save settings and does not create or process any payment/receipt records).
6. Click **Save Settings**. The change takes effect immediately without code deployment.

---

## How to Add a New Provider

1. Create a new adapter class in `app/Http/Services/ReceiptExtraction/` implementing `ReceiptExtractionProviderInterface` (`key()`, `label()`, `extract()`, `testConnection()`).
2. Register the provider's `label`, `base_url`, `dev_key`, and `prod_key` under `services.receipt_extraction_ai.providers` in `config/services.php` and document the env vars in `.env.example`.
3. Add the provider key and class mapping to `ReceiptExtractionProviderFactory::PROVIDERS`.
4. Add unit/feature tests covering credential resolution, adapter invocation, error handling, and connection testing.
