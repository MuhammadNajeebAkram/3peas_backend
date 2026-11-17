<?php

return [
    'attachments' => [
        'max_per_assignment' => 5,
        'max_per_submission' => 5,
        'max_per_calendar_event' => 1,
        'max_file_kb' => 10240,
        'allowed_extensions' => [
            'pdf', 'jpg', 'jpeg', 'png', 'webp',
            'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip',
        ],
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream', // checked alongside strict extension validation only for office/zip
        ],
    ],
];
