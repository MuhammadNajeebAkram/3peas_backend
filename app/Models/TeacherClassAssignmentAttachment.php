<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAssignmentAttachment extends Model
{
    protected $table = 'teacher_class_assignment_attachments';

    protected $fillable = [
        'assignment_id',
        'uploaded_by_web_user_id',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'storage_disk',
        'storage_key',
    ];

    protected $hidden = [
        'storage_disk',
        'storage_key',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignment::class, 'assignment_id');
    }
}
