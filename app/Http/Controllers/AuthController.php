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
use Illuminate\Support\Facades\Log;

use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Carbon\Carbon;



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
            'email' => 'required|email|unique:web_users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|string', // Ensure 'role' is validated
            'address' => 'required|string',
            'city_id' => 'nullable|integer',
            'phone' => 'required|string',
            //'class_id' => 'nullable|integer',
            //'curriculum_board_id' => 'nullable|integer',
            'institute_id' => 'nullable|integer', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'nullable|integer',
            'dob' => 'required|date',
            'designation' => 'nullable|string',
            'heard_about_id' => 'required|integer',
        ]);

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

                $token = JWTAuth::fromUser($user);

                return response()->json([
                    'message' => 'User registered successfully',
                    'user' => $user,
                    'success' => 1,
                    'token' => $token,
                ]);

            } catch (\Illuminate\Validation\ValidationException $e) {
                $errors = $e->errors();
                if (isset($errors['email'])) {
                    foreach ($errors['email'] as $msg) {
                        if (strpos($msg, 'unique') !== false || strpos(strtolower($msg), 'already exists') !== false) {
                            return response()->json([
                                'message' => 'Duplicate user error: Email already exists.',
                                'errors' => $errors,
                                'success' => -2,
                            ], 409);
                        }
                    }
                }
                return response()->json([
                    'errors' => $errors,
                    'success' => -1,
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
    try {
        $credentials = $request->only('email', 'password');
        $guard = $request->is('api/*') ? 'api' : 'web_api';

        // Attempt to authenticate and generate a JWT token
        if (!$token = Auth::guard($guard)->attempt($credentials)) {
            return response()->json([
                'success' => 0,
                'error' => 'Unauthorized'
            ], 401);
        }

        // Get the authenticated user
        $user = Auth::guard($guard)->user();
        $expiration = config('jwt.ttl') * 60; // TTL in seconds from JWT config

        // Web_api specific checks
        if ($guard === 'web_api') {
            // Check if email is verified
            if (!$user->email_verified_at) {
                return response()->json([
                    'success' => 2,
                    'user' => $user->name,
                    'error' => 'First verify your email'
                ], 403);
            }

            // Check if study session is active
            $session_id = $user->study_session_id;
            if ($session_id === 0) {
                return response()->json([
                    'success' => 3,
                    'error' => 'Your session has been expired',
                    'token' => $token,
                    'user' => $user->name,
                ], 404);
            }

            $sessionTbl = DB::table('study_session_tbl')
                ->where('id', $session_id)
                ->whereDate('start_date', '<=',  Carbon::today())
                ->whereDate('end_date', '>=',  Carbon::today())               
                ->first();

            if (!$sessionTbl) {
                return response()->json([
                    'success' => 4,
                    'error' => 'Your study session has expired or is invalid',
                    'token' => $token,
                ], 404);
            }

            // Invalidate previous token (logout from other devices)
            if (!empty($user->last_token)) {
                Log::info("Trying to invalidate previous token: " . $user->last_token);
                try {
                    JWTAuth::setToken($user->last_token); // Set the token before invalidation
                    JWTAuth::invalidate(true);
                    Log::info("Previous token invalidated successfully.");
                } catch (JWTException $e) {
                    Log::error("Token invalidation failed: " . $e->getMessage());
                }
            } else {
                Log::warning("User last_token is empty, nothing to invalidate.");
            }

            // Save the new token
            $user->last_token = $token;
            $user->save();
        }

        $classInfo = app(ClassesController::class)->getClassOfUser($request);
        $classData = $classInfo->getData();

        return response()->json([
            'success' => 1,
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => $expiration,           
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'classInfo' => $classData,
        ]);
    } catch (JWTException $e) {
        return response()->json([
            'success' => -1,
            'error' => 'Could not create token',
            'message' => $e->getMessage()
        ], 500);
    } catch (\Exception $e) {
        return response()->json([
            'success' => -1,
            'error' => 'Something went wrong',
            'message' => $e->getMessage()
        ], 500);
    }
}


    public function logout()
    {
        try {
            $token = JWTAuth::getToken(); // Get the token from the request
    
            if (!$token) {
                return response()->json(['error' => 'Token not provided'], 400);
            }
            Log::info('logout and invalidate token'. $token);
            JWTAuth::invalidate(true); // Invalidate the token
    
            return response()->json(['success' => 1, 'message' => 'Successfully logged out']);
        } catch (TokenExpiredException $e) {
            return response()->json(['error' => 'Token already expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token not found'], 500);
        }
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

public function createWebUser(Request $request){
    DB::beginTransaction();
    try{

        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:web_users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|string', // Ensure 'role' is validated
            'address' => 'required|string',
            'city_id' => 'nullable|integer',
            'phone' => 'required|string',
            'class_id' => 'nullable|integer',
            'curriculum_board_id' => 'nullable|integer',
            'institute_id' => 'nullable|integer', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'required|integer',
            'dob' => 'required|date',
            'designation' => 'nullable|string',
            'heard_about_id' => 'required|integer',
            'class_id' => 'required|integer|exists:class_tbl,id',
            'curriculum_board_id' => 'required|integer|exists:curriculum_board_tbl,id',
            'study_plan_id' => 'required|integer|exists:study_plan_tbl,id',            

        ]);

       
       
        $session = DB::table('study_plan_tbl')
        ->where('id', $request->study_plan_id)
        ->value('session_id'); // Use value() instead of select()->first()

        $validated['study_session_id'] = $session;

        $webUserData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'email_verified_at' => now(),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'study_session_id' => $session,
        ];
        
        $user = WebUser::create($webUserData);

       
        $validated['user_id'] = $user->id;

        WebUserProfile::create($validated);

         // Insert study plan mapping
         DB::table('user_study_plan_tbl')->insert([
            'user_id' => $user->id,
            'study_plan_id' => $request->study_plan_id,
            'qty' => 1,
            'price' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Assign subjects
        $subjects = DB::table('study_group_detail_tbl')
            ->where('study_group_id', $request->study_group_id)
            ->pluck('subject_id');

        foreach ($subjects as $subjectId) {
            DB::table('user_selected_subject_tbl')->insert([
                'user_id' => $user->id,
                'subject_id' => $subjectId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => 1,
            ], 200);


    }catch(\Exception $e){
        DB::rollBack();
        return response()->json([
            'success' => -1,
            'error' => $e->getMessage()], 500);

    }
}
}
