<?php

namespace App\Http\Controllers;

use App\Models\AdminActivityLog;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    public function getMyActivityLogs(Request $request)
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $adminUserId = $this->authenticatedUserId($request);

        if (!$adminUserId) {
            return response()->json([
                'success' => 0,
                'message' => 'Unable to resolve authenticated admin user.',
            ], 422);
        }

        try {
            $logs = AdminActivityLog::query()
                ->where('admin_user_id', $adminUserId)
                ->when($validated['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
                ->when($validated['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
                ->when($validated['date_from'] ?? null, fn ($query, $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($validated['date_to'] ?? null, fn ($query, $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
                ->latest('created_at')
                ->paginate($validated['per_page'] ?? 10);

            return response()->json([
                'success' => 1,
                'activity_logs' => $logs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve activity logs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function authenticatedUserId(Request $request): ?int
    {
        $user = $request->user()
            ?: auth('api')->user()
            ?: auth('web_api')->user()
            ?: auth()->user();

        return $user?->id ? (int) $user->id : null;
    }
}
