<?php

return [

    'gemini' => [
        'api_key' => env('GOOGLE_AI_API_KEY', env('GEMINI_API_KEY')),
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 1800),
    ],

    'openai' => [
        'api_key' => env('OPEN_AI_API_KEY', env('OPENAI_API_KEY')),
        'timeout' => (int) env('OPENAI_TIMEOUT', 45),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 1800),
        'receipt_extraction_model' => env('OPENAI_RECEIPT_EXTRACTION_MODEL', 'gpt-6-luna'),
        'receipt_extraction_timeout' => (int) env('OPENAI_RECEIPT_EXTRACTION_TIMEOUT', 25),
        'receipt_extraction_max_output_tokens' => (int) env('OPENAI_RECEIPT_EXTRACTION_MAX_OUTPUT_TOKENS', 400),
    ],

    'receipt_ocr' => [
        'tesseract_binary' => env('TESSERACT_BINARY', 'tesseract'),
        'tesseract_lang' => env('TESSERACT_LANG', 'eng'),
        'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),
        'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'timeout' => (int) env('RECEIPT_OCR_TIMEOUT', 20),
        'max_text_chars' => (int) env('RECEIPT_OCR_MAX_TEXT_CHARS', 4000),
        'max_pdf_pages' => (int) env('RECEIPT_OCR_MAX_PDF_PAGES', 2),
    ],

    'ai_credentials' => (static function (): array {
        $appEnv = strtolower(trim((string) env('APP_ENV', '')));
        $pattern = match (true) {
            $appEnv === 'production' => '/^AI_[A-Z0-9_]+_PROD_KEY$/',
            $appEnv === 'staging' => '/^AI_[A-Z0-9_]+_STAGING_KEY$/',
            in_array($appEnv, ['local', 'development', 'testing'], true) => '/^AI_[A-Z0-9_]+_DEV_KEY$/',
            default => null,
        };

        if ($pattern === null) {
            return [];
        }

        $placeholders = ['null', '(null)', 'empty', '(empty)', 'false', '(false)'];
        $keys = [];
        $sources = array_merge(getenv() ?: [], $_SERVER, $_ENV);

        foreach ($sources as $name => $value) {
            if (! is_string($name) || ! preg_match($pattern, $name) || ! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '' || in_array(strtolower($trimmed), $placeholders, true)) {
                continue;
            }

            $keys[$name] = $trimmed;
        }

        return $keys;
    })(),

    'receipt_extraction_ai' => [
        'default_provider' => env('RECEIPT_EXTRACTION_AI_PROVIDER', 'openai'),
        'default_model' => env('OPENAI_RECEIPT_EXTRACTION_MODEL', 'gpt-6-luna'),
        'default_enabled' => filter_var(env('RECEIPT_EXTRACTION_AI_ENABLED', true), FILTER_VALIDATE_BOOL),
        'default_timeout' => (int) env('OPENAI_RECEIPT_EXTRACTION_TIMEOUT', 25),
        'default_max_output_tokens' => (int) env('OPENAI_RECEIPT_EXTRACTION_MAX_OUTPUT_TOKENS', 400),
        'providers' => [
            'openai' => [
                'label' => 'OpenAI',
                'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'base_url' => rtrim((string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            ],
            'qwen' => [
                'label' => 'Qwen',
                'base_url' => rtrim((string) env('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'), '/'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'frontend' => [
        'api_key' => env('FRONT_END_API_KEY'),
    ],

];
