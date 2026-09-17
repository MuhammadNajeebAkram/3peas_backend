<?php

return [
    'source_disk' => 'local',
    'diagram_disk' => env('AI_QUESTION_DIAGRAM_DISK', 's3'),
    'max_files' => 10,
    'max_file_bytes' => 8 * 1024 * 1024,
    'max_total_bytes' => 12 * 1024 * 1024,
    'max_image_pixels' => 8000000,
    'max_text_chars' => 50000,
    'max_questions' => 5,
    'max_output_tokens' => (int) env('AI_QUESTION_MAX_OUTPUT_TOKENS', 12000),
    'timeout' => (int) env('AI_QUESTION_TIMEOUT', 90),
];
