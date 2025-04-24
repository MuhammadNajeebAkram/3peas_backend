<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use App\Models\WebUser;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, $id, $hash)
    {
        $user = WebUser::findOrFail($id);

       

        // Check if hash matches the user's email
        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link'], 400);
        }

        // If already verified
        if ($user->hasVerifiedEmail()) {
            //return redirect()->away("http://localhost:3000");
            return redirect()->away("https://lms.al-faraabi.com");
            //return response()->json(['message' => 'Email already verified.'], 200);
        }

        // Mark as verified
        $user->markEmailAsVerified();
        event(new Verified($user));

        // Generate a new JWT token after verification
       /* $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Email verified successfully.',
            'token' => $token
        ], 200);*/
        
        //return redirect()->away("http://localhost:3000");
        return redirect()->away("https://lms.al-faraabi.com");
        //return redirect()->away("https://login.pakistanpastpapers.com/payment?user_id={$user->id}");
        //return redirect()->away("http://localhost:3000/payment?user_id={$user->id}");
    }

    public function resend(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->hasVerifiedEmail()) {
             //return redirect()->away("http://localhost:3000");
        return redirect()->away("https://lms.al-faraabi.com");
            //return response()->json(['message' => 'Email already verified.'], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email resent.'], 200);
    }

    public function checkEmailVerification($email)
{
    try {
        // Check if the email is verified
        $verification = DB::table('web_users')
            ->where('email', $email)
            ->whereNotNull('email_verified_at')
            ->first();

        if (!$verification) {
            return response()->json([
                'success' => 2 // Email not verified
            ]);
        }

        // Fetch study session ID correctly
        $session = DB::table('web_users')
            ->where('email', $email)
            ->select('study_session_id')
            ->first();

        if (!$session || !$session->study_session_id) {
            return response()->json([
                'success' => 3 // No valid session ID found
            ]);
        }

        // Check if session is valid
        $valid_session = DB::table('study_session_tbl')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->where('id', $session->study_session_id) // Using correct ID
            ->first();

        if (!$valid_session) {
            return response()->json([
                'success' => 3 // Session not valid
            ]);
        }

        return response()->json([
            'success' => 1 // All checks passed
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'success' => -1,
        ]);
    }
}

}
