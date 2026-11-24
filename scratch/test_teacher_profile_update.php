<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\WebUser;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$teacher = WebUser::where('role', 'teacher')->first();
if (!$teacher) {
    echo "No teacher found\n";
    exit(1);
}

echo "Testing profile update for teacher: {$teacher->name} (ID: {$teacher->id})\n";
Auth::guard('web_api')->setUser($teacher);

$ctrl = app(\App\Http\Controllers\Authentication\WebUserAuthController::class);

$payload = [
    'name' => $teacher->name,
    'phone' => $teacher->phone,
    'city_id' => 16, // Rawalpindi
    'institute_id' => 17, // Jinnah College
];

$req = Request::create('/web_api/complete-profile', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
$response = $ctrl->completeProfile($req);

echo "Status Code: " . $response->getStatusCode() . "\n";
$responseData = json_decode($response->getContent(), true);

$tpRow = DB::table('teacher_profiles')->where('web_user_id', $teacher->id)->first();
echo "Updated Teacher Profile in DB:\n" . json_encode($tpRow, JSON_PRETTY_PRINT) . "\n";
echo "Returned Teacher User Payload:\n" . json_encode($responseData['user'] ?? [], JSON_PRETTY_PRINT) . "\n";
