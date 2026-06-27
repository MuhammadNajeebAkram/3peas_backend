<?php

namespace App\Http\Controllers;

use App\Models\AdminUserPreference;
use App\Models\DashboardItem;
use App\Models\User;
use App\Models\UserDashboardItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminUserPreferenceController extends Controller
{
    public function show(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (!$user) {
            return response()->json([
                'success' => 0,
                'message' => 'Unauthorized.',
            ], 401);
        }

        try {
            return response()->json([
                'success' => 1,
                'preferences' => $this->preferencePayload($this->preferenceForUser($user->id)),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve admin user preferences.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (!$user) {
            return response()->json([
                'success' => 0,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $this->validatePreferences($request);

        try {
            $preference = $this->preferenceForUser($user->id);
            $preference->update($validated);

            return response()->json([
                'success' => 1,
                'message' => 'Admin user preferences updated successfully.',
                'preferences' => $this->preferencePayload($preference->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update admin user preferences.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getDashboardItems(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (!$user) {
            return response()->json([
                'success' => 0,
                'message' => 'Unauthorized.',
            ], 401);
        }

        try {
            return response()->json([
                'success' => 1,
                'dashboard_items' => $this->dashboardItemsForUser($user)->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve user dashboard items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncDashboardItems(Request $request)
    {
        $user = $this->authenticatedUser($request);

        if (!$user) {
            return response()->json([
                'success' => 0,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'dashboard_items' => ['required', 'array'],
            'dashboard_items.*.dashboard_item_id' => ['required', 'integer', 'exists:dashboard_items,id'],
            'dashboard_items.*.is_visible' => ['nullable', 'boolean'],
            'dashboard_items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'dashboard_items.*.width' => ['nullable', 'string', Rule::in(['small', 'medium', 'large', 'full'])],
            'dashboard_items.*.settings' => ['nullable', 'array'],
        ]);

        try {
            $allowedIds = $this->allowedDashboardItems($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $requestedIds = collect($validated['dashboard_items'])
                ->pluck('dashboard_item_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $blockedIds = array_values(array_diff($requestedIds, $allowedIds));

            if (!empty($blockedIds)) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Some dashboard items are not allowed for this user role.',
                    'blocked_dashboard_item_ids' => $blockedIds,
                ], 422);
            }

            DB::transaction(function () use ($user, $validated) {
                $requestedIds = collect($validated['dashboard_items'])
                    ->pluck('dashboard_item_id')
                    ->unique()
                    ->values()
                    ->all();

                UserDashboardItem::where('user_id', $user->id)
                    ->whereNotIn('dashboard_item_id', $requestedIds)
                    ->delete();

                collect($validated['dashboard_items'])
                    ->unique('dashboard_item_id')
                    ->values()
                    ->each(function ($item, $index) use ($user) {
                        UserDashboardItem::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'dashboard_item_id' => $item['dashboard_item_id'],
                            ],
                            [
                                'is_visible' => $item['is_visible'] ?? true,
                                'sort_order' => $item['sort_order'] ?? $index,
                                'width' => $item['width'] ?? null,
                                'settings' => $item['settings'] ?? null,
                            ]
                        );
                    });
            });

            return response()->json([
                'success' => 1,
                'message' => 'User dashboard items updated successfully.',
                'dashboard_items' => $this->dashboardItemsForUser($user->fresh())->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update user dashboard items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validatePreferences(Request $request): array
    {
        return $request->validate([
            'theme_mode' => ['nullable', Rule::in(['light', 'dark', 'system'])],
            'primary_color' => ['nullable', 'string', 'max:30'],
            'sidebar_state' => ['nullable', Rule::in(['expanded', 'collapsed'])],
            'sidebar_pinned' => ['nullable', 'boolean'],
            'sidebar_width' => ['nullable', 'integer', 'min:180', 'max:420'],
            'topbar_density' => ['nullable', Rule::in(['compact', 'standard'])],
            'default_landing_page' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:20'],
            'text_direction' => ['nullable', Rule::in(['ltr', 'rtl', 'auto'])],
            'timezone' => ['nullable', 'string', 'max:255'],
            'date_format' => ['nullable', 'string', 'max:30'],
            'time_format' => ['nullable', Rule::in(['12h', '24h'])],
            'table_rows_per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
            'table_density' => ['nullable', Rule::in(['compact', 'standard', 'comfortable'])],
            'sticky_table_header' => ['nullable', 'boolean'],
            'remember_filters' => ['nullable', 'boolean'],
            'remember_sorting' => ['nullable', 'boolean'],
            'editor_default_language' => ['nullable', 'string', 'max:20'],
            'editor_text_direction' => ['nullable', Rule::in(['ltr', 'rtl', 'auto'])],
            'editor_font_family' => ['nullable', 'string', 'max:255'],
            'editor_toolbar_mode' => ['nullable', Rule::in(['full', 'compact'])],
            'auto_save_enabled' => ['nullable', 'boolean'],
            'auto_save_interval_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'dashboard_layout' => ['nullable', 'array'],
            'dashboard_refresh_interval' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'dashboard_date_range' => ['nullable', 'string', 'max:30'],
            'dashboard_compact_mode' => ['nullable', 'boolean'],
            'notification_settings' => ['nullable', 'array'],
            'module_preferences' => ['nullable', 'array'],
        ]);
    }

    private function preferenceForUser(int $userId): AdminUserPreference
    {
        return AdminUserPreference::firstOrCreate(['user_id' => $userId]);
    }

    private function preferencePayload(AdminUserPreference $preference): array
    {
        return [
            'id' => $preference->id,
            'user_id' => $preference->user_id,
            'theme_mode' => $preference->theme_mode,
            'primary_color' => $preference->primary_color,
            'sidebar_state' => $preference->sidebar_state,
            'sidebar_pinned' => (bool) $preference->sidebar_pinned,
            'sidebar_width' => $preference->sidebar_width,
            'topbar_density' => $preference->topbar_density,
            'default_landing_page' => $preference->default_landing_page,
            'language' => $preference->language,
            'text_direction' => $preference->text_direction,
            'timezone' => $preference->timezone,
            'date_format' => $preference->date_format,
            'time_format' => $preference->time_format,
            'table_rows_per_page' => $preference->table_rows_per_page,
            'table_density' => $preference->table_density,
            'sticky_table_header' => (bool) $preference->sticky_table_header,
            'remember_filters' => (bool) $preference->remember_filters,
            'remember_sorting' => (bool) $preference->remember_sorting,
            'editor_default_language' => $preference->editor_default_language,
            'editor_text_direction' => $preference->editor_text_direction,
            'editor_font_family' => $preference->editor_font_family,
            'editor_toolbar_mode' => $preference->editor_toolbar_mode,
            'auto_save_enabled' => (bool) $preference->auto_save_enabled,
            'auto_save_interval_seconds' => $preference->auto_save_interval_seconds,
            'dashboard_layout' => $preference->dashboard_layout,
            'dashboard_refresh_interval' => $preference->dashboard_refresh_interval,
            'dashboard_date_range' => $preference->dashboard_date_range,
            'dashboard_compact_mode' => (bool) $preference->dashboard_compact_mode,
            'notification_settings' => $preference->notification_settings,
            'module_preferences' => $preference->module_preferences,
            'created_at' => $preference->created_at,
            'updated_at' => $preference->updated_at,
        ];
    }

    private function dashboardItemsForUser(User $user)
    {
        $allowedItems = $this->allowedDashboardItems($user);
        $overrides = UserDashboardItem::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('dashboard_item_id');

        return $allowedItems
            ->map(function (DashboardItem $item) use ($overrides) {
                $override = $overrides->get($item->id);

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'title' => $item->title,
                    'category' => $item->category,
                    'widget_type' => $item->widget_type,
                    'data_key' => $item->data_key,
                    'permission_name' => $item->permission_name,
                    'width' => $override?->width ?: ($item->pivot?->width ?? $item->width),
                    'sort_order' => (int) ($override?->sort_order ?? $item->pivot?->sort_order ?? $item->sort_order ?? 0),
                    'is_visible' => (bool) ($override?->is_visible ?? $item->pivot?->is_visible ?? true),
                    'description' => $item->description,
                    'settings' => $override?->settings ?: ($item->pivot?->settings ? json_decode($item->pivot->settings, true) : $item->settings),
                    'has_user_override' => (bool) $override,
                ];
            })
            ->sortBy('sort_order')
            ->values();
    }

    private function allowedDashboardItems(User $user)
    {
        $user->loadMissing([
            'role.dashboardItems' => fn ($query) => $query
                ->where('dashboard_items.is_active', true)
                ->orderBy('role_dashboard_items.sort_order')
                ->orderBy('dashboard_items.sort_order'),
        ]);

        $roleName = $user->role?->name;
        $normalizedRoleName = str_replace(['-', ' '], '_', strtolower(trim((string) $roleName)));

        if ($normalizedRoleName === 'super_admin') {
            return DashboardItem::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('sort_order')
                ->get();
        }

        return $user->role?->dashboardItems
            ?->filter(fn ($item) => (bool) ($item->pivot?->is_visible ?? true))
            ->values() ?? collect();
    }

    private function authenticatedUser(Request $request): ?User
    {
        return $request->user()
            ?: auth('api')->user()
            ?: auth('web_api')->user()
            ?: auth()->user();
    }
}
