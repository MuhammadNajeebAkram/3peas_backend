<?php

namespace App\Http\Controllers;

use App\Models\AdminLoginLog;
use Illuminate\Http\Request;

class AdminLoginLogController extends Controller
{
    public function getAdminLoginLogs(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'email' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:30'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $logs = AdminLoginLog::query()
                ->with('user:id,name,email')
                ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
                ->when($validated['email'] ?? null, fn ($query, $email) => $query->where('email', 'like', '%' . $email . '%'))
                ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($validated['date_from'] ?? null, fn ($query, $dateFrom) => $query->whereDate('login_at', '>=', $dateFrom))
                ->when($validated['date_to'] ?? null, fn ($query, $dateTo) => $query->whereDate('login_at', '<=', $dateTo))
                ->latest('login_at')
                ->paginate($validated['per_page'] ?? 25);

            return response()->json([
                'success' => 1,
                'logs' => $logs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
