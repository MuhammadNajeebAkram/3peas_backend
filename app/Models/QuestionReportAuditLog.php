<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionReportAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'question_report_audit_logs';

    protected $fillable = [
        'question_report_id',
        'admin_user_id',
        'previous_status',
        'new_status',
        'internal_note',
        'action_type',
        'created_at',
    ];

    protected $casts = [
        'question_report_id' => 'integer',
        'admin_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Question report audit logs are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new \LogicException('Question report audit logs are append-only and cannot be deleted.');
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(QuestionReport::class, 'question_report_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
