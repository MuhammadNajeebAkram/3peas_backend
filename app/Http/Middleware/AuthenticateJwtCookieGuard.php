<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthenticateJwtCookieGuard
{
    public function handle(Request $request, Closure $next, ?string $context = null): Response
    {
        $guard = $this->resolveGuard($context);
        $token = $request->bearerToken() ?: $request->cookie($this->resolveCookieName($context));

        Auth::shouldUse($guard);

        Log::info('AuthenticateJwtCookieGuard invoked', [
            'context' => $context,
            'guard' => $guard,
            'has_token' => !empty($token),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (empty($token)) {
            Log::warning('AuthenticateJwtCookieGuard missing token', [
                'context' => $context,
                'guard' => $guard,
            ]);

            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $jwtGuard = Auth::guard($guard);

            if (!method_exists($jwtGuard, 'setToken')) {
                Log::warning('AuthenticateJwtCookieGuard guard does not support setToken', [
                    'context' => $context,
                    'guard' => $guard,
                    'guard_class' => get_class($jwtGuard),
                ]);

                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $user = $jwtGuard->setToken($token)->user();

            if (!$user) {
                Log::warning('AuthenticateJwtCookieGuard user not resolved', [
                    'context' => $context,
                    'guard' => $guard,
                ]);

                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            Auth::shouldUse($guard);
            Auth::guard($guard)->setUser($user);

            Log::info('AuthenticateJwtCookieGuard authenticated user', [
                'context' => $context,
                'guard' => $guard,
                'user_id' => $user->id,
            ]);
        } catch (JWTException) {
            Log::warning('AuthenticateJwtCookieGuard JWT failure', [
                'context' => $context,
                'guard' => $guard,
            ]);

            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }

    private function resolveGuard(?string $context): string
    {
        return match ($context) {
            'admin' => 'api',
            'lms' => 'web_api',
            default => config('auth.defaults.guard', 'web'),
        };
    }

    private function resolveCookieName(?string $context): string
    {
        return match ($context) {
            'admin' => env('ADMIN_JWT_COOKIE_NAME', 'admin_auth_token'),
            'lms' => env('LMS_JWT_COOKIE_NAME', 'lms_auth_token'),
            default => env('JWT_COOKIE_NAME', 'auth_token'),
        };
    }
}
