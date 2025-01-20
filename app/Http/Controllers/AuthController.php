<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    //
    public function register(Request $request) {
        try {
            $request->validate([
                'name' => 'required|string',                
                'email' => 'required|email|unique:users',
                'password' => 'required|min:8|confirmed',                
            ]);
            
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            //$token = JWTAuth::fromUser($user);
            
            return response()->json(['message' => 'User registered successfully', 'user' => $user, 'success' => 1, ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors(), 'success' => 0], 422);
        }
    }
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        
        $expiration = Auth::factory()->getTTL() * 60; // TTL in seconds
        if (!$token = Auth::attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = Auth::user();
       

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => $expiration,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,                
            ],
        ]);
    }
    public function logout()
    {
        Auth::logout();
        return response()->json(['message' => 'Successfully logged out']);
    }
    public function updatePassword(Request $request)
{
    try{
 // Validate the input
 $request->validate([
    'current_password' => 'required',
    'new_password' => 'required|min:8',
]);

$user = auth()->user();

// Check if the current password matches
if (!Hash::check($request->current_password, $user->password)) {
    return response()->json(['success' => 0, 'error' => 'Current password is incorrect'], 403);
}

// Update the password
$user->password = bcrypt($request->new_password);
$user->save();

return response()->json(['success' => 1, 'message' => 'Password updated successfully']);
    }
    catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json(['errors' => $e->errors(), 'success' => 2], 422);
    }
   
}
}
