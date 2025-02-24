<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\WebUserProfile;
use App\Models\WebUser;
use Illuminate\Support\Facades\DB;

class UserProfileController extends Controller
{
    //
    public function getAwaitedUsers(Request $request){
        try{

            $users = WebUser::with('profile')
    ->where('study_session_id', 0)
    ->get()
    ->map(function ($user) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,           
            'phone' => $user->profile->phone ?? null,     // Access from related model
            'slip' => $user->paymentSlip->name ?? null, // Payment slip number
        ];
    });

    return response()->json([
        'success' => 1,
        'users' => $users,
    ]);

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getmessage(),
            ]);

        }
    }

    public function activateAwaitedUser(Request $request){
        try{
            $class = DB::table('user_profile_tbl')
            ->where('user_id', $request->user_id)
            ->select('class_id')
            ->first();

            $class_id = $class->class_id;

            $sessionId = DB::table('study_session_tbl')
            ->where('class_id', $class_id)
            ->where('activate', 1)
            ->select('id')
            ->first();

            $session_id = $sessionId->id;

            $user = DB::table('web_users')
            ->where('id', $request->user_id)
            ->update([
                'study_session_id' => $session_id,
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1,
            ]);

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0,
                'error' => $e->getmessage(),
            ]);

        }
    }
}
