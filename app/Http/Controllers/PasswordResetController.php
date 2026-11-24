<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * Send a password reset link to the given user's email.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json([
                    'success' => 1,
                    'message' => 'A password reset link has been sent to your email address.',
                ]);
            }

            return response()->json([
                'success' => 0,
                'message' => __($status),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('Password reset email error: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Unable to send password reset email at this time. Please try again later or contact support.',
            ], 500);
        }
    }

    /**
     * Reset the given user's password.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                    ])->save();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'success' => 1,
                    'message' => 'Your password has been reset successfully. You can now log in with your new password.',
                ]);
            }

            return response()->json([
                'success' => 0,
                'message' => __($status),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('Password reset execution error: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'An error occurred while resetting your password. Please try again.',
            ], 500);
        }
    }
}
