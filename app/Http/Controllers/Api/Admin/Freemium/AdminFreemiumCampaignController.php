<?php

namespace App\Http\Controllers\Api\Admin\Freemium;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Freemium\StoreCampaignRequest;
use App\Http\Requests\Admin\Freemium\UpdateCampaignRequest;
use App\Http\Requests\Admin\Freemium\UpdateCampaignStatusRequest;
use App\Http\Services\Freemium\Exceptions\CampaignDeletionRestrictedException;
use App\Http\Services\Freemium\Exceptions\InvalidCampaignTransitionException;
use App\Http\Services\Freemium\FreemiumCampaignLifecycleService;
use App\Models\Freemium\FreemiumCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdminFreemiumCampaignController extends Controller
{
    public function __construct(
        protected FreemiumCampaignLifecycleService $lifecycleService
    ) {
    }

    /**
     * List campaigns with optional filtering and relation counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FreemiumCampaign::query()
            ->withCount(['accessRules', 'examQuotas', 'studentGrants']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('start_date')) {
            $query->whereDate('start_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('end_at', '<=', $request->input('end_date'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $campaigns = $query->orderBy('priority', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'per_page'     => $campaigns->perPage(),
                'total'        => $campaigns->total(),
                'last_page'    => $campaigns->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new Freemium campaign.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        try {
            $campaign = $this->lifecycleService->createCampaign(
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'message' => 'Campaign created successfully.',
                'data'    => $campaign,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * View detailed campaign attributes and child configurations.
     */
    public function show(int $id): JsonResponse
    {
        $campaign = FreemiumCampaign::with(['accessRules', 'examQuotas'])
            ->withCount(['studentGrants'])
            ->findOrFail($id);

        return response()->json([
            'data' => $campaign,
        ]);
    }

    /**
     * Update campaign metadata and intervals (excluding lifecycle status).
     */
    public function update(UpdateCampaignRequest $request, int $id): JsonResponse
    {
        $campaign = FreemiumCampaign::findOrFail($id);

        try {
            $updated = $this->lifecycleService->updateCampaign(
                $campaign,
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'message' => 'Campaign updated successfully.',
                'data'    => $updated,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Transition campaign lifecycle status.
     */
    public function updateStatus(UpdateCampaignStatusRequest $request, int $id): JsonResponse
    {
        $campaign = FreemiumCampaign::findOrFail($id);

        try {
            $updated = $this->lifecycleService->updateCampaign(
                $campaign,
                ['status' => $request->input('status')],
                $request->user()
            );

            return response()->json([
                'message' => 'Campaign status updated successfully.',
                'data'    => $updated,
            ]);
        } catch (InvalidCampaignTransitionException $e) {
            return response()->json([
                'message'    => $e->getMessage(),
                'error_code' => 'INVALID_CAMPAIGN_TRANSITION',
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete campaign if no historical consumption records or grants exist.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $campaign = FreemiumCampaign::findOrFail($id);

        try {
            $this->lifecycleService->deleteCampaign($campaign, $request->user());

            return response()->json([
                'message' => 'Campaign deleted successfully.',
            ]);
        } catch (CampaignDeletionRestrictedException $e) {
            return response()->json([
                'message'    => $e->getMessage(),
                'error_code' => 'CAMPAIGN_DELETION_RESTRICTED',
            ], 409);
        }
    }
}
