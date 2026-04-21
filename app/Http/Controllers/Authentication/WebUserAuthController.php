<?php

namespace App\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Services\Authentication\WebUserAuthService;
use App\Http\Services\WebUserService;
use App\Models\StudyPlan;
use App\Models\SubscriptionPaymentRequest;
use App\Models\UserSubscription;
use App\Models\UserStudyPlan;
use App\Models\WebUser;
use App\Models\WebUserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function Symfony\Component\Clock\now;

class WebUserAuthController extends Controller
{
    protected WebUserAuthService $webUserAuthService;
    protected WebUserService $webUserService;
    public function __construct()
    {
        $this->webUserAuthService = new WebUserAuthService();
        $this->webUserService = new WebUserService();
       
    }

    public function googleLogin(Request $request){
         $request->validate([
        'idToken' => ['required', 'string'],
    ]);
        return $this->webUserAuthService->googleLogin($request->idToken);
    }
    public function me()  {
        return $this->webUserAuthService->me();
        
    }
    public function login(Request $request){
        Log::info('login');
        return $this->webUserAuthService->login($request);
       /* $status = $this->webUserAuthService->login($request);
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
        ]);*/

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

    public function logout(Request $request){
        return $this->webUserAuthService->logout();
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
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:web_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'unique:web_users,phone'],
            'google_id' => ['nullable', 'string', 'unique:web_users,google_id'],
            'login_provider' => ['required', Rule::in(['email', 'google'])],
            'avatar' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'email_verified_at' => ['nullable', 'date'],
            'last_login_at' => ['nullable', 'date'],
            'profile' => ['nullable', 'array'],
            'profile.address' => ['nullable', 'string'],
            'profile.city_id' => ['nullable', 'integer', 'exists:city_tbl,id'],
            'profile.institute_id' => ['nullable', 'integer', 'exists:institute_tbl,id'],
            'profile.gender_id' => ['nullable', 'integer'],
            'profile.dob' => ['nullable', 'date'],
            'profile.designation' => ['nullable', 'string'],
            'profile.heard_about_id' => ['nullable', 'integer', 'exists:heard_about_tbl,id'],
            'profile.referral_code' => ['nullable', 'integer', 'unique:user_profile_tbl,referral_code'],
            'profile.profile_completed' => ['nullable', 'boolean'],
            'profile.preferred_language' => ['nullable', Rule::in(['en', 'ur'])],
            'subscriptions' => ['nullable', 'array'],
            'subscriptions.*.id' => ['nullable', 'integer', 'exists:user_subscriptions,id'],
            'subscriptions.*.offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subscriptions.*.status' => ['required', Rule::in(['pending', 'active', 'expired', 'rejected', 'cancelled'])],
            'subscriptions.*.access_type' => ['required', Rule::in(['paid', 'discounted', 'free_specimen', 'complimentary'])],
            'subscriptions.*.price_paid' => ['nullable', 'numeric', 'min:0'],
            'subscriptions.*.started_at' => ['nullable', 'date'],
            'subscriptions.*.expires_at' => ['nullable', 'date'],
            'subscriptions.*.approved_at' => ['nullable', 'date'],
            'subscriptions.*.approved_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        DB::beginTransaction();
        try{
            $profile = $validated['profile'] ?? [];
            $subscriptions = $validated['subscriptions'] ?? [];
            $loginProvider = $validated['login_provider'];

            $webUserData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'email_verified_at' => $validated['email_verified_at'] ?? ($loginProvider === 'google' ? now() : null),
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'avatar' => $validated['avatar'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'phone' => $validated['phone'] ?? null,
                'google_id' => $validated['google_id'] ?? null,
                'login_provider' => $loginProvider,
                'last_login_at' => $validated['last_login_at'] ?? null,
            ];

            $user = WebUser::create($webUserData);

            $profileData = [
                'user_id' => $user->id,
                'address' => $profile['address'] ?? null,
                'city_id' => $profile['city_id'] ?? null,
                'institute_id' => $profile['institute_id'] ?? null,
                'gender_id' => $profile['gender_id'] ?? null,
                'dob' => $profile['dob'] ?? null,
                'designation' => $profile['designation'] ?? null,
                'heard_about_id' => $profile['heard_about_id'] ?? null,
                'referral_code' => $profile['referral_code'] ?? null,
                'profile_completed' => $profile['profile_completed'] ?? false,
                'preferred_language' => $profile['preferred_language'] ?? 'en',
            ];

            WebUserProfile::create($profileData);

            foreach ($subscriptions as $subscription) {
                UserSubscription::create([
                    'user_id' => $user->id,
                    'offered_program_id' => $subscription['offered_program_id'],
                    'status' => $subscription['status'],
                    'access_type' => $subscription['access_type'],
                    'price_paid' => $subscription['price_paid'] ?? 0,
                    'started_at' => $subscription['started_at'] ?? null,
                    'expires_at' => $subscription['expires_at'] ?? null,
                    'approved_at' => $subscription['approved_at'] ?? null,
                    'approved_by' => $subscription['approved_by'] ?? null,
                ]);
            }

        DB::commit();

        return response()->json([
            'success' => 1,
            'message' => 'User created successfully by admin.',
            'user_id' => $user->id,
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
        $validatedData = $request->validate([
            'user_id' => ['required', 'integer', 'exists:web_users,id'],
            'name' => ['required', 'string'],
            'email' => ['required', 'email', Rule::unique('web_users', 'email')->ignore($request->user_id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string'],
            'phone' => ['nullable', 'string', Rule::unique('web_users', 'phone')->ignore($request->user_id)],
            'google_id' => ['nullable', 'string', Rule::unique('web_users', 'google_id')->ignore($request->user_id)],
            'login_provider' => ['required', Rule::in(['email', 'google'])],
            'avatar' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'email_verified_at' => ['nullable', 'date'],
            'last_login_at' => ['nullable', 'date'],
            'profile' => ['nullable', 'array'],
            'profile.address' => ['nullable', 'string'],
            'profile.city_id' => ['nullable', 'integer', 'exists:city_tbl,id'],
            'profile.institute_id' => ['nullable', 'integer', 'exists:institute_tbl,id'],
            'profile.gender_id' => ['nullable', 'integer'],
            'profile.dob' => ['nullable', 'date'],
            'profile.designation' => ['nullable', 'string'],
            'profile.heard_about_id' => ['nullable', 'integer', 'exists:heard_about_tbl,id'],
            'profile.referral_code' => [
                'nullable',
                'integer',
                Rule::unique('user_profile_tbl', 'referral_code')->ignore($request->input('profile.id')),
            ],
            'profile.profile_completed' => ['nullable', 'boolean'],
            'profile.preferred_language' => ['nullable', Rule::in(['en', 'ur'])],
            'subscriptions' => ['nullable', 'array'],
            'subscriptions.*.offered_program_id' => ['required', 'integer', 'exists:offered_programs,id'],
            'subscriptions.*.status' => ['required', Rule::in(['pending', 'active', 'expired', 'rejected', 'cancelled'])],
            'subscriptions.*.access_type' => ['required', Rule::in(['paid', 'discounted', 'free_specimen', 'complimentary'])],
            'subscriptions.*.price_paid' => ['nullable', 'numeric', 'min:0'],
            'subscriptions.*.started_at' => ['nullable', 'date'],
            'subscriptions.*.expires_at' => ['nullable', 'date'],
            'subscriptions.*.approved_at' => ['nullable', 'date'],
            'subscriptions.*.approved_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        DB::beginTransaction();

        try {
            $userId = $validatedData['user_id'];
            $user = WebUser::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => 0,
                    'error' => "Target user (ID: {$userId}) not found."
                ], 404);
            }

            $profile = $validatedData['profile'] ?? [];
            $subscriptions = $validatedData['subscriptions'] ?? null;
            $userProfile = WebUserProfile::firstOrCreate(['user_id' => $user->id]);

            $userData = [
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'role' => $validatedData['role'],
                'avatar' => $validatedData['avatar'] ?? null,
                'status' => $validatedData['status'] ?? 'active',
                'phone' => $validatedData['phone'] ?? null,
                'google_id' => $validatedData['google_id'] ?? null,
                'login_provider' => $validatedData['login_provider'],
                'email_verified_at' => $validatedData['email_verified_at'] ?? null,
                'last_login_at' => $validatedData['last_login_at'] ?? null,
            ];

            if (!empty($validatedData['password'])) {
                $userData['password'] = Hash::make($validatedData['password']);
            }

            $user->update($userData);

            $profileData = [
                'address' => $profile['address'] ?? null,
                'city_id' => $profile['city_id'] ?? null,
                'institute_id' => $profile['institute_id'] ?? null,
                'gender_id' => $profile['gender_id'] ?? null,
                'dob' => $profile['dob'] ?? null,
                'designation' => $profile['designation'] ?? null,
                'heard_about_id' => $profile['heard_about_id'] ?? null,
                'referral_code' => $profile['referral_code'] ?? null,
                'profile_completed' => $profile['profile_completed'] ?? false,
                'preferred_language' => $profile['preferred_language'] ?? 'en',
            ];

            $userProfile->update($profileData);

            if ($subscriptions !== null) {
                $submittedSubscriptionIds = [];

                foreach ($subscriptions as $subscription) {
                    $subscriptionData = [
                        'user_id' => $user->id,
                        'offered_program_id' => $subscription['offered_program_id'],
                        'status' => $subscription['status'],
                        'access_type' => $subscription['access_type'],
                        'price_paid' => $subscription['price_paid'] ?? 0,
                        'started_at' => $subscription['started_at'] ?? null,
                        'expires_at' => $subscription['expires_at'] ?? null,
                        'approved_at' => $subscription['approved_at'] ?? null,
                        'approved_by' => $subscription['approved_by'] ?? null,
                    ];

                    if (!empty($subscription['id'])) {
                        $existingSubscription = UserSubscription::where('id', $subscription['id'])
                            ->where('user_id', $user->id)
                            ->first();

                        if (!$existingSubscription) {
                            DB::rollBack();

                            return response()->json([
                                'success' => 0,
                                'error' => "Subscription ID {$subscription['id']} does not belong to User ID {$user->id}.",
                            ], 404);
                        }

                        $existingSubscription->update($subscriptionData);
                        $submittedSubscriptionIds[] = $existingSubscription->id;
                        continue;
                    }

                    $newSubscription = UserSubscription::create($subscriptionData);
                    $submittedSubscriptionIds[] = $newSubscription->id;
                }

                UserSubscription::where('user_id', $user->id)
                    ->when(
                        !empty($submittedSubscriptionIds),
                        fn ($query) => $query->whereNotIn('id', $submittedSubscriptionIds)
                    )
                    ->delete();
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

    public function getUserDataByAdmin(Request $request)
    {
        $validatedData = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        if (!isset($validatedData['email']) && !isset($validatedData['phone'])) {
            return response()->json([
                'success' => 0,
                'error' => 'Either email or phone is required.',
            ], 422);
        }

        $userQuery = WebUser::with('profile');

        if (isset($validatedData['email'])) {
            $userQuery->where('email', $validatedData['email']);
        } elseif (isset($validatedData['phone'])) {
            $userQuery->where('phone', $validatedData['phone']);
        }

        $userData = $userQuery->first();

        if (!$userData) {
            return response()->json([
                'success' => 0,
                'error' => 'Web user not found.',
            ], 404);
        }

        return response()->json([
            'success' => 1,
            'data' => $userData,
        ]);
    }

    public function getUserData(Request $request)
    {
        return $this->getUserDataByAdmin($request);
    }

    public function getAllUsersDataByAdmin()
    {
        try {
            $users = WebUser::with(['profile', 'subscriptions', 'subscriptionPaymentRequests',
            'subscriptionPaymentRequests.offeredProgram'])->get();

            return response()->json([
                'success' => 1,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            Log::error("Admin getAllUsersDataByAdmin Failed: " . $e->getMessage());

            return response()->json([
                'success' => -1,
                'error' => 'Server Error: Could not fetch web users.',
            ], 500);
        }
    }

    public function approveStudentSubscriptionByAdmin(Request $request)
    {
        $validatedData = $request->validate([
            'subscription_payment_request_id' => ['required', 'integer', 'exists:subscription_payment_requests,id'],
            'access_type' => ['nullable', Rule::in(['paid', 'discounted', 'free_specimen', 'complimentary'])],
            'expires_at' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'admin_remarks' => ['nullable', 'string'],
        ]);

        DB::beginTransaction();

        try {
            $adminId = Auth::guard('api')->id();
            $paymentRequest = SubscriptionPaymentRequest::with(['user', 'offeredProgram'])
                ->find($validatedData['subscription_payment_request_id']);

            if (!$paymentRequest) {
                return response()->json([
                    'success' => 0,
                    'error' => 'Subscription payment request not found.',
                ], 404);
            }

            if ($paymentRequest->status === 'approved') {
                return response()->json([
                    'success' => 0,
                    'error' => 'This payment request is already approved.',
                ], 422);
            }

            if ($paymentRequest->status === 'rejected') {
                return response()->json([
                    'success' => 0,
                    'error' => 'Rejected payment requests cannot be approved directly.',
                ], 422);
            }

            $subscription = null;

            if (!empty($paymentRequest->subscription_id)) {
                $subscription = UserSubscription::where('id', $paymentRequest->subscription_id)
                    ->where('user_id', $paymentRequest->user_id)
                    ->where('offered_program_id', $paymentRequest->offered_program_id)
                    ->first();
            }

            if (!$subscription) {
                $subscription = UserSubscription::firstOrNew([
                    'user_id' => $paymentRequest->user_id,
                    'offered_program_id' => $paymentRequest->offered_program_id,
                ]);
            }

            $subscription->fill([
                'status' => 'active',
                'access_type' => $validatedData['access_type'] ?? $subscription->access_type ?? 'paid',
                'price_paid' => $paymentRequest->final_amount ?? $paymentRequest->price ?? $subscription->price_paid ?? 0,
                'started_at' => $validatedData['started_at'] ?? $subscription->started_at ?? now()->toDateString(),
                'expires_at' => $validatedData['expires_at'] ?? $subscription->expires_at,
                'approved_at' => now(),
                'approved_by' => $adminId,
            ]);
            $subscription->save();

            $paymentRequest->update([
                'subscription_id' => $subscription->id,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $adminId,
                'rejected_by' => null,
                'rejection_reason' => null,
                'admin_remarks' => $validatedData['admin_remarks'] ?? $paymentRequest->admin_remarks,
            ]);

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Student subscription approved successfully.',
                'data' => [
                    'payment_request' => $paymentRequest->fresh(['offeredProgram', 'user', 'userSubscription']),
                    'subscription' => $subscription->fresh(['offeredProgram', 'webUser']),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin approveStudentSubscriptionByAdmin failed: ' . $e->getMessage());

            return response()->json([
                'success' => -1,
                'error' => 'Server Error: Could not approve student subscription.',
            ], 500);
        }
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
