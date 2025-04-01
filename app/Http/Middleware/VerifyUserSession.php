<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VerifyUserSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // ✅ Check if email is verified
        if (!$user->email_verified_at) {
            return response()->json([
                'success' => 2,
                'error' => 'First verify your email'
            ], 403);
        }

        // ✅ Check if study session is active
        if ($user->study_session_id === 0) {
            return response()->json([
                'success' => 3,
                'error' => 'Your session has expired',
            ], 403);
        }

        $sessionTbl = DB::table('study_session_tbl')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->where('id', '=', $user->study_session_id)
            ->first();

        if (!$sessionTbl) {
            return response()->json([
                'success' => 4,
                'error' => 'Your study session has expired or is invalid'
            ], 403);
        }
        return $next($request);
    }
}
