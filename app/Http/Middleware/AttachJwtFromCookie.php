<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AttachJwtFromCookie
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $context = null): Response
    {
        $cookieName = $this->resolveCookieName($context);
        $cookieToken = $request->cookie($cookieName);
        $guard = $this->resolveGuard($context);
        $bearerToken = $request->bearerToken();

        Log::info('Attaching JWT from cookie', [
            'context' => $context,
            'guard' => $guard,
            'cookie_name' => $cookieName,
            'has_cookie_token' => !empty($cookieToken),
            'has_bearer_token' => !empty($bearerToken),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (!empty($cookieToken) && !empty($bearerToken) && $cookieToken !== $bearerToken) {
            Log::warning('AttachJwtFromCookie token conflict detected', [
                'context' => $context,
                'guard' => $guard,
                'cookie_name' => $cookieName,
            ]);
        }

        // Prefer the context-specific cookie token so stale frontend Bearer
        // tokens do not override a valid cookie during page refreshes.
        if (!empty($cookieToken)) {
            Auth::shouldUse($guard);

            $authorizationHeader = 'Bearer ' . $cookieToken;

            $request->headers->set(
                'Authorization',
                $authorizationHeader
            );

            $request->server->set('HTTP_AUTHORIZATION', $authorizationHeader);
            $request->server->set('REDIRECT_HTTP_AUTHORIZATION', $authorizationHeader);

            try {
                $user = JWTAuth::setToken($cookieToken)->authenticate();

                if ($user) {
                    Auth::guard($guard)->setUser($user);
                }
            } catch (JWTException $e) {
                Log::warning('Failed to authenticate JWT from cookie', [
                    'context' => $context,
                    'guard' => $guard,
                    'cookie_name' => $cookieName,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }

    private function resolveCookieName(?string $context): string
    {
        return match ($context) {
            'admin' => env('ADMIN_JWT_COOKIE_NAME', 'admin_auth_token'),
            'lms' => env('LMS_JWT_COOKIE_NAME', 'lms_auth_token'),
            default => env('JWT_COOKIE_NAME', 'auth_token'),
        };
    }

    private function resolveGuard(?string $context): string
    {
        return match ($context) {
            'admin' => 'api',
            'lms' => 'web_api',
            default => config('auth.defaults.guard', 'web'),
        };
    }
}
