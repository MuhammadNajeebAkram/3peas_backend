<?php

$defaultOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:4100',
    'http://127.0.0.1:4100',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'https://thestudentstimes.com',
    'https://www.thestudentstimes.com',
    'https://admin.thestudentstimes.com',
    'https://api.thestudentstimes.com',
];

$configuredOrigins = explode(
    ',',
    env('CORS_ALLOWED_ORIGINS', implode(',', $defaultOrigins))
);

$allowedOrigins = [];

foreach ($configuredOrigins as $origin) {
    $origin = trim($origin);

    if ($origin === '') {
        continue;
    }

    $allowedOrigins[] = $origin;

    $parts = parse_url($origin);
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? null;
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if ($host === 'localhost') {
        $allowedOrigins[] = $scheme . '://127.0.0.1' . $port;
    } elseif ($host === '127.0.0.1') {
        $allowedOrigins[] = $scheme . '://localhost' . $port;
    }
}

$allowedOrigins = array_values(array_unique($allowedOrigins));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'web_api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
