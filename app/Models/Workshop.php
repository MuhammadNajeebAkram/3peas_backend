<?php

// app/Models/Workshop.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workshop extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'description',
        'cover_image',
        'og_image',
        'workshop_mode',
        'institute_id',
        'speaker_name',
        'speaker_designation',
        'start_at',
        'end_at',
        'location',
        'meeting_link',
        'seat_limit',
        'registered_count',
        'registration_deadline',
        'is_registration_open',
        'recording_url',
        'recording_access',
        'quiz_enabled',
        'is_featured',
        'is_published',
        'published_at',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'registration_deadline' => 'datetime',
        'published_at' => 'datetime',
        'is_registration_open' => 'boolean',
        'quiz_enabled' => 'boolean',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function institute()
    {
        return $this->belongsTo(Institute::class, 'institute_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function registrations()
    {
        return $this->hasMany(WorkshopRegistration::class, 'workshop_id');
    }

    public function prerequisites()
    {
        return $this->hasMany(WorkshopPrerequisite::class, 'workshop_id')
            ->orderBy('display_order');
    }

    public function registeredStudents()
    {
        return $this->belongsToMany(WebUser::class, 'workshop_registrations', 'workshop_id', 'user_id')
            ->withPivot(['status', 'registered_at', 'attendance_marked_at', 'attendance_marked_by'])
            ->withTimestamps();
    }
}