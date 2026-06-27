<?php

namespace App\Http\Controllers;

use App\Models\AdminUserProfile;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserProfileController extends Controller
{
    public function show(Request $request)
    {
        return $this->showByUser($request, $request->user()?->id);
    }

    public function showByUser(Request $request, $userId)
    {
        try {
            $user = User::with(['role:id,name,display_name', 'adminProfile'])->findOrFail($userId);

            return response()->json([
                'success' => 1,
                'user' => $this->userPayload($user),
                'profile' => $this->profilePayload($user->adminProfile),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request)
    {
        return $this->updateByUser($request, $request->user()?->id);
    }

    public function updateByUser(Request $request, $userId)
    {
        $validated = $this->validateProfile($request);

        try {
            $user = User::findOrFail($userId);
            $profile = AdminUserProfile::updateOrCreate(
                ['user_id' => $user->id],
                $validated
            );

            return response()->json([
                'success' => 1,
                'message' => 'Admin user profile updated successfully.',
                'profile' => $this->profilePayload($profile),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:20'],
            'notification_preferences' => ['nullable', 'array'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_iban_no' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role' => $user->role?->name,
            'role_display_name' => $user->role?->display_name,
            'is_active' => (bool) ($user->is_active ?? true),
        ];
    }

    private function profilePayload(?AdminUserProfile $profile): ?array
    {
        if (!$profile) {
            return null;
        }

        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'phone' => $profile->phone,
            'alternate_phone' => $profile->alternate_phone,
            'avatar_url' => $profile->avatar_url,
            'designation' => $profile->designation,
            'department' => $profile->department,
            'bio' => $profile->bio,
            'address' => $profile->address,
            'city' => $profile->city,
            'province' => $profile->province,
            'country' => $profile->country,
            'timezone' => $profile->timezone,
            'locale' => $profile->locale,
            'notification_preferences' => $profile->notification_preferences,
            'emergency_contact_name' => $profile->emergency_contact_name,
            'emergency_contact_phone' => $profile->emergency_contact_phone,
            'bank_name' => $profile->bank_name,
            'bank_account_no' => $profile->bank_account_no,
            'bank_iban_no' => $profile->bank_iban_no,
            'created_by' => $profile->created_by,
            'updated_by' => $profile->updated_by,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ];
    }
}
