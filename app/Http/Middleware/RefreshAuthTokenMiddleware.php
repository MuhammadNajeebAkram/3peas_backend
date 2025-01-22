<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Carbon\Carbon;

class RefreshAuthTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Check if a token is present in the request
            if (!JWTAuth::getToken()) {
                return response()->json(['error' => 'Token not provided'], 401);
            }

            // Decode the token to get expiration time
            $payload = JWTAuth::parseToken()->getPayload();
            $exp = $payload->get('exp'); // Expiration timestamp
            $iat = $payload->get('iat'); // Issued at timestamp

            // Calculate remaining time and total lifetime
            $currentTime = Carbon::now()->timestamp;
            $totalLifetime = $exp - $iat; // Total lifetime of the token
            $remainingTime = $exp - $currentTime; // Remaining lifetime of the token

            // Refresh the token if less than 10% of its lifetime remains
            if ($remainingTime <= $totalLifetime * 0.1) {
                $newToken = JWTAuth::refresh();
                $request->headers->set('Authorization', 'Bearer ' . $newToken);

                $response = $next($request);
                return $response->header('Authorization', 'Bearer ' . $newToken);
            }

            // Authenticate the token
            JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            return response()->json(['error' => 'Token expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token parsing error'], 401);
        }

        // Proceed with the request if token is valid
        return $next($request);
    }
}
