<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

$req = Request::create('/web_api/get_institutes', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['city_id' => 1]));
$ctrl = new App\Http\Controllers\InstituteController();
$res = $ctrl->getInstitutes($req);
echo "Response for city_id = 1: " . $res->getContent() . "\n";
