<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$allCities = DB::table('city_tbl')->get();
echo "Total cities in DB: " . $allCities->count() . "\n";
foreach ($allCities->take(5) as $c) {
    echo "ID: {$c->id}, Name: {$c->name}, activate: " . var_export($c->activate, true) . " (type: " . gettype($c->activate) . ")\n";
}

$status1 = DB::table('city_tbl')->where('activate', '=', 1)->get();
echo "where activate = 1: " . $status1->count() . "\n";

$statusStr1 = DB::table('city_tbl')->where('activate', '=', '1')->get();
echo "where activate = '1': " . $statusStr1->count() . "\n";
