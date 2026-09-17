<?php

namespace App\Http\Controllers;

use App\Http\Services\Authentication\WebUserAuthService;
use App\Http\Services\TeacherSettlementService;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherAccountController extends Controller
{
    public function register(Request $request, WebUserAuthService $auth)
    {
        $request->merge(['role' => 'teacher']);
        return $auth->registerWebUser($request);
    }

    public function google(Request $request, WebUserAuthService $auth)
    {
        $data = $request->validate(['idToken' => 'required|string']);
        return $auth->googleLogin($data['idToken'], 'teacher');
    }

    public function profile(): TeacherProfile
    {
        $user = auth('web_api')->user();
        abort_unless($user && $user->role === 'teacher' && $user->status === 'active', 403);
        return TeacherProfile::where('web_user_id', $user->id)->firstOrFail();
    }

    public function show()
    {
        return response()->json(['teacher_profile' => $this->profile()->load(['city:id,name', 'institute:id,name'])]);
    }

    public function update(Request $request)
    {
        $profile = $this->profile();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:30', Rule::unique('web_users', 'phone')->ignore($profile->web_user_id)],
            'city_id' => 'nullable|integer|exists:city_tbl,id',
            'institute_id' => 'nullable|integer|exists:institute_tbl,id',
            'status' => 'prohibited', 'collection_code' => 'prohibited', 'teacher_code' => 'prohibited',
        ]);
        DB::transaction(function () use ($profile, $data) {
            $profile->webUser->update(['name' => $data['name'], 'phone' => $data['phone']]);
            $profile->update(array_intersect_key($data, array_flip(['city_id', 'institute_id'])));
        });
        return $this->show();
    }

    public function dashboard(TeacherSettlementService $settlements)
    {
        $profile = $this->profile();
        return response()->json(['teacher_profile' => $profile,
            'can_collect' => $profile->status === 'active' && filled($profile->collection_code),
            'summary' => $settlements->summary($profile->id)]);
    }
}
