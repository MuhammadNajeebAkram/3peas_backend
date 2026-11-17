<?php

namespace App\Http\Middleware;

use App\Models\TeacherProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows only authenticated, active teachers with an approved (active) teacher profile.
 * The resolved profile is attached to the request so controllers never trust a
 * teacher_profile_id supplied by the client.
 */
class EnsureTeacherActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'teacher') {
            return response()->json(['success' => false, 'code' => 'teacher_required',
                'message' => 'Teacher access required.'], 403);
        }

        if ($user->status !== 'active') {
            return response()->json(['success' => false, 'code' => 'teacher_account_inactive',
                'message' => 'Your teacher account is not active.'], 403);
        }

        $profile = TeacherProfile::where('web_user_id', $user->id)->first();

        if (!$profile || $profile->status !== 'active') {
            return response()->json(['success' => false, 'code' => 'teacher_not_approved',
                'teacher_status' => $profile?->status,
                'message' => 'Your teacher profile is pending administrative approval.'], 403);
        }

        $request->attributes->set('teacher_profile', $profile);

        return $next($request);
    }
}
