<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\DashboardItem;
use App\Models\RoleDashboardItem;
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
                'dashboardItems:id,code,title,category,widget_type,width,sort_order,is_active',
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
            'dashboard_item_ids' => ['nullable', 'array'],
            'dashboard_item_ids.*' => ['integer', 'exists:dashboard_items,id'],
            'dashboard_items' => ['nullable', 'array'],
            'dashboard_items.*.dashboard_item_id' => ['required', 'integer', 'exists:dashboard_items,id'],
            'dashboard_items.*.is_visible' => ['nullable', 'boolean'],
            'dashboard_items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'dashboard_items.*.settings' => ['nullable', 'array'],
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

            $this->syncDashboardItemsFromPayload($role, $validated);

            DB::commit();

            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name', 'dashboardItems:id,code,title,category,widget_type,width,sort_order,is_active']);

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
            'dashboard_item_ids' => ['nullable', 'array'],
            'dashboard_item_ids.*' => ['integer', 'exists:dashboard_items,id'],
            'dashboard_items' => ['nullable', 'array'],
            'dashboard_items.*.dashboard_item_id' => ['required', 'integer', 'exists:dashboard_items,id'],
            'dashboard_items.*.is_visible' => ['nullable', 'boolean'],
            'dashboard_items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'dashboard_items.*.settings' => ['nullable', 'array'],
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

            $this->syncDashboardItemsFromPayload($role, $validated);

            DB::commit();

            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name', 'dashboardItems:id,code,title,category,widget_type,width,sort_order,is_active']);

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

    public function syncRoleDashboardItems(Request $request, $id)
    {
        $validated = $request->validate([
            'dashboard_item_ids' => ['nullable', 'array'],
            'dashboard_item_ids.*' => ['integer', 'exists:dashboard_items,id'],
            'dashboard_items' => ['nullable', 'array'],
            'dashboard_items.*.dashboard_item_id' => ['required', 'integer', 'exists:dashboard_items,id'],
            'dashboard_items.*.is_visible' => ['nullable', 'boolean'],
            'dashboard_items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'dashboard_items.*.settings' => ['nullable', 'array'],
        ]);

        try {
            $role = Role::findOrFail($id);

            if ($this->isProtectedRole($role)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Super admin dashboard items cannot be modified.',
                    'role' => $this->rolePayload($role->load(['permissions:id,name', 'permissionScopes.permission:id,name', 'dashboardItems:id,code,title,category,widget_type,width,sort_order,is_active'])),
                ], 403);
            }

            $this->syncDashboardItemsFromPayload($role, $validated);
            $role->load(['permissions:id,name', 'permissionScopes.permission:id,name', 'dashboardItems:id,code,title,category,widget_type,width,sort_order,is_active']);

            return response()->json([
                'success' => 1,
                'message' => 'Role dashboard items updated successfully.',
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
            $dashboardItems = DashboardItem::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get(['id', 'code', 'title', 'category', 'widget_type', 'width', 'sort_order', 'description']);

            return response()->json([
                'success' => 1,
                'permissions' => $permissions,
                'dashboard_items' => $dashboardItems,
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
            'dashboard_items' => $role->dashboardItems
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'title' => $item->title,
                    'category' => $item->category,
                    'widget_type' => $item->widget_type,
                    'width' => $item->width,
                    'is_active' => (bool) $item->is_active,
                    'is_visible' => (bool) ($item->pivot?->is_visible ?? true),
                    'sort_order' => (int) ($item->pivot?->sort_order ?? $item->sort_order ?? 0),
                    'settings' => $item->pivot?->settings ? json_decode($item->pivot->settings, true) : null,
                ])
                ->sortBy('sort_order')
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

    private function syncDashboardItemsFromPayload(Role $role, array $validated): void
    {
        if (array_key_exists('dashboard_items', $validated)) {
            $this->syncDashboardItems($role, $validated['dashboard_items']);
            return;
        }

        if (array_key_exists('dashboard_item_ids', $validated)) {
            $this->syncDashboardItems(
                $role,
                collect($validated['dashboard_item_ids'])
                    ->map(fn ($dashboardItemId, $index) => [
                        'dashboard_item_id' => $dashboardItemId,
                        'is_visible' => true,
                        'sort_order' => $index,
                    ])
                    ->all()
            );
        }
    }

    private function syncDashboardItems(Role $role, array $dashboardItems): void
    {
        $role->roleDashboardItems()->delete();

        collect($dashboardItems)
            ->unique('dashboard_item_id')
            ->values()
            ->each(function ($item, $index) use ($role) {
                RoleDashboardItem::create([
                    'role_id' => $role->id,
                    'dashboard_item_id' => $item['dashboard_item_id'],
                    'is_visible' => $item['is_visible'] ?? true,
                    'sort_order' => $item['sort_order'] ?? $index,
                    'settings' => $item['settings'] ?? null,
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
