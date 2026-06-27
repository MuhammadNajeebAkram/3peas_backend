<?php

namespace App\Http\Controllers\AdminAuth;

use App\Http\Controllers\Controller;
use App\Models\AdminLoginLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        Log::info('Admin login attempt', [
            'email' => $request->input('email'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            $normalizedEmail = Str::lower(trim($request->input('email')));
            $matchedUser = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->first();

            $credentials = [
                'email' => $matchedUser?->email ?? $normalizedEmail,
                'password' => $request->input('password'),
            ];
            $token = Auth::guard('api')->attempt($credentials);

            if (!$token) {
                $this->recordLoginLog($request, [
                    'user_id' => $matchedUser?->id,
                    'email' => $matchedUser?->email ?? $normalizedEmail,
                    'status' => 'failed',
                    'failure_reason' => 'invalid_credentials',
                ]);

                return response()->json([
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            /** @var User|null $user */
            $user = Auth::guard('api')->user();

            if (!$user) {
                $this->recordLoginLog($request, [
                    'email' => $matchedUser?->email ?? $normalizedEmail,
                    'status' => 'failed',
                    'failure_reason' => 'user_not_resolved',
                ]);

                return response()->json([
                    'message' => 'Unable to authenticate user.',
                ], 401);
            }

            if (isset($user->is_active) && !$user->is_active) {
                $this->recordLoginLog($request, [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'status' => 'inactive',
                    'failure_reason' => 'account_inactive',
                    'token_jti' => $this->tokenJti($token),
                    'login_at' => now(),
                ]);

                Auth::guard('api')->logout();

                return response()->json([
                    'message' => 'Your account is inactive.',
                ], 403);
            }

            $user->load(['role', 'role.permissions', 'role.permissionScopes.permission', 'adminProfile', 'adminPreference']);

            $this->recordLoginLog($request, [
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => 'success',
                'token_jti' => $this->tokenJti($token),
                'login_at' => now(),
            ]);

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'expires_in' => (int) config('jwt.ttl') * 60,
                'user' => $this->userPayload($user),
            ])->cookie(
                $this->cookieName(),
                $token,
                60 * 24 * 7,
                '/api',
                $this->cookieDomain(),
                $this->cookieSecure(),
                true,
                false,
                'Lax'
            );
        } catch (Throwable $e) {
            Log::error('Admin login failed', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Login failed. Please try again.',
            ], 500);
        }
    }

    public function me(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::guard('api')->user();
        if (!$user) {
            Log::warning('Admin me unauthorized', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'has_cookie' => $request->hasCookie($this->cookieName()),
                'has_bearer' => !empty($request->bearerToken()),
            ]);

            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $user->load(['role', 'role.permissions', 'role.permissionScopes.permission', 'adminProfile', 'adminPreference']);

        Log::info('Admin me authenticated', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            $token = JWTAuth::getToken();
            $tokenJti = $this->tokenJti($token ? (string) $token : null);

            $this->recordLogout($user?->id, $tokenJti);

            if ($token) {
                JWTAuth::invalidate($token);
            }
        } catch (Throwable $e) {
            Log::warning('Admin logout token invalidate failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ])->withoutCookie(
            $this->cookieName(),
            '/api',
            $this->cookieDomain()
        );
    }

    private function userPayload(User $user): array
    {
        $roleName = $user->role?->name;
        $normalizedRoleName = str_replace(['-', ' '], '_', strtolower(trim((string) $roleName)));
        $permissions = $normalizedRoleName === 'super_admin'
            ? Permission::orderBy('name')->pluck('name')->values()->all()
            : ($user->role?->permissions?->pluck('name')->values()->all() ?? []);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role' => $roleName,
            'role_display_name' => $user->role?->display_name,
            'profile' => $user->adminProfile ? [
                'id' => $user->adminProfile->id,
                'phone' => $user->adminProfile->phone,
                'alternate_phone' => $user->adminProfile->alternate_phone,
                'avatar_url' => $user->adminProfile->avatar_url,
                'designation' => $user->adminProfile->designation,
                'department' => $user->adminProfile->department,
                'bio' => $user->adminProfile->bio,
                'address' => $user->adminProfile->address,
                'city' => $user->adminProfile->city,
                'province' => $user->adminProfile->province,
                'country' => $user->adminProfile->country,
                'timezone' => $user->adminProfile->timezone,
                'locale' => $user->adminProfile->locale,
                'notification_preferences' => $user->adminProfile->notification_preferences,
                'emergency_contact_name' => $user->adminProfile->emergency_contact_name,
                'emergency_contact_phone' => $user->adminProfile->emergency_contact_phone,
                'bank_name' => $user->adminProfile->bank_name,
                'bank_account_no' => $user->adminProfile->bank_account_no,
                'bank_iban_no' => $user->adminProfile->bank_iban_no,
            ] : null,
            'preferences' => $user->adminPreference ? [
                'theme_mode' => $user->adminPreference->theme_mode,
                'primary_color' => $user->adminPreference->primary_color,
                'sidebar_state' => $user->adminPreference->sidebar_state,
                'sidebar_pinned' => (bool) $user->adminPreference->sidebar_pinned,
                'sidebar_width' => $user->adminPreference->sidebar_width,
                'topbar_density' => $user->adminPreference->topbar_density,
                'default_landing_page' => $user->adminPreference->default_landing_page,
                'language' => $user->adminPreference->language,
                'text_direction' => $user->adminPreference->text_direction,
                'timezone' => $user->adminPreference->timezone,
                'date_format' => $user->adminPreference->date_format,
                'time_format' => $user->adminPreference->time_format,
                'table_rows_per_page' => $user->adminPreference->table_rows_per_page,
                'table_density' => $user->adminPreference->table_density,
                'sticky_table_header' => (bool) $user->adminPreference->sticky_table_header,
                'remember_filters' => (bool) $user->adminPreference->remember_filters,
                'remember_sorting' => (bool) $user->adminPreference->remember_sorting,
                'editor_default_language' => $user->adminPreference->editor_default_language,
                'editor_text_direction' => $user->adminPreference->editor_text_direction,
                'editor_font_family' => $user->adminPreference->editor_font_family,
                'editor_toolbar_mode' => $user->adminPreference->editor_toolbar_mode,
                'auto_save_enabled' => (bool) $user->adminPreference->auto_save_enabled,
                'auto_save_interval_seconds' => $user->adminPreference->auto_save_interval_seconds,
                'dashboard_layout' => $user->adminPreference->dashboard_layout,
                'dashboard_refresh_interval' => $user->adminPreference->dashboard_refresh_interval,
                'dashboard_date_range' => $user->adminPreference->dashboard_date_range,
                'dashboard_compact_mode' => (bool) $user->adminPreference->dashboard_compact_mode,
                'notification_settings' => $user->adminPreference->notification_settings,
                'module_preferences' => $user->adminPreference->module_preferences,
            ] : null,
            'is_super_admin' => $normalizedRoleName === 'super_admin',
            'permissions' => $permissions,
            'permission_scopes' => $user->role?->permissionScopes
                ?->map(fn ($scope) => [
                    'permission_id' => $scope->permission_id,
                    'permission_name' => $scope->permission?->name,
                    'scope_type' => $scope->scope_type,
                    'scope_id' => $scope->scope_id,
                ])
                ->values()
                ->all() ?? [],
        ];
    }

    private function cookieName(): string
    {
        return env('ADMIN_JWT_COOKIE_NAME', 'admin_auth_token');
    }

    private function cookieDomain(): ?string
    {
        if (app()->environment('production')) {
            return 'api.thestudentstimes.com';
        }

        return null;
    }

    private function cookieSecure(): bool
    {
        return app()->environment('production');
    }

    private function recordLoginLog(Request $request, array $data): void
    {
        try {
            AdminLoginLog::create(array_merge([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'login_at' => now(),
            ], $data));
        } catch (Throwable $e) {
            Log::warning('Admin login log write failed', [
                'message' => $e->getMessage(),
                'email' => $data['email'] ?? null,
                'status' => $data['status'] ?? null,
            ]);
        }
    }

    private function recordLogout(?int $userId, ?string $tokenJti): void
    {
        if (!$userId && !$tokenJti) {
            return;
        }

        try {
            $query = AdminLoginLog::query()
                ->where('status', 'success')
                ->whereNull('logout_at');

            if ($tokenJti) {
                $query->where('token_jti', $tokenJti);
            } elseif ($userId) {
                $query->where('user_id', $userId);
            }

            $query->latest('login_at')->first()?->update([
                'logout_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Admin logout log update failed', [
                'message' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }

    private function tokenJti(?string $token): ?string
    {
        if (!$token) {
            return null;
        }

        try {
            $jti = JWTAuth::setToken($token)->getPayload()->get('jti');

            return $jti ? (string) $jti : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
