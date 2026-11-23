<?php

namespace App\Http\Services\Freemium;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;

class FreemiumEvaluationResult
{
    public const DECISION_ALLOW = 'ALLOW';
    public const DECISION_DENY  = 'DENY';

    public const SOURCE_EXPLICIT_RULE   = 'explicit_rule';
    public const SOURCE_LEGACY_FALLBACK = 'legacy_fallback';
    public const SOURCE_DEFAULT_DENY    = 'default_deny';

    public function __construct(
        private readonly string $decision,
        private readonly ?FreemiumAccessRule $winningRule = null,
        private readonly array $participatingCampaigns = [],
        private readonly ?FreemiumCampaign $primaryCampaign = null,
        private readonly int $resolvedSpecificity = -1,
        private readonly string $source = self::SOURCE_DEFAULT_DENY,
        private readonly string $reason = '',
        private readonly array $targetContext = []
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->decision === self::DECISION_ALLOW;
    }

    public function isDenied(): bool
    {
        return $this->decision === self::DECISION_DENY;
    }

    public function getDecision(): string
    {
        return $this->decision;
    }

    public function getWinningRule(): ?FreemiumAccessRule
    {
        return $this->winningRule;
    }

    public function getParticipatingCampaigns(): array
    {
        return $this->participatingCampaigns;
    }

    public function getPrimaryCampaign(): ?FreemiumCampaign
    {
        return $this->primaryCampaign;
    }

    public function getResolvedSpecificity(): int
    {
        return $this->resolvedSpecificity;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getTargetContext(): array
    {
        return $this->targetContext;
    }

    public function toArray(): array
    {
        return [
            'decision'                 => $this->decision,
            'is_allowed'               => $this->isAllowed(),
            'winning_rule_id'          => $this->winningRule?->id,
            'primary_campaign_id'      => $this->primaryCampaign?->id,
            'participating_campaign_ids' => array_map(fn($c) => $c->id, $this->participatingCampaigns),
            'resolved_specificity'     => $this->resolvedSpecificity,
            'source'                   => $this->source,
            'reason'                   => $this->reason,
            'target_context'           => $this->targetContext,
        ];
    }
}
