<?php

namespace App\Http\Controllers;

use App\Models\DashboardItem;
use App\Models\UserDashboardItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DashboardItemController extends Controller
{
    public function getDashboardItems(Request $request)
    {
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:100'],
            'widget_type' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $items = DashboardItem::query()
                ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
                ->when($validated['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
                ->when($validated['widget_type'] ?? null, fn ($query, $widgetType) => $query->where('widget_type', $widgetType))
                ->orderBy('category')
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get();

            return response()->json([
                'success' => 1,
                'dashboard_items' => $items,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve dashboard items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMyDashboardItems(Request $request)
    {
        try {
            $user = $request->user()
                ?: auth('api')->user()
                ?: auth('web_api')->user()
                ?: auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized.',
                ], 401);
            }

            $user->loadMissing([
                'role.dashboardItems' => fn ($query) => $query
                    ->where('dashboard_items.is_active', true)
                    ->orderBy('role_dashboard_items.sort_order')
                    ->orderBy('dashboard_items.sort_order'),
            ]);

            $roleName = $user->role?->name;
            $normalizedRoleName = str_replace(['-', ' '], '_', strtolower(trim((string) $roleName)));

            $items = $normalizedRoleName === 'super_admin'
                ? DashboardItem::query()
                    ->where('is_active', true)
                    ->orderBy('category')
                    ->orderBy('sort_order')
                    ->get()
                : $user->role?->dashboardItems
                    ?->filter(fn ($item) => (bool) ($item->pivot?->is_visible ?? true))
                    ->values() ?? collect();

            $overrides = UserDashboardItem::query()
                ->where('user_id', $user->id)
                ->get()
                ->keyBy('dashboard_item_id');

            return response()->json([
                'success' => 1,
                'dashboard_items' => $items
                    ->map(fn ($item) => $this->dashboardItemPayload($item, $overrides->get($item->id)))
                    ->sortBy('sort_order')
                    ->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve allowed dashboard items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOverAllSummary(Request $request){
        try{

            $user = Auth::user();

            $result = DB::select("CALL GetStudentPerformanceSummary(?, ?)", [$user->id, $request->subject_id]);

            return response()->json([
                'success' => 1,
                'stats' => $result,
                'user' => $user,
                'subject' => $request->subject_id
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getSubjectLeaderBoard(Request $request){
        try{
            $user = Auth::user();

            $result = DB::select("CALL GetSubjectLeaderBoard(?)", [$user->id]);

            return response()->json([
                'success' => 1,
                'stats' => $result,
               
            ]);


        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getTestHistory(Request $request){
        try{
            $user = Auth::user();

            $result = DB::select("CALL GetTestHistory(?)", [$user->id]);

            return response()->json([
                'success' => 1,
                'history' => $result,
               
            ]);


        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    private function dashboardItemPayload(DashboardItem $item, ?UserDashboardItem $override = null): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'title' => $item->title,
            'category' => $item->category,
            'widget_type' => $item->widget_type,
            'data_key' => $item->data_key,
            'permission_name' => $item->permission_name,
            'width' => $override?->width ?: $item->width,
            'sort_order' => (int) ($override?->sort_order ?? $item->pivot?->sort_order ?? $item->sort_order ?? 0),
            'is_visible' => (bool) ($override?->is_visible ?? $item->pivot?->is_visible ?? true),
            'description' => $item->description,
            'settings' => $override?->settings ?: ($item->pivot?->settings
                ? json_decode($item->pivot->settings, true)
                : $item->settings),
            'has_user_override' => (bool) $override,
        ];
    }
}
