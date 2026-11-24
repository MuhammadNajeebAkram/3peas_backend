<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\WebUser;
use App\Models\WebUserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Pick a test student user
$student = WebUser::where('role', 'student')->first();
if (!$student) {
    echo "No student found in DB\n";
    exit(1);
}

echo "Testing profile update for student: {$student->name} (ID: {$student->id}, Email: {$student->email})\n";

// Authenticate as student
Auth::guard('web_api')->setUser($student);

$ctrl = app(\App\Http\Controllers\Authentication\WebUserAuthController::class);

$payload = [
    'name' => $student->name,
    'phone' => $student->phone ?? '03347552937',
    'city_id' => 1, // Lahore
    'institute_id' => 1, // Govt. Central model School
    'gender_id' => 1, // Male
    'dob' => '2005-04-15',
    'address' => 'Gulberg III, Lahore',
    'referral_code' => 'PGCAB775',
    'preferred_language' => 'en',
];

$req = Request::create('/web_api/complete-profile', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
$response = $ctrl->completeProfile($req);

echo "Status Code: " . $response->getStatusCode() . "\n";
$responseData = json_decode($response->getContent(), true);

echo "Updated Profile in DB:\n";
$profileRow = DB::table('user_profile_tbl')->where('user_id', $student->id)->first();
echo json_encode($profileRow, JSON_PRETTY_PRINT) . "\n";

echo "Returned User Payload:\n";
echo json_encode($responseData['user'] ?? [], JSON_PRETTY_PRINT) . "\n";
