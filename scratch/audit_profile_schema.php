<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$tables = [
    'user_profile_tbl',
    'web_users',
    'teacher_profiles',
    'city_tbl',
    'institute_tbl',
    'board_tbl',
    'class_tbl',
    'heard_about_tbl',
    'user_selected_subject_tbl'
];

$result = [];
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        $columns = DB::select("DESCRIBE {$table}");
        $sample = DB::table($table)->latest()->first();
        $count = DB::table($table)->count();
        $result[$table] = [
            'count' => $count,
            'columns' => array_map(function($c) {
                return [
                    'Field' => $c->Field,
                    'Type' => $c->Type,
                    'Null' => $c->Null,
                    'Key' => $c->Key,
                    'Default' => $c->Default,
                ];
            }, $columns),
            'sample' => $sample
        ];
    } else {
        $result[$table] = 'NOT_FOUND';
    }
}

file_put_contents(__DIR__ . '/profile_schema_audit.json', json_encode($result, JSON_PRETTY_PRINT));
echo "AUDIT COMPLETE. Saved to scratch/profile_schema_audit.json\n";
