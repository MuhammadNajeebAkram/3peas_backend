<?php

namespace App\Http\Controllers;

use App\Http\Services\TeacherCollectionPermissionService;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTeacherCollectionPermissionController extends Controller
{
    public function show(int $teacherProfileId, TeacherCollectionPermissionService $permissions)
    {
        $teacher = TeacherProfile::with('webUser:id,name,email,phone,status,role')->findOrFail($teacherProfileId);
        $eligibility = $permissions->eligibility($teacher);
        $authorized = $permissions->canCollect($teacher);
        $history = DB::table('teacher_profile_events as events')
            ->leftJoin('users as admins', 'admins.id', '=', 'events.admin_id')
            ->where('events.teacher_profile_id', $teacher->id)
            ->whereIn('events.action', ['collection_permission_enabled', 'collection_permission_disabled'])
            ->orderByDesc('events.id')->limit(50)
            ->get(['events.id', 'events.action', 'events.admin_id', 'events.details', 'events.created_at', 'admins.name as admin_name']);

        return response()->json(['data' => [
            'teacher_profile_id' => $teacher->id,
            'name' => $teacher->webUser?->name,
            'teacher_code' => $teacher->teacher_code,
            'approval_status' => $teacher->status,
            'account_status' => $teacher->webUser?->status,
            'account_role' => $teacher->webUser?->role,
            'collection_code' => $teacher->collection_code,
            'collection_code_assigned' => filled($teacher->collection_code),
            'permission_enabled' => (bool) $teacher->can_collect_student_subscriptions,
            'currently_authorized' => $authorized,
            'eligible' => $eligibility['eligible'],
            'eligibility_reason' => $eligibility['reason'],
            'eligibility_reasons' => $eligibility['reasons'],
        ], 'history' => $history]);
    }

    public function enable(Request $request, int $teacherProfileId, TeacherCollectionPermissionService $permissions)
    {
        return $this->change($request, $teacherProfileId, true, $permissions);
    }

    public function disable(Request $request, int $teacherProfileId, TeacherCollectionPermissionService $permissions)
    {
        return $this->change($request, $teacherProfileId, false, $permissions);
    }

    private function change(Request $request, int $teacherProfileId, bool $enabled, TeacherCollectionPermissionService $permissions)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $result = $permissions->setPermission(
            $teacherProfileId,
            $enabled,
            (int) auth('api')->id(),
            trim($data['reason'])
        );

        return response()->json([
            'message' => $result['changed']
                ? ($enabled ? 'Collection permission enabled.' : 'Collection permission disabled.')
                : 'Collection permission already had the requested status.',
            'changed' => $result['changed'],
            'data' => [
                'teacher_profile_id' => $result['profile']->id,
                'permission_enabled' => (bool) $result['profile']->can_collect_student_subscriptions,
                'currently_authorized' => $permissions->canCollect($result['profile']),
                'eligible' => $result['eligibility']['eligible'],
            ],
        ]);
    }
}
