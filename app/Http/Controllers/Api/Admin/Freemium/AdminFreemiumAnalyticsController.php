<?php

namespace App\Http\Controllers\Api\Admin\Freemium;

use App\Http\Controllers\Controller;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumStudentGrant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFreemiumAnalyticsController extends Controller
{
    /**
     * Provide baseline Freemium system aggregate analytics and KPIs.
     */
    public function summary(Request $request): JsonResponse
    {
        // Campaign counts grouped by status
        $campaignCounts = FreemiumCampaign::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Total active grants
        $activeGrantsCount = FreemiumStudentGrant::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', Carbon::now());
            })
            ->count();

        // Total quota consumptions
        $totalConsumptions = FreemiumQuotaConsumption::count();

        // Distinct active users in past 30 days
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $recentActiveUsers = FreemiumQuotaConsumption::where('consumed_at', '>=', $thirtyDaysAgo)
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'data' => [
                'campaigns' => [
                    'draft'    => $campaignCounts['draft'] ?? 0,
                    'active'   => $campaignCounts['active'] ?? 0,
                    'paused'   => $campaignCounts['paused'] ?? 0,
                    'archived' => $campaignCounts['archived'] ?? 0,
                    'total'    => array_sum($campaignCounts),
                ],
                'grants' => [
                    'active' => $activeGrantsCount,
                    'total'  => FreemiumStudentGrant::count(),
                ],
                'consumptions' => [
                    'total_events'           => $totalConsumptions,
                    'active_users_last_30d'  => $recentActiveUsers,
                ],
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
        ]);
    }
}
