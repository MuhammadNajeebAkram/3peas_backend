<?php

namespace App\Http\Controllers\AdminAuth;

use App\Http\Controllers\Controller;
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
                return response()->json([
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            /** @var User|null $user */
            $user = Auth::guard('api')->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unable to authenticate user.',
                ], 401);
            }

            if (isset($user->is_active) && !$user->is_active) {
                Auth::guard('api')->logout();

                return response()->json([
                    'message' => 'Your account is inactive.',
                ], 403);
            }

            $user->load(['role', 'role.permissions']);

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

        $user->load(['role', 'role.permissions']);

        Log::info('Admin me authenticated', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout()
    {
        try {
            $token = JWTAuth::getToken();

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
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'permissions' => $user->role?->permissions?->pluck('name')->values()->all() ?? [],
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
}
