<?php

namespace App\Http\Middleware;

use App\Models\StudySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// NOTE: Removed 'use function Symfony\Component\Clock\now;' as Laravel uses the global now() helper

class EnsureStudySessionVerified
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Ensure user is authenticated (safety check, though auth middleware should precede this)
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userSessionId = $user->study_session_id;

        // 1. Check 1: Session ID Presence (Assuming 0 is the unassigned state)
        if ($userSessionId === 0) {
             return response()->json([
                 'success' => 0,
                 'message' => 'Access denied: No active session assigned.'
             ], 402); // 403 Forbidden
        }

        // 2. Check 2: Session Existence
        $userSession = StudySession::where('id', $userSessionId)->first();
        if (!$userSession) {
            return response()->json([
                'success' => 0,
                'message' => 'Access denied: The assigned session could not be found.'
            ], 402); // 403 Forbidden
        }

        // 3. Check 3: Activation Status (0 means inactive/expired by status flag)
        if ($userSession->activate === 0) {
            return response()->json([
                'success' => -1,
                'message' => 'Access denied: Your study session has been manually revoked.'
            ], 402); // 410 Gone
        }

        // 4. Check 4: Time Window Status
        // Assumes StudySession model has 'start_date' and 'end_date' fields cast as 'datetime'.
        $now = now(); 

        if ($userSession->start_date->lte($now) && $userSession->end_date->gte($now)) {
            // Success: Session is active and within the time window.
            return $next($request);
        }

        // Failure: Session is outside the valid time window (either hasn't started or has ended)
        $message = 'Access denied: Your study session time is invalid (either not started or expired).';
        
        // Optionally provide more detail:
        if ($userSession->start_date->gt($now)) {
            $message = 'Access denied: Your study session has not started yet.';
        } elseif ($userSession->end_date->lt($now)) {
            $message = 'Access denied: Your study session has expired.';
        }

        return response()->json([
            'success' => -2, // Distinct error code for time-based failure
            'message' => $message
        ], 410); // 410 Gone for an expired resource
    }
}