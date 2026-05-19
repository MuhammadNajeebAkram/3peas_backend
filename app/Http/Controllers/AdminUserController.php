<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function getAdminUsers()
    {
        try {
            $users = User::with('role:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn ($user) => $this->userPayload($user));

            return response()->json([
                'success' => 1,
                'users' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveAdminUser(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role_id' => $validated['role_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $user->load('role:id,name,display_name');

            return response()->json([
                'success' => 1,
                'message' => 'Admin user saved successfully.',
                'user' => $this->userPayload($user),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateAdminUser(Request $request, $id)
    {
        $id = $id ?? $request->id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $user = User::findOrFail($id);
            $user->name = $validated['name'];
            $user->email = $validated['email'];

            if (array_key_exists('role_id', $validated)) {
                $user->role_id = $validated['role_id'];
            }

            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            if (array_key_exists('is_active', $validated)) {
                if ((int) $user->id === (int) $request->user()?->id && !$validated['is_active']) {
                    return response()->json([
                        'success' => 0,
                        'message' => 'You cannot deactivate your own admin account.',
                    ], 422);
                }

                $user->is_active = $validated['is_active'];
            }

            $user->save();
            $user->load('role:id,name,display_name');

            return response()->json([
                'success' => 1,
                'message' => 'Admin user updated successfully.',
                'user' => $this->userPayload($user),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activateAdminUser(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'is_active' => ['required_without:activate', 'boolean'],
            'activate' => ['required_without:is_active', 'boolean'],
        ]);

        try {
            $user = User::findOrFail($validated['id']);
            if ((int) $user->id === (int) $request->user()?->id && !(array_key_exists('is_active', $validated) ? $validated['is_active'] : $validated['activate'])) {
                return response()->json([
                    'success' => 0,
                    'message' => 'You cannot deactivate your own admin account.',
                ], 422);
            }

            $user->is_active = array_key_exists('is_active', $validated)
                ? $validated['is_active']
                : $validated['activate'];
            $user->save();
            $user->load('role:id,name,display_name');

            return response()->json([
                'success' => 1,
                'message' => 'Admin user status updated successfully.',
                'user' => $this->userPayload($user),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
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
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
