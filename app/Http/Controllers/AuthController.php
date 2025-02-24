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
    $debug = 'start';
    try {
        // Validate incoming request
        $validatedData = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:web_users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|string', // Ensure 'role' is validated
            'address' => 'nullable|string',
            'city_id' => 'nullable|integer',
            'phone' => 'nullable|string',
            //'class_id' => 'nullable|integer',
            //'curriculum_board_id' => 'nullable|integer',
            'institute_id' => 'nullable|integer', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'nullable|integer',
            'dob' => 'nullable|date',
            'designation' => 'nullable|string',
            'heard_about_id' => 'nullable|integer',
        ]);
        $debug = 'validation';

        // Start transaction to ensure atomicity
        DB::beginTransaction();
        
        // Create User
        $user = WebUser::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'role' => $validatedData['role'],
            'study_session_id' => 0,
        ]);
        $debug = 'After user';

        // Create User Profile
        WebUserProfile::create([
            'user_id' => $user->id,
            'address' => $validatedData['address'] ?? null,
            'city_id' => $validatedData['city_id'] ?? null,
            'phone' => $validatedData['phone'] ?? null,
            'class_id' => $validatedData['class_id'] ?? 0,
            'curriculum_board_id' => $validatedData['curriculum_board_id'] ?? 0,
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
            'success' => -1,
            'debug' => $debug,
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

        $user = Auth::guard($guard)->user();

        //web_api
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

        // 🔹 Invalidate previous token if it exists
        if ($user->last_token) {
            try {
                Auth::guard($guard)->setToken($user->last_token)->invalidate();
            } catch (\Exception $e) {
                // Log the error but continue (might be invalid or already expired)
                \Log::error("Token invalidation failed: " . $e->getMessage());
            }
        }

        // 🔹 Save the new token to the database
        $user->update(['last_token' => $token]);

        }  
        // end web_api
        
        

        return response()->json([
            'success' => 1,
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => $expiration,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,                
            ],
        ]);
    }
    catch(\Exception $e){
        return response()->json([
            'success' => -1,
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

        $guard = Auth::guard('api')->check() ? 'api' : (Auth::guard('web_api')->check() ? 'web_api' : null);
        
        if (!$guard) {
            return response()->json(['success' => 0, 'error' => 'Unauthorized'], 401);
        }

 // Validate the input
 $request->validate([
    'current_password' => 'required',
    'new_password' => 'required|min:8',
]);

$user = Auth::guard($guard)->user();

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

public function getUserProfile(Request $request){
    try{

        $guard = Auth::guard('api')->check() ? 'api' : (Auth::guard('web_api')->check() ? 'web_api' : null);
        
        if (!$guard) {
            return response()->json(['success' => 0, 'error' => 'Unauthorized'], 401);
        }

        $user_id = $request->user()->id;
        $user_name = $request->user()->name;
        $user_email = $request->user()->email;

        $userData = DB::table('user_profile_tbl as upt')
        ->join('class_tbl as ct', 'upt.class_id', '=', 'ct.id')
        ->join('curriculum_board_tbl as cbt', 'upt.curriculum_board_id', '=', 'cbt.id')
        ->where('upt.user_id', $user_id)
        ->select('upt.address', 'upt.city_id', 'upt.phone', 'ct.class_name', 'upt.institute_id', 'upt.incharge_name', 'upt.incharge_phone', 
        'upt.gender_id', 'upt.dob', 'upt.designation', 'cbt.name as curriculum_board')
        ->first();

        return response()->json([
            'success' => 1,
            'userName' => $user_name,
            'userEmail' => $user_email,
            'userProfile' => $userData,]);



    }
    catch(\Exception $e){

        return response()->json([
            'successs' => 0,
            'error' => $e->getMessage()]);

    }
}

public function updateUserProfile(Request $request){
    try{
        $validatedData = $request->validate([
            'name' => 'required|string',  
            'address' => 'nullable|string',
            'city_id' => 'nullable|integer',
            'phone' => 'nullable|string',
            'institute_id' => 'nullable|integer', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'nullable|integer',
            'dob' => 'nullable|date',
            
        ]);

        $guard = Auth::guard('api')->check() ? 'api' : (Auth::guard('web_api')->check() ? 'web_api' : null);
        
        if (!$guard) {
            return response()->json(['success' => 0, 'error' => 'Unauthorized'], 401);
        }
        $user = Auth::guard($guard)->user();


        $user_id = $user->id;

        DB::beginTransaction();

        $user->update(['name' => $validatedData['name']]);

        $updateProfile = DB::table('user_profile_tbl')
        ->where('user_id', $user_id)
        ->update([
            'address' => $validatedData['address'] ?? null,
            'city_id' => $validatedData['city_id'] ?? null,
            'phone' => $validatedData['phone'] ?? null,
            'institute_id' => $validatedData['institute_id'] ?? null, // Fixed typo
            'incharge_name' => $validatedData['incharge_name'] ?? null,
            'incharge_phone' => $validatedData['incharge_phone'] ?? null,
            'gender_id' => $validatedData['gender_id'] ?? null,
            'dob' => $validatedData['dob'] ?? null,

        ]);

        DB::commit();

        return response()->json([
            'success' => 1,
        ]);
    }
    catch(\Exception $e){
        DB::rollBack();
        return response()->json([
            'successs' => 0,
            'error' => $e->getMessage()]);
    }
}
}
