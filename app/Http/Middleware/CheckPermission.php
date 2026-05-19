<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $permissions = collect($permissions)
            ->flatMap(fn ($permission) => explode(',', $permission))
            ->map(fn ($permission) => trim($permission))
            ->filter()
            ->values();

        if ($permissions->isEmpty()) {
            return response()->json([
                'message' => 'Permission name is required.',
            ], 500);
        }

        $user->loadMissing('role.permissions');

        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $user->role?->name)));

        if ($roleName === 'super_admin') {
            return $next($request);
        }

        $userPermissions = $user->role?->permissions
            ?->pluck('name')
            ->all() ?? [];

        if (!array_intersect($permissions->all(), $userPermissions)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
