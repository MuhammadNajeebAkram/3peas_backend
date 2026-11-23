<?php

namespace App\Http\Controllers\Api\Admin\Freemium;

use App\Http\Controllers\Controller;
use App\Models\Freemium\FreemiumAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFreemiumAuditController extends Controller
{
    /**
     * List audit trail logs with filtering.
     * Read-only: Updates and deletions are strictly prohibited.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FreemiumAuditLog::query()
            ->with(['admin:id,name']);

        $adminId = $request->input('admin_id', $request->input('actor_id'));
        if (!empty($adminId)) {
            $query->where('admin_id', $adminId);
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $logs = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
                'last_page'    => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * View a single audit trail log entry.
     */
    public function show(int $id): JsonResponse
    {
        $log = FreemiumAuditLog::with(['admin:id,name'])->findOrFail($id);

        return response()->json([
            'data' => $log,
        ]);
    }
}
