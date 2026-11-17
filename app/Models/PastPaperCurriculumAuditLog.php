<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastPaperCurriculumAuditLog extends Model
{
    public const SOURCE_STRUCTURED = 'tst_past_papers';

    public const SOURCE_LEGACY = 'past_paper_tbl';

    public const OPERATION_INITIAL = 'initial_assignment';

    public const OPERATION_REASSIGN = 'reassignment';

    protected $table = 'past_paper_curriculum_audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'paper_id' => 'integer',
        'previous_curriculum_board_id' => 'integer',
        'new_curriculum_board_id' => 'integer',
        'admin_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Past paper curriculum audit logs are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new \LogicException('Past paper curriculum audit logs are append-only and cannot be deleted.');
        });
    }

    public function previousCurriculumBoard(): BelongsTo
    {
        return $this->belongsTo(CurriculumBoard::class, 'previous_curriculum_board_id');
    }

    public function newCurriculumBoard(): BelongsTo
    {
        return $this->belongsTo(CurriculumBoard::class, 'new_curriculum_board_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
