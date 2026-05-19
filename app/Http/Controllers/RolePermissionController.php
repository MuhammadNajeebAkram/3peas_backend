<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    private const SCOPE_TYPES = [
        'curriculum_board',
        'class',
        'subject',
        'book',
        'unit',
        'topic',
        'question_type',
        'cognitive_domain',
        'topic_content',
    ];

    public function getRoles()
    {
        try {
            $roles = Role::with([
                'permissions:id,name',
                'permissionScopes.permission:id,name',
            ])
                ->orderBy('name')
                ->get()
                ->map(fn ($role) => $this->rolePayload($role));

            return response()->json([
                'success' => 1,
                'roles' => $roles,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveRole(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
            'permission_scopes' => ['nullable', 'array'],
            'permission_scopes.*.permission_id' => ['required', 'integer', 'exists:permissions,id'],
            'permission_scopes.*.scope_type' => ['required', 'string', Rule::in(self::SCOPE_TYPES)],
            'permission_scopes.*.scope_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            if ($this->isProtectedRoleName($validated['name'])) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Super admin role is protected.',
                ], 403);
            }

            DB::beginTransaction();

            $role = Role::create([
                'name' => $validated['name'],
                'display_name' => $validated['display_name'] ?? null,
            ]);

            if (array_key_exists('permission_ids', $validated)) {
                $role->permissions()->sync($validated['permission_ids']);
            }

            if (array_key_exists('permission_scopes', $validated)) {
                $this->syncPermissionScopes($role, $validated['permission_scopes']);
            }

            DB::commit();

            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name']);

            return response()->json([
                'success' => 1,
                'message' => 'Role saved successfully.',
                'role' => $this->rolePayload($role),
            ]);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateRole(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($id)],
            'display_name' => ['nullable', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
            'permission_scopes' => ['nullable', 'array'],
            'permission_scopes.*.permission_id' => ['required', 'integer', 'exists:permissions,id'],
            'permission_scopes.*.scope_type' => ['required', 'string', Rule::in(self::SCOPE_TYPES)],
            'permission_scopes.*.scope_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $role = Role::findOrFail($id);

            if ($this->isProtectedRole($role) || $this->isProtectedRoleName($validated['name'])) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Super admin role cannot be modified.',
                    'role' => $this->rolePayload($role->load(['permissions:id,name', 'permissionScopes.permission:id,name'])),
                ], 403);
            }

            DB::beginTransaction();

            $role->name = $validated['name'];
            $role->display_name = $validated['display_name'] ?? null;
            $role->save();

            if (array_key_exists('permission_ids', $validated)) {
                $role->permissions()->sync($validated['permission_ids']);
            }

            if (array_key_exists('permission_scopes', $validated)) {
                $this->syncPermissionScopes($role, $validated['permission_scopes']);
            }

            DB::commit();

            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name']);

            return response()->json([
                'success' => 1,
                'message' => 'Role updated successfully.',
                'role' => $this->rolePayload($role),
            ]);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteRole($id)
    {
        try {
            $role = Role::findOrFail($id);

            if ($this->isProtectedRole($role)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Super admin role cannot be deleted.',
                ], 403);
            }

            $role->delete();

            return response()->json([
                'success' => 1,
                'message' => 'Role deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncRolePermissions(Request $request, $id)
    {
        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        try {
            $role = Role::findOrFail($id);

            if ($this->isProtectedRole($role)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Super admin permissions cannot be modified.',
                    'role' => $this->rolePayload($role->load(['permissions:id,name', 'permissionScopes.permission:id,name'])),
                ], 403);
            }

            $role->permissions()->sync($validated['permission_ids']);
            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name']);

            return response()->json([
                'success' => 1,
                'message' => 'Role permissions updated successfully.',
                'role' => $this->rolePayload($role),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncRolePermissionScopes(Request $request, $id)
    {
        $validated = $request->validate([
            'permission_scopes' => ['required', 'array'],
            'permission_scopes.*.permission_id' => ['required', 'integer', 'exists:permissions,id'],
            'permission_scopes.*.scope_type' => ['required', 'string', Rule::in(self::SCOPE_TYPES)],
            'permission_scopes.*.scope_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $role = Role::findOrFail($id);

            if ($this->isProtectedRole($role)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Super admin permission scopes cannot be modified.',
                    'role' => $this->rolePayload($role->load(['permissions:id,name', 'permissionScopes.permission:id,name'])),
                ], 403);
            }

            $this->syncPermissionScopes($role, $validated['permission_scopes']);
            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name']);

            return response()->json([
                'success' => 1,
                'message' => 'Role permission scopes updated successfully.',
                'role' => $this->rolePayload($role),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPermissions()
    {
        try {
            $permissions = Permission::orderBy('name')->get(['id', 'name']);

            return response()->json([
                'success' => 1,
                'permissions' => $permissions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function rolePayload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'permissions' => $role->permissions
                ->map(fn ($permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                ])
                ->values(),
            'permission_scopes' => $role->permissionScopes
                ->map(fn ($scope) => [
                    'id' => $scope->id,
                    'permission_id' => $scope->permission_id,
                    'permission_name' => $scope->permission?->name,
                    'scope_type' => $scope->scope_type,
                    'scope_id' => $scope->scope_id,
                ])
                ->values(),
        ];
    }

    private function syncPermissionScopes(Role $role, array $permissionScopes): void
    {
        $role->permissionScopes()->delete();

        collect($permissionScopes)
            ->unique(fn ($scope) => $scope['permission_id'] . ':' . $scope['scope_type'] . ':' . $scope['scope_id'])
            ->each(function ($scope) use ($role) {
                RolePermissionScope::create([
                    'role_id' => $role->id,
                    'permission_id' => $scope['permission_id'],
                    'scope_type' => $scope['scope_type'],
                    'scope_id' => $scope['scope_id'],
                ]);
            });
    }

    private function isProtectedRole(Role $role): bool
    {
        return $this->isProtectedRoleName($role->name);
    }

    private function isProtectedRoleName(?string $name): bool
    {
        $normalizedName = str_replace(['-', ' '], '_', strtolower(trim((string) $name)));

        return $normalizedName === 'super_admin';
    }
}
