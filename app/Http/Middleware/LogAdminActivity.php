<?php

namespace App\Http\Middleware;

use App\Models\AdminActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class LogAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldLog($request, $response)) {
            $this->logActivity($request);
        }

        return $response;
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (!$request->isMethod('post') && !$request->isMethod('put') && !$request->isMethod('patch') && !$request->isMethod('delete')) {
            return false;
        }

        if (!$response->isSuccessful()) {
            return false;
        }

        if (!Schema::hasTable('admin_activity_logs')) {
            return false;
        }

        $path = trim($request->path(), '/');

        return !str_contains($path, 'admin-activity-logs')
            && !str_contains($path, 'admin-login-logs')
            && !str_contains($path, 'data-entry-assignments')
            && !str_ends_with($path, 'admin/auth/logout')
            && !str_contains($path, 'media/presign');
    }

    private function logActivity(Request $request): void
    {
        try {
            $adminUserId = $this->authenticatedUserId($request);

            if (!$adminUserId) {
                return;
            }

            AdminActivityLog::create([
                'admin_user_id' => $adminUserId,
                'action' => $this->resolveAction($request),
                'module' => $this->resolveModule($request),
                'reference_type' => $this->resolveReferenceType($request),
                'reference_id' => $this->resolveReferenceId($request),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function authenticatedUserId(Request $request): ?int
    {
        $user = $request->user()
            ?: auth('api')->user()
            ?: auth('web_api')->user()
            ?: auth()->user();

        return $user?->id ? (int) $user->id : null;
    }

    private function resolveAction(Request $request): string
    {
        $segments = $this->adminPathSegments($request);
        $leaf = strtolower((string) end($segments));
        $controllerMethod = strtolower((string) $request->route()?->getActionMethod());

        $actionSource = $leaf . ' ' . $controllerMethod;

        return match (true) {
            str_contains($actionSource, 'update') => 'updated',
            str_contains($actionSource, 'delete') => 'deleted',
            str_contains($actionSource, 'activate') => 'status_updated',
            str_contains($actionSource, 'approve') => 'approved',
            str_contains($actionSource, 'review') => 'reviewed',
            str_contains($actionSource, 'sync') => 'synced',
            str_contains($actionSource, 'payment') || str_contains($actionSource, 'pay') => 'payment_recorded',
            str_contains($actionSource, 'add') || str_contains($actionSource, 'save') || str_contains($actionSource, 'create') => 'created',
            $request->isMethod('delete') => 'deleted',
            default => 'changed',
        };
    }

    private function resolveModule(Request $request): string
    {
        $segments = $this->adminPathSegments($request);

        return substr($segments[0] ?? 'admin', 0, 100);
    }

    private function resolveReferenceType(Request $request): ?string
    {
        $controller = $request->route()?->getControllerClass();

        return $controller ? substr($controller, 0, 100) : null;
    }

    private function resolveReferenceId(Request $request): ?int
    {
        foreach (['id', 'user', 'user_id', 'itemId', 'item_id', 'topic_id'] as $key) {
            $value = $request->route($key) ?? $request->input($key);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function adminPathSegments(Request $request): array
    {
        $segments = $request->segments();
        $adminAuthIndex = array_search('auth', $segments, true);

        if ($adminAuthIndex !== false && ($segments[$adminAuthIndex - 1] ?? null) === 'admin') {
            return array_values(array_slice($segments, $adminAuthIndex + 1));
        }

        return array_values(array_slice($segments, 2));
    }
}
