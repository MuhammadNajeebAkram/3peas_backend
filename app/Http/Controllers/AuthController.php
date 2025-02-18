<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Auth\Events\Registered;
use App\Models\WebUserProfile;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    //
    public function register(Request $request) {
        try {
            $request->validate([
                'name' => 'required|string',                
                'email' => 'required|email|unique:users',
                'password' => 'required|min:8|confirmed',                
            ]);
            
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            //$token = JWTAuth::fromUser($user);
            
            return response()->json(['message' => 'User registered successfully', 'user' => $user, 'success' => 1, ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors(), 'success' => 0], 422);
        }
    }
   

public function registerWebUser(Request $request)
{
    try {
        // Validate incoming request
        $validatedData = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|string', // Ensure 'role' is validated
            'address' => 'nullable|string',
            'city_id' => 'nullable|integer',
            'phone' => 'nullable|string',
            'class_id' => 'nullable|integer',
            'curriculum_board_id' => 'nullable|integer',
            'institute_id' => 'nullable|integer', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'nullable|integer',
            'dob' => 'nullable|date',
            'designation' => 'nullable|string',
            'heard_about_id' => 'nullable|integer',
        ]);

        // Start transaction to ensure atomicity
        DB::beginTransaction();

        // Create User
        $user = WebUser::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'role' => $validatedData['role'],
        ]);

        // Create User Profile
        WebUserProfile::create([
            'user_id' => $user->id,
            'address' => $validatedData['address'] ?? null,
            'city_id' => $validatedData['city_id'] ?? null,
            'phone' => $validatedData['phone'] ?? null,
            'class_id' => $validatedData['class_id'] ?? null,
            'curriculum_board_id' => $validatedData['curriculum_board_id'] ?? null,
            'institute_id' => $validatedData['institute_id'] ?? null, // Fixed typo
            'incharge_name' => $validatedData['incharge_name'] ?? null,
            'incharge_phone' => $validatedData['incharge_phone'] ?? null,
            'gender_id' => $validatedData['gender_id'] ?? null,
            'dob' => $validatedData['dob'] ?? null,
            'designation' => $validatedData['designation'] ?? null,
            'heard_about_id' => $validatedData['heard_about_id'] ?? null,
            'study_plan_id' => 0,
            'activate' => 0,
        ]);

        // Commit transaction
        event(new Registered($user));
        DB::commit();

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'success' => 1,
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'errors' => $e->errors(),
            'success' => 0,
        ], 422);
    } catch (\Exception $e) {
        // Rollback transaction in case of an error
        DB::rollBack();

        return response()->json([
            'message' => 'An error occurred during registration.',
            'error' => $e->getMessage(),
            'success' => 0,
        ], 500);
    }
}

    public function login(Request $request)
    {
        
        try{

        
        $credentials = $request->only('email', 'password');

        $guard = $request->is('api/*') ? 'api' : 'web_api';
        
        $expiration = Auth::factory()->getTTL() * 60; // TTL in seconds
        if (!$token = Auth::guard($guard)->attempt($credentials)) {
            return response()->json([
                'success' => 0,
                'error' => 'Unauthorized'], 401);
        }

        $user = Auth::user();
        if($guard == 'web_api'){

             // Check if email is verified
         if (!$user->email_verified_at) {
            return response()->json([
                'success' => 2,
                'error' => 'First verify your email'], 403);
        }
         // Check if study session active or payment verified
         $session_id = $user->study_session_id;
         if ($session_id === 0) {
            return response()->json([
                'success' => 3,
                'error' => 'Your session has been expired',
            'token' => $token,
            ], 404);
        }
        $sessionTbl = DB::table('study_session_tbl')
        ->where('start_date', '<=', now())
        ->where('end_date', '>=', now())
        ->where('id', '=', $session_id)
        ->first();

        if(!$sessionTbl){

            return response()->json([
                'success' => 4,
                'error' => 'Your study session has expired or is invalid',
            'token' => $token,
            ], 404);

        }

        }      

        return response()->json([
            'success' => 1,
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => $expiration,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,                
            ],
        ]);
    }
    catch(\Exception $e){
        return response()->json([
            'message' => $e->getMessage(),
        ], 500);
    }
    }
    public function logout()
    {
        Auth::logout();
        return response()->json(['message' => 'Successfully logged out']);
    }
    public function updatePassword(Request $request)
{
    try{
 // Validate the input
 $request->validate([
    'current_password' => 'required',
    'new_password' => 'required|min:8',
]);

$user = auth()->user();

// Check if the current password matches
if (!Hash::check($request->current_password, $user->password)) {
    return response()->json(['success' => 0, 'error' => 'Current password is incorrect'], 403);
}

// Update the password
$user->password = bcrypt($request->new_password);
$user->save();

return response()->json(['success' => 1, 'message' => 'Password updated successfully']);
    }
    catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json(['errors' => $e->errors(), 'success' => 2], 422);
    }
   
}
}
