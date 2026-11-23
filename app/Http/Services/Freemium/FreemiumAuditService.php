<?php

namespace App\Http\Services\Freemium;

use App\Models\Freemium\FreemiumAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class FreemiumAuditService
{
    /**
     * Sensitive keys that must be stripped from before/after audit payloads.
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_token',
        'remember_token',
        'access_token',
        'refresh_token',
    ];

    /**
     * Create an immutable audit trail entry for an administrative action.
     *
     * Note: Eloquent models enforce append-only immutability at the application layer,
     * but direct raw SQL or query-builder operations bypass Eloquent lifecycle hooks.
     *
     * @param User|null $actor Admin actor initiating the change
     * @param string $action Domain action identifier (e.g. 'campaign.created', 'campaign.updated')
     * @param string $entityType Entity class or table name (e.g. 'freemium_campaign')
     * @param int $entityId Primary key of target entity
     * @param array|null $oldValues State before mutation
     * @param array|null $newValues State after mutation
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client User Agent
     * @param Carbon|null $now Time authority (UTC)
     */
    public function log(
        ?User $actor,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?Carbon $now = null
    ): FreemiumAuditLog {
        $tz = config('app.timezone', 'Asia/Karachi');
        $now = ($now ? $now->copy() : Carbon::now())->setTimezone($tz);

        // Sanitize payloads to strip secrets and sensitive credentials
        $sanitizedOld = $oldValues !== null ? $this->sanitizePayload($oldValues) : null;
        $sanitizedNew = $newValues !== null ? $this->sanitizePayload($newValues) : null;

        // Extract actor snapshot safely
        $adminId = $actor?->id;
        $adminName = $actor?->name;
        $adminEmail = $actor?->email;

        $adminRole = null;
        if ($actor) {
            $rawRole = $actor->getAttribute('role');
            if (is_string($rawRole)) {
                $adminRole = $rawRole;
            } elseif ($actor->relationLoaded('role') && $actor->role) {
                $adminRole = is_string($actor->role) ? $actor->role : ($actor->role->name ?? 'admin');
            } elseif (isset($actor->role) && is_object($actor->role) && isset($actor->role->name)) {
                $adminRole = (string) $actor->role->name;
            } else {
                $adminRole = 'admin';
            }

            if (strlen((string) $adminRole) > 50) {
                $adminRole = substr((string) $adminRole, 0, 50);
            }
        }

        return FreemiumAuditLog::create([
            'admin_id'    => $adminId,
            'admin_name'  => $adminName,
            'admin_email' => $adminEmail,
            'admin_role'  => $adminRole,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $sanitizedOld,
            'new_values'  => $sanitizedNew,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
            'created_at'  => $now,
        ]);
    }

    /**
     * Recursively sanitize payload array by removing sensitive keys.
     */
    public function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
