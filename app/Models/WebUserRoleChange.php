<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebUserRoleChange extends Model
{
    public $timestamps = false;

    protected $table = 'web_user_role_changes';

    protected $fillable = [
        'web_user_id',
        'from_role',
        'to_role',
        'changed_by_admin_id',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function webUser(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }

    public function changedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_admin_id');
    }
}
