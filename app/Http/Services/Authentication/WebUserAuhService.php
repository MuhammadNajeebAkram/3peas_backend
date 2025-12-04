<?php

namespace App\Http\Services\Authentication;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WebUserAuhService {
    public function login($request){
        $credentials = $request->only('email', 'password');
        $token = Auth::guard('web_api')->attempt($credentials);
        Log::info('Authentication', ['user' => $credentials['email'],
        'pass' => $credentials['password'], 'token' => $token]);
        if(!$token){
           return response()->json([
            'success' => 0,
            'error' => 'Unauthorized'
           ], 401);

        }
        $user = Auth::guard('web_api')->user();
        $expiration = config('jwt.ttl') * 60;

        return response()->json([
            'success' => 1,
            'token' => $token,
             'expires_in' => $expiration,           
            
        ]);

    }
}
