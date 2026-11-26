<?php

namespace App\Http\Controllers;

use App\Http\Services\WebUserRoleChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminWebUserRoleController extends Controller
{
    public function __construct(
        protected WebUserRoleChangeService $roleChangeService
    ) {
    }

    /**
     * GET /api/admin/auth/web-user/{id}/role-change-preview?target_role=teacher
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_role' => ['required', 'string', Rule::in(WebUserRoleChangeService::ALLOWED_ROLES)],
        ]);

        $data = $this->roleChangeService->previewRoleChange($id, $validated['target_role']);

        return response()->json([
            'success' => 1,
            'data' => $data,
        ]);
    }

    /**
     * POST /api/admin/auth/web-user/{id}/change-role
     */
    public function changeRole(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_role' => ['required', 'string', Rule::in(WebUserRoleChangeService::ALLOWED_ROLES)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'teacher_status' => ['sometimes', 'string', Rule::in(['active', 'pending'])],
            'city_id' => ['nullable', 'integer', 'exists:city_tbl,id'],
            'institute_id' => ['nullable', 'integer', 'exists:institute_tbl,id'],
        ]);

        $adminId = (int) auth('api')->id();
        $result = $this->roleChangeService->changeRole($id, $validated['target_role'], $adminId, $validated);

        return response()->json([
            'success' => 1,
            'message' => sprintf(
                'User role changed from %s to %s successfully.',
                $result['role_change']->from_role,
                $result['role_change']->to_role
            ),
            'data' => $result,
        ]);
    }
}
