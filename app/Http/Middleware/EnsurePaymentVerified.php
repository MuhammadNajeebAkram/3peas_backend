<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class EnsurePaymentVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $session_id = $user->study_session_id;

        $sessionTbl = DB::table('study_session_tbl')
        ->where('start_date', '<=', now())
        ->where('end_date', '>=', now())
        ->where('id', '=', $session_id)
        ->first();

        if(!$sessionTbl){
            return response()->json([
                'paymentVerified' => 0, 
            ]);

        }

        return $next($request);
    }
}
