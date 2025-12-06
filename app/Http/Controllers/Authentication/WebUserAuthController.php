<?php

namespace App\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Services\Authentication\WebUserAuhService;
use App\Http\Services\WebUserService;
use App\Models\StudyPlan;
use App\Models\UserStudyPlan;
use App\Models\WebUser;
use App\Models\WebUserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Symfony\Component\Clock\now;

class WebUserAuthController extends Controller
{
    protected WebUserAuhService $webUserAuthService;
    protected WebUserService $webUserService;
    public function __construct()
    {
        $this->webUserAuthService = new WebUserAuhService();
        $this->webUserService = new WebUserService();
       
    }
    public function login(Request $request){
        $status = $this->webUserAuthService->login($request);
        $statusData = $status->getData();

        if($statusData->success == 0){
             return response()->json([
                'success' => 0,
                'error' => 'Unauthorized'
            ], 401);
        }

        return response()->json([
            'success' => 1,
            'token' => $statusData->token,
            'expires_in' => $statusData->expires_in,
        ]);

    }
    public function registerWebUser(Request $request){
       /* $register = $this->webUserAuthService->registerWebUser($request);
        $registerData = $register->getData();

        if($registerData->success == 1){
            return response()->json([
                    'message' => 'User registered successfully',
                    'user' => $registerData->user,
                    'success' => 1,
                    'token' => $registerData->token,
                ]);
        }
        $statusCode = $registerData->status;

        return response()->json([
            'success' => $registerData->success,
            'message' => $registerData->message,
            'error' => $registerData->error,
        ]);*/
        return $this->webUserAuthService->registerWebUser($request);
    }

    public function getStatus(Request $request){

        $user = $request->user();

        $userClass = $this->webUserService->getUserClass($request);
        $userClassData = $userClass->getData();       

         return response()->json([
            'success' => 1,
            'message' => 'Login successful',
                   
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'classInfo' => $userClassData->success === 1 ? $userClassData->data : [] ,
        ]);
    }

    public function saveUserDataByAdmin(Request $request){
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:web_users',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|string', // Ensure 'role' is validated
            'address' => 'required|string',
            'city_id' => 'required|integer|exists:city_tbl,id',
            'phone' => 'required|string',
            'class_id' => 'nullable|integer|exists:class_tbl,id',
            'curriculum_board_id' => 'nullable|integer|exists:curriculum_board_tbl,id',
            'institute_id' => 'nullable|exists:institute_tbl,id', // Fixed typo
            'incharge_name' => 'nullable|string',
            'incharge_phone' => 'nullable|string',
            'gender_id' => 'nullable|integer',
            'dob' => 'required|date',
            'designation' => 'nullable|string',
            'heard_about_id' => 'required|integer|exists:heard_about_tbl,id',
            'study_group_id' => 'integer|exists:study_group_tbl,id',
            'study_plan_id' => 'integer|exists:study_plan_tbl,id',
        ]);

        DB::beginTransaction();
        try{
            $planId = $request->study_plan_id;
            $plan = StudyPlan::find($planId);
            $session_id = $plan->session_id;

             $webUserData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'email_verified_at' => now(),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'study_session_id' => $session_id,
        ];
        $user = WebUser::create($webUserData);

        $profileData = [
            'user_id' => $user->id,
                    'address' => $validated['address'] ?? null,
                    'city_id' => $validated['city_id'],
                    'phone' => $validated['phone'] ?? null,
                    'class_id' => $validated['class_id'] ?? null,
                    'curriculum_board_id' => $validated['curriculum_board_id'] ?? null,
                    'institute_id' => $validated['institute_id'] ?? null, // Fixed typo
                    'incharge_name' => $validated['incharge_name'] ?? null,
                    'incharge_phone' => $validated['incharge_phone'] ?? null,
                    'gender_id' => $validated['gender_id'],
                    'dob' => $validated['dob'] ,
                    'designation' => $validated['designation'] ?? null,
                    'heard_about_id' => $validated['heard_about_id'],
                    'study_plan_id' => $validated['study_plan_id'],
                    'study_group_id' => $validated['study_group_id'],
                    'activate' => $request->activate ?? 1,
        ];

        WebUserProfile::create($profileData);

        $planData = [
            'user_id' => $user->id,
            'study_plan_id' => $validated['study_plan_id'],
            'qty' => 1,
            'price' => $request->price ?? 0,
        ];

        UserStudyPlan::created($planData);

        DB::commit();

        return response()->json([
            'success' => 1
        ]);


        }catch (\Exception $e) {
            DB::rollBack();
            Log::error("Admin saved Failed: " . $e->getMessage());
            
            return response()->json([
                'success' => -1,
                'error' => 'Server Error: Could not complete the save.',
            ], 500); 
        }
    }

    public function updateUserDataByAdmin(Request $request)
    {
        // --- 1. Validation (CRITICAL FOR ADMIN ROUTES) ---
        $request->validate([
            'user_id' => 'required|integer|exists:web_users,id',
            'profile_id' => 'required|integer|exists:user_profile_tbl,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20', 
            // Add all other required validation rules here...
        ]);

        DB::beginTransaction();

        try {
            // --- 2. Target User Retrieval (FIXED) ---
            // Use find() or firstOrFail() to get a single Model instance, not a Query Builder.
            $userId = $request->user_id;
            $user = WebUser::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => 0,
                    'error' => "Target user (ID: {$userId}) not found."
                ], 404);
            }

            // Retrieve the profile using the requested profile ID
            $userProfileId = $request->profile_id;
            $userProfile = WebUserProfile::find($userProfileId);
            
            if (!$userProfile || $userProfile->user_id !== $user->id) {
                 return response()->json([
                    'success' => 0,
                    'error' => "Profile ID {$userProfileId} does not match User ID {$userId}."
                ], 404);
            }

            // --- 3. Update User Data ---
            $userData = [
                'name' => $request->name,
                // Do NOT allow email update here unless you have a dedicated flow
            ];

            // FIXED: Correct syntax for assigning email_verified_at
            if ($user->email_verified_at === null) {
                $userData['email_verified_at'] = now();
            }

            $user->update($userData);

            // --- 4. Update Profile Data ---
            $profileData = [
                'address' => $request->address,
                'phone' => $request->phone,
                'city_id' => $request->city_id,
                'institute_id' => $request->institute_id,
                'incharge_name' => $request->incharge_name,
                'incharge_phone' => $request->incharge_phone,
                'gender_id' => $request->gender_id,
                'dob' => $request->dob,
                'study_plan_id' => $request->study_plan_id,
                'class_id' => $request->class_id,
                'curriculum_board_id' => $request->curriculum_board_id,
                'study_group_id' => $request->study_group_id,
            ];

            $userProfile->update($profileData);

           $plan = UserStudyPlan::where('user_id', $user->id)->first();

            $planData = [
            'user_id' => $user->id,
            'study_plan_id' => $request->study_plan_id,
            'qty' => 1,
            'price' => $request->price ?? 590,
        ];

        if(!$plan){
            UserStudyPlan::create($planData);
        }
        else{
            $planModel = UserStudyPlan::find($plan->id);
            $planModel->update($planData);
        }

            DB::commit();
            
            return response()->json([
                'success' => 1,
                'message' => "User {$user->email} data and profile updated successfully by Admin."
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Admin Update Failed: " . $e->getMessage());
            
            return response()->json([
                'success' => -1,
                'error' => 'Server Error: Could not complete the update.',
            ], 500); 
        }
    }

    public function getUserData(Request $request){
        $email = $request->email;
        $userData = WebUser::where('email', $email)
        ->with('profile')->first();

        if(!$userData){
            return response()->json([
                'success' => 0,
            ], 401);
        }
         return response()->json([
                'success' => 1,
                'data' => $userData,
            ]);
    }

    public function verifiedUserByAdmin(Request $request){
        $userEmail = $request->input('email');
        $userId = $request->input('user_id');

        $emailData = [
            'email_verified_at' => now(), 
        ];

        $user = WebUser::find($userId);
        if($user->email === $userEmail){
            $user->update($emailData);
            return response()->json([
                'success' => 1,
            ]);
        }

         return response()->json([
                'success' => 0,
            ]);

    }
    public function getUnVerifiedWebUsers(){
        try{
            $users = WebUser::where('email_verified_at', null)->with('profile')->get();

            $formattedUsers = $users->map(function ($user) {
                if($user->profile){
                    return[
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->profile->phone,
                    ];
                }
                else{
                     return[
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => '',
                    ];

                }
            });

            return response()->json([
                'success' => 1,
                'data' => $formattedUsers,
            ]);

        }catch (\Exception $e) {
            
            Log::error("Admin getUnVerifiedWebUsers: " . $e->getMessage());
            
            return response()->json([
                'success' => -1,
                'error' => 'Server Error: Could not complete the update.',
            ], 500); 
        }
    }
    
}
