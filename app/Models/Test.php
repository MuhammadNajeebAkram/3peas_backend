<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Test extends Model
{
    use HasFactory;

    protected $fillable = [
        'offered_program_id',
        'subject_id',
        'created_by',
        'title',
        'description',
        'test_source',
        'test_mode',
        'scope_type',
        'question_type',
        'time_limit_minutes',
        'total_questions',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'time_limit_minutes' => 'integer',
        'total_questions' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function offeredProgram(): BelongsTo
    {
        return $this->belongsTo(OfferedProgram::class, 'offered_program_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class, 'test_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TestQuestion::class, 'test_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(TestScope::class, 'test_id');
    }
}
