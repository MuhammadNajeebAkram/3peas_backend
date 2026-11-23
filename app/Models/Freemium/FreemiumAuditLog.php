<?php

namespace App\Models\Freemium;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FreemiumAuditLog extends Model
{
    use HasFactory;

    protected $table = 'freemium_audit_logs';

    /**
     * Disable standard Eloquent timestamps (table has created_at, no updated_at).
     */
    public $timestamps = false;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'admin_email',
        'admin_role',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'entity_id'   => 'integer',
        'old_values'  => 'array',
        'new_values'  => 'array',
        'created_at'  => 'datetime',
    ];

    /**
     * Enforce strict append-only immutability.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('FreemiumAuditLog is an append-only immutable audit record. Updates are prohibited.');
        });

        static::deleting(function () {
            throw new RuntimeException('FreemiumAuditLog is an append-only immutable audit record. Deletions are prohibited.');
        });
    }

    // ==========================================
    // Relationships
    // ==========================================

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // ==========================================
    // Query Scopes
    // ==========================================

    public function scopeForEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)
                     ->where('entity_id', $entityId);
    }

    public function scopeForAdmin($query, int $adminId)
    {
        return $query->where('admin_id', $adminId);
    }
}
