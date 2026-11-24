<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$institutes = DB::table('institute_tbl')->get();
echo "Total institutes: " . $institutes->count() . "\n";
foreach ($institutes as $i) {
    echo "ID: {$i->id}, Name: {$i->name}, city_id: {$i->city_id}, activate: {$i->activate}\n";
}
