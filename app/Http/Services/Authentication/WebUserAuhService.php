<?php

namespace App\Http\Services\Authentication;

use App\Models\WebUser;
use App\Models\WebUserProfile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class WebUserAuhService {
    public function login($request){
        $credentials = $request->only('email', 'password');
        $token = Auth::guard('web_api')->attempt($credentials);
        Log::info('Authentication', ['user' => $credentials['email'],
        'pass' => $credentials['password'], 'token' => $token]);
        if(!$token){
           return response()->json([
            'success' => 0,
            'error' => 'Unauthorized'
           ], 401);

        }
        $user = Auth::guard('web_api')->user();
        $expiration = config('jwt.ttl') * 60;

        return response()->json([
            'success' => 1,
            'token' => $token,
             'expires_in' => $expiration,           
            
        ]);

    }

    public function registerWebUser($request)
{
    
    try {
        // Validate incoming request
        $validatedData = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:web_users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|string', // Ensure 'role' is validated
            'address' => 'required|string',
            'city_id' => 'required|integer|exists:city_tbl,id',
            'phone' => 'required|string',
            //'class_id' => 'nullable|integer',
            //'curriculum_board_id' => 'nullable|integer',
            'institute_id' => 'nullable', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'nullable|integer',
            'dob' => 'required|date',
            'designation' => 'nullable|string',
            'heard_about_id' => 'required|integer|exists:heard_about_tbl,id',
        ]);

        $validatedData['institute_id'] = ($validatedData['institute_id'] == 'other' || $validatedData['institute_id'] == '0') ? null : $validatedData['institute_id'];

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
                    'class_id' => $validatedData['class_id'] ?? null,
                    'curriculum_board_id' => $validatedData['curriculum_board_id'] ?? null,
                    'institute_id' => $validatedData['institute_id'] ?? null, // Fixed typo
                    'incharge_name' => $validatedData['incharge_name'] ?? null,
                    'incharge_phone' => $validatedData['incharge_phone'] ?? null,
                    'gender_id' => $validatedData['gender_id'] ?? null,
                    'dob' => $validatedData['dob'] ?? null,
                    'designation' => $validatedData['designation'] ?? null,
                    'heard_about_id' => $validatedData['heard_about_id'],
                    'study_plan_id' => null,
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
}
