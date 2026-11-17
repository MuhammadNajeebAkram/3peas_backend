<?php

namespace App\Http\Middleware;

use App\Http\Services\TeacherSubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $profile = $request->attributes->get('teacher_profile');
        abort_unless($profile, 403, 'Teacher access required.');
        app(TeacherSubscriptionService::class)->requireFeature($profile->id, $feature);
        return $next($request);
    }
}
