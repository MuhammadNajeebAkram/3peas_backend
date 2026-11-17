<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassSubmissionAttachment extends Model
{
    protected $table = 'teacher_class_submission_attachments';

    protected $fillable = [
        'submission_id',
        'version_number',
        'uploaded_by_web_user_id',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'storage_disk',
        'storage_key',
        'is_active',
    ];

    protected $hidden = [
        'storage_disk',
        'storage_key',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'size_bytes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignmentSubmission::class, 'submission_id');
    }
}
