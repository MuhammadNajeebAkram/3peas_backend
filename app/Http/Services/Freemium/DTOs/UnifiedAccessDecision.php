<?php

namespace App\Http\Services\Freemium\DTOs;

/**
 * Immutable Data Transfer Object representing an authoritative access-control decision
 * across Paid Subscriptions, Freemium Campaigns, Legacy Free Flags, and Default Deny.
 */
class UnifiedAccessDecision
{
    public const PROVIDER_PAID     = 'paid';
    public const PROVIDER_FREEMIUM = 'freemium';
    public const PROVIDER_LEGACY   = 'legacy';
    public const PROVIDER_NONE     = 'none';

    public const ORIGIN_USER_SUBSCRIPTION = 'user_subscription';
    public const ORIGIN_FREEMIUM_RULE     = 'freemium_access_rule';
    public const ORIGIN_LEGACY_FALLBACK   = 'legacy_fallback';
    public const ORIGIN_DEFAULT_DENY      = 'default_deny';

    public function __construct(
        private readonly bool $isGranted,
        private readonly string $provider,
        private readonly string $origin,
        private readonly string $dimension,
        private readonly bool $requiresQuotaEvaluation = false,
        private readonly ?int $campaignId = null,
        private readonly ?int $winningRuleId = null,
        private readonly ?string $examType = null,
        private readonly ?string $denialReason = null,
        private readonly ?string $diagnosticMessage = null,
        private readonly ?string $upgradeProgramSlug = null,
        private readonly array $metadata = []
    ) {
    }

    public function isGranted(): bool
    {
        return $this->isGranted;
    }

    public function isAllowed(): bool
    {
        return $this->isGranted;
    }

    public function getSource(): string
    {
        return $this->origin;
    }

    public function isDenied(): bool
    {
        return !$this->isGranted;
    }

    public function isPaid(): bool
    {
        return $this->provider === self::PROVIDER_PAID;
    }

    public function isFreemium(): bool
    {
        return $this->provider === self::PROVIDER_FREEMIUM;
    }

    public function isLegacyFree(): bool
    {
        return $this->provider === self::PROVIDER_LEGACY;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getDimension(): string
    {
        return $this->dimension;
    }

    public function requiresQuotaEvaluation(): bool
    {
        return $this->requiresQuotaEvaluation;
    }

    public function getCampaignId(): ?int
    {
        return $this->campaignId;
    }

    public function getWinningRuleId(): ?int
    {
        return $this->winningRuleId;
    }

    public function getExamType(): ?string
    {
        return $this->examType;
    }

    public function getDenialReason(): ?string
    {
        return $this->denialReason;
    }

    public function getDiagnosticMessage(): ?string
    {
        return $this->diagnosticMessage;
    }

    public function getUpgradeProgramSlug(): ?string
    {
        return $this->upgradeProgramSlug;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Factory constructor: Granted via valid active paid subscription (Tier 1).
     */
    public static function grantedPaid(
        string $dimension,
        ?string $diagnostic = null,
        ?int $programId = null,
        ?string $programSlug = null
    ): self {
        return new self(
            isGranted: true,
            provider: self::PROVIDER_PAID,
            origin: self::ORIGIN_USER_SUBSCRIPTION,
            dimension: $dimension,
            requiresQuotaEvaluation: false,
            campaignId: null,
            winningRuleId: null,
            examType: null,
            denialReason: null,
            diagnosticMessage: $diagnostic ?? 'Access granted via valid active paid subscription.',
            upgradeProgramSlug: null,
            metadata: array_filter([
                'program_id'   => $programId,
                'program_slug' => $programSlug,
            ])
        );
    }

    /**
     * Factory constructor: Granted via explicit Freemium campaign rule (Tier 2).
     */
    public static function grantedFreemium(
        ?int $campaignId,
        ?int $winningRuleId,
        string $dimension,
        bool $requiresQuota = false,
        ?string $examType = null,
        ?string $diagnostic = null
    ): self {
        return new self(
            isGranted: true,
            provider: self::PROVIDER_FREEMIUM,
            origin: self::ORIGIN_FREEMIUM_RULE,
            dimension: $dimension,
            requiresQuotaEvaluation: $requiresQuota,
            campaignId: $campaignId,
            winningRuleId: $winningRuleId,
            examType: $examType,
            denialReason: null,
            diagnosticMessage: $diagnostic ?? 'Access granted via explicit Freemium campaign rule.',
            upgradeProgramSlug: null,
            metadata: []
        );
    }

    /**
     * Factory constructor: Granted via legacy free flag fallback (Tier 3).
     */
    public static function grantedLegacy(
        string $dimension,
        ?string $diagnostic = null
    ): self {
        return new self(
            isGranted: true,
            provider: self::PROVIDER_LEGACY,
            origin: self::ORIGIN_LEGACY_FALLBACK,
            dimension: $dimension,
            requiresQuotaEvaluation: false,
            campaignId: null,
            winningRuleId: null,
            examType: null,
            denialReason: null,
            diagnosticMessage: $diagnostic ?? 'Access granted via legacy free flag fallback.',
            upgradeProgramSlug: null,
            metadata: []
        );
    }

    /**
     * Factory constructor: Denied via explicit Freemium campaign rule (Tier 2).
     */
    public static function deniedFreemium(
        ?int $campaignId,
        ?int $winningRuleId,
        string $dimension,
        ?string $denialReason = null,
        ?string $diagnostic = null,
        ?string $upgradeSlug = null
    ): self {
        return new self(
            isGranted: false,
            provider: self::PROVIDER_FREEMIUM,
            origin: self::ORIGIN_FREEMIUM_RULE,
            dimension: $dimension,
            requiresQuotaEvaluation: false,
            campaignId: $campaignId,
            winningRuleId: $winningRuleId,
            examType: null,
            denialReason: $denialReason ?? 'Access denied by Freemium policy.',
            diagnosticMessage: $diagnostic ?? 'Explicit DENY rule encountered in Freemium campaign.',
            upgradeProgramSlug: $upgradeSlug,
            metadata: []
        );
    }

    /**
     * Factory constructor: Denied via default deny (Tier 4).
     */
    public static function deniedDefault(
        string $dimension,
        ?string $denialReason = null,
        ?string $diagnostic = null,
        ?string $upgradeSlug = null
    ): self {
        return new self(
            isGranted: false,
            provider: self::PROVIDER_NONE,
            origin: self::ORIGIN_DEFAULT_DENY,
            dimension: $dimension,
            requiresQuotaEvaluation: false,
            campaignId: null,
            winningRuleId: null,
            examType: null,
            denialReason: $denialReason ?? 'An active subscription is required to access this resource.',
            diagnosticMessage: $diagnostic ?? 'Default deny: no matching active subscription, freemium rule, or legacy free entitlement.',
            upgradeProgramSlug: $upgradeSlug,
            metadata: []
        );
    }

    /**
     * Transform decision to an array suitable for logging or structured API envelopes.
     */
    public function toArray(): array
    {
        return [
            'is_granted'                => $this->isGranted,
            'provider'                  => $this->provider,
            'origin'                    => $this->origin,
            'dimension'                 => $this->dimension,
            'requires_quota_evaluation' => $this->requiresQuotaEvaluation,
            'campaign_id'               => $this->campaignId,
            'winning_rule_id'           => $this->winningRuleId,
            'exam_type'                 => $this->examType,
            'denial_reason'             => $this->denialReason,
            'diagnostic_message'        => $this->diagnosticMessage,
            'upgrade_program_slug'      => $this->upgradeProgramSlug,
            'metadata'                  => $this->metadata,
        ];
    }
}
