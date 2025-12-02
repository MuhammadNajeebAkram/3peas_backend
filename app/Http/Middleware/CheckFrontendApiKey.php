<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckFrontendApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providedApiKey = $request->header('x-frontend-api-key');
        //Log::info("provide Api key: ", ['key received' => $providedApiKey]);

        $validApiKey = config('services.frontend.api_key');
        //Log::info("system Api key: ", ['key' => $validApiKey]);
        if ($providedApiKey !== $validApiKey) {
           
            return response()->json(['message' => 'Unauthorized'], 401);
        }   
        return $next($request);
    }
}
