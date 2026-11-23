<?php

namespace App\Models\Freemium;

use App\Models\WebUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreemiumQuotaCounter extends Model
{
    use HasFactory;

    protected $table = 'freemium_quota_counters';

    protected $fillable = [
        'user_id',
        'exam_type',
        'window_type',
        'window_key',
        'counter',
    ];

    protected $casts = [
        'counter' => 'integer',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }
}
