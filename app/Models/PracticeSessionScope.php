<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class PracticeSessionScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'unit_id',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class, 'session_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BookUnit::class, 'unit_id');
    }
}
