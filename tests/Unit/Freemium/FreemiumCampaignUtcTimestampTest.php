<?php

namespace Tests\Unit\Freemium;

use App\Http\Services\Freemium\Exceptions\InvalidCampaignTransitionException;
use App\Http\Services\Freemium\FreemiumAuditService;
use App\Http\Services\Freemium\FreemiumCampaignLifecycleService;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class FreemiumCampaignUtcTimestampTest extends TestCase
{
    private FreemiumCampaignLifecycleService $lifecycleService;
    private FreemiumAuditService $auditService;
    private ?User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = new FreemiumAuditService();
        $this->lifecycleService = new FreemiumCampaignLifecycleService($this->auditService);
        $this->admin = User::first();
    }

    /**
     * Test 1: Naive datetime is interpreted as Asia/Karachi and converted to UTC.
     */
    public function test_naive_datetime_interpreted_as_asia_karachi(): void
    {
        $parsed = $this->lifecycleService->normalizeToUtc('2026-11-21T14:49');

        $this->assertInstanceOf(Carbon::class, $parsed);
        $this->assertEquals('UTC', $parsed->timezoneName);
        // 14:49 in Asia/Karachi (UTC+5) is 09:49 UTC
        $this->assertEquals('2026-11-21 09:49:00', $parsed->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-21T09:49:00+00:00', $parsed->toIso8601String());
    }

    /**
     * Test 2: Explicit +05:00 timestamp is converted correctly.
     */
    public function test_explicit_karachi_offset_timestamp_converted_correctly(): void
    {
        $parsed = $this->lifecycleService->normalizeToUtc('2026-11-21T14:49:00+05:00');

        $this->assertInstanceOf(Carbon::class, $parsed);
        $this->assertEquals('UTC', $parsed->timezoneName);
        $this->assertEquals('2026-11-21 09:49:00', $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * Test 3: Explicit Z timestamp is preserved as the exact same instant.
     */
    public function test_explicit_z_timestamp_preserved_as_same_instant(): void
    {
        $parsed = $this->lifecycleService->normalizeToUtc('2026-11-21T09:49:00.000Z');

        $this->assertInstanceOf(Carbon::class, $parsed);
        $this->assertEquals('UTC', $parsed->timezoneName);
        $this->assertEquals('2026-11-21 09:49:00', $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * Test 4: Both start_at and end_at are normalized to UTC.
     */
    public function test_start_and_end_timestamps_both_normalized(): void
    {
        DB::beginTransaction();
        try {
            $campaign = $this->lifecycleService->createCampaign([
                'name'     => 'Normalized Start and End Test',
                'status'   => 'draft',
                'start_at' => '2026-11-21T14:00',          // naive local
                'end_at'   => '2026-11-21T10:00:00.000Z',   // explicit UTC (15:00 PKT)
            ], $this->admin);

            $this->assertEquals('UTC', $campaign->start_at->timezoneName);
            $this->assertEquals('UTC', $campaign->end_at->timezoneName);
            $this->assertEquals('2026-11-21 09:00:00', $campaign->start_at->format('Y-m-d H:i:s'));
            $this->assertEquals('2026-11-21 10:00:00', $campaign->end_at->format('Y-m-d H:i:s'));

            // JSON serialization must be UTC with Z
            $json = $campaign->toArray();
            $this->assertEquals('2026-11-21T09:00:00.000000Z', $json['start_at']);
            $this->assertEquals('2026-11-21T10:00:00.000000Z', $json['end_at']);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Test 5: Null end timestamp remains null and passes normalization.
     */
    public function test_null_end_timestamp_remains_null(): void
    {
        $this->assertNull($this->lifecycleService->normalizeToUtc(null));
        $this->assertNull($this->lifecycleService->normalizeToUtc(''));
    }

    /**
     * Test 6: Updating an existing campaign without changing timestamps preserves its values.
     */
    public function test_updating_campaign_without_timestamps_preserves_values(): void
    {
        DB::beginTransaction();
        try {
            $campaign = $this->lifecycleService->createCampaign([
                'name'     => 'Original Name',
                'status'   => 'draft',
                'start_at' => '2026-11-21T09:00:00Z',
                'end_at'   => '2026-11-21T12:00:00Z',
            ], $this->admin);

            $origStartRaw = $campaign->getRawOriginal('start_at');
            $origEndRaw = $campaign->getRawOriginal('end_at');

            // Update only description
            $updated = $this->lifecycleService->updateCampaign($campaign, [
                'description' => 'Updated description without timestamp change',
            ], $this->admin);

            $this->assertEquals($origStartRaw, $updated->getRawOriginal('start_at'));
            $this->assertEquals($origEndRaw, $updated->getRawOriginal('end_at'));
            $this->assertEquals('Updated description without timestamp change', $updated->description);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Test 7: Half-open campaign validity boundary [start_at, end_at) remains unchanged.
     */
    public function test_half_open_campaign_validity_boundary(): void
    {
        $campaign = new FreemiumCampaign();
        $campaign->status = 'active';
        $campaign->start_at = '2026-11-21T09:00:00Z';
        $campaign->end_at = '2026-11-21T12:00:00Z';

        // 1. One second before start_at -> NOT active
        $beforeStart = Carbon::parse('2026-11-21T08:59:59Z');
        $this->assertFalse($this->lifecycleService->isCurrentlyActive($campaign, $beforeStart));

        // 2. Exactly at start_at -> ACTIVE (inclusive lower boundary)
        $atStart = Carbon::parse('2026-11-21T09:00:00Z');
        $this->assertTrue($this->lifecycleService->isCurrentlyActive($campaign, $atStart));

        // 3. During campaign interval -> ACTIVE
        $during = Carbon::parse('2026-11-21T10:30:00Z');
        $this->assertTrue($this->lifecycleService->isCurrentlyActive($campaign, $during));

        // 4. One second before end_at -> ACTIVE
        $beforeEnd = Carbon::parse('2026-11-21T11:59:59Z');
        $this->assertTrue($this->lifecycleService->isCurrentlyActive($campaign, $beforeEnd));

        // 5. Exactly at end_at -> NOT active (exclusive upper boundary)
        $atEnd = Carbon::parse('2026-11-21T12:00:00Z');
        $this->assertFalse($this->lifecycleService->isCurrentlyActive($campaign, $atEnd));

        // 6. After end_at -> NOT active
        $afterEnd = Carbon::parse('2026-11-21T12:00:01Z');
        $this->assertFalse($this->lifecycleService->isCurrentlyActive($campaign, $afterEnd));
    }

    /**
     * Test 8: Existing campaign lifecycle transitions remain strictly enforced.
     */
    public function test_campaign_lifecycle_transitions_enforced(): void
    {
        DB::beginTransaction();
        try {
            $campaign = $this->lifecycleService->createCampaign([
                'name'     => 'Transition Test',
                'status'   => 'draft',
                'start_at' => '2026-11-21T09:00:00Z',
                'end_at'   => '2026-11-21T12:00:00Z',
            ], $this->admin);

            // draft -> active is allowed
            $active = $this->lifecycleService->updateCampaign($campaign, ['status' => 'active'], $this->admin);
            $this->assertEquals('active', $active->status);

            // active -> paused is allowed
            $paused = $this->lifecycleService->updateCampaign($active, ['status' => 'paused'], $this->admin);
            $this->assertEquals('paused', $paused->status);

            // paused -> draft is PROHIBITED
            $this->expectException(InvalidCampaignTransitionException::class);
            $this->lifecycleService->updateCampaign($paused, ['status' => 'draft'], $this->admin);
        } finally {
            DB::rollBack();
        }
    }
}
