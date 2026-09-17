<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSettlement extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['proof_path'];
    protected $appends = ['has_proof'];
    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'date', 'reviewed_at' => 'datetime'];

    public function getHasProofAttribute(): bool
    {
        return filled($this->proof_path);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function allocations()
    {
        return $this->hasMany(TeacherSettlementAllocation::class);
    }
}
