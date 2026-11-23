<?php

namespace App\Http\Services\Freemium;

use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use Carbon\Carbon;
use InvalidArgumentException;

class FreemiumQuotaWindow
{
    public const TIMEZONE = 'Asia/Karachi';
    public const PREFIX   = 'PK:';

    /**
     * Resolve the deterministic window key for a quota window.
     */
    public static function resolveWindowKey(string $windowType, ?int $campaignId, Carbon $nowUtc): string
    {
        $pkt = $nowUtc->copy()->setTimezone(self::TIMEZONE);

        return match ($windowType) {
            'lifetime' => 'lifetime',
            'campaign' => 'campaign_' . ($campaignId ?? 'global'),
            'daily'    => self::PREFIX . $pkt->format('Y-m-d'),
            'weekly'   => self::PREFIX . $pkt->format('o-\WW'),
            'monthly'  => self::PREFIX . $pkt->format('Y-m'),
            'annual'   => self::PREFIX . $pkt->format('Y'),
            default    => throw new InvalidArgumentException("Unsupported quota window type: {$windowType}"),
        };
    }

    /**
     * Calculate local window boundaries in Asia/Karachi and convert to exact UTC boundaries [start, end).
     *
     * @return array{0: Carbon, 1: ?Carbon} [startUtc, endUtc] (endUtc may be null for lifetime)
     */
    public static function resolveWindowUtcBounds(
        string $windowType,
        ?FreemiumCampaign $campaign,
        Carbon $nowUtc
    ): array {
        if ($windowType === 'lifetime') {
            return [Carbon::createFromTimestamp(0, 'UTC'), null];
        }

        if ($windowType === 'campaign') {
            if (!$campaign) {
                throw new InvalidArgumentException("Campaign instance required to resolve campaign window bounds.");
            }
            return [
                $campaign->start_at->copy()->setTimezone('UTC'),
                $campaign->end_at->copy()->setTimezone('UTC'),
            ];
        }

        $pkt = $nowUtc->copy()->setTimezone(self::TIMEZONE);

        [$startPkt, $endPkt] = match ($windowType) {
            'daily'   => [$pkt->copy()->startOfDay(), $pkt->copy()->startOfDay()->addDay()],
            'weekly'  => [$pkt->copy()->startOfWeek(Carbon::MONDAY), $pkt->copy()->startOfWeek(Carbon::MONDAY)->addWeek()],
            'monthly' => [$pkt->copy()->startOfMonth(), $pkt->copy()->startOfMonth()->addMonth()],
            'annual'  => [$pkt->copy()->startOfYear(), $pkt->copy()->startOfYear()->addYear()],
            default   => throw new InvalidArgumentException("Unsupported calendar window type: {$windowType}"),
        };

        return [
            $startPkt->setTimezone('UTC'),
            $endPkt->setTimezone('UTC'),
        ];
    }
}
