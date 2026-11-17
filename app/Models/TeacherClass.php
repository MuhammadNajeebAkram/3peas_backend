<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClass extends Model
{
    protected $table = 'teacher_classes';

    // teacher_profile_id and class_code are assigned explicitly by trusted backend code only.
    protected $fillable = ['class_id', 'subject_id', 'curriculum_board_id', 'name', 'section', 'is_active', 'requires_approval'];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_approval' => 'boolean',
    ];

    // Unambiguous characters only (no 0/O, 1/I/L) so codes are easy to read aloud and type.
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 8;

    public static function generateUniqueCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
        } while (static::where('class_code', $code)->exists());

        return $code;
    }

    public function scopeOwnedBy(Builder $query, TeacherProfile $profile): Builder
    {
        return $query->where('teacher_profile_id', $profile->id);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function academicClass(): BelongsTo
    {
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function curriculumBoard(): BelongsTo
    {
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TeacherClassEnrollment::class, 'teacher_class_id');
    }

    public function activeEnrollments(): HasMany
    {
        return $this->hasMany(TeacherClassEnrollment::class, 'teacher_class_id')
            ->where('status', TeacherClassEnrollment::STATUS_ACTIVE);
    }

    public function pendingEnrollments(): HasMany
    {
        return $this->hasMany(TeacherClassEnrollment::class, 'teacher_class_id')
            ->where('status', TeacherClassEnrollment::STATUS_PENDING);
    }

    public function enrollmentEvents(): HasMany
    {
        return $this->hasMany(TeacherClassEnrollmentEvent::class, 'teacher_class_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(TeacherClassTest::class, 'teacher_class_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
