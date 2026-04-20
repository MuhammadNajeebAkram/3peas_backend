<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TestScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_id',
        'unit_id',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class, 'test_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BookUnit::class, 'unit_id');
    }
}
