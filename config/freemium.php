<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Freemium Legacy-Free Fallback Quotas
    |--------------------------------------------------------------------------
    |
    | Defines system fallback quotas for students accessing legacy free resources
    | (where program_subjects.is_free = 1 or offered_classes.is_free = 1) in the
    | absence of an active paid subscription or explicit Freemium campaign rule.
    |
    | Note: Application services read values through config('freemium.fallback_quotas.*')
    | using strict integer parsing. Zero disables generation; negative values fallback.
    |
    */
    'fallback_quotas' => [
        'formal_test'      => env('FREEMIUM_LEGACY_WEEKLY_TESTS', '1'),
        'mock'             => env('FREEMIUM_LEGACY_WEEKLY_MOCKS', '1'),
        'practice'         => env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2'),
        'practice_session' => env('FREEMIUM_LEGACY_WEEKLY_PRACTICE', '2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Freemium Assessment Policies
    |--------------------------------------------------------------------------
    |
    | Default retake and attempt policies for legacy free assessments.
    |
    */
    'policy' => [
        'allow_retakes'         => env('FREEMIUM_LEGACY_ALLOW_RETAKES', 'false'),
        'max_attempts_per_test' => env('FREEMIUM_LEGACY_MAX_ATTEMPTS', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Freemium Mock Examination Default Duration
    |--------------------------------------------------------------------------
    |
    | Default time limit in minutes for dynamic mock examinations when the
    | applicable campaign quota does not specify a duration.
    |
    */
    'mock_default_duration_minutes' => env('FREEMIUM_MOCK_DEFAULT_DURATION_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Question Type Explicit Permission Mapping (Milestone A-C1)
    |--------------------------------------------------------------------------
    |
    | Centralized explicit mapping from verified academic question types
    | to their authoritative entitlement permission dimensions and canonical codes.
    |
    | Unmapped, unknown, or malformed question types strictly fail closed and
    | are never assigned default permissions or exposed to students.
    |
    */
    'question_type_permissions' => [
        'explicit_types' => [
            'mcqs' => [
                'dimension' => 'perm_mcqs',
                'code'      => 'mcq',
                'is_mcq'    => true,
            ],
            'mcq' => [
                'dimension' => 'perm_mcqs',
                'code'      => 'mcq',
                'is_mcq'    => true,
            ],
            'short questions' => [
                'dimension' => 'perm_short_questions',
                'code'      => 'short',
                'is_mcq'    => false,
            ],
            'short question' => [
                'dimension' => 'perm_short_questions',
                'code'      => 'short',
                'is_mcq'    => false,
            ],
            'long questions' => [
                'dimension' => 'perm_long_questions',
                'code'      => 'long',
                'is_mcq'    => false,
            ],
            'long question' => [
                'dimension' => 'perm_long_questions',
                'code'      => 'long',
                'is_mcq'    => false,
            ],
        ],
    ],
];
