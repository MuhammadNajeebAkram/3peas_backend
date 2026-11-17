<?php

// app/Models/WorkshopPrerequisite.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkshopPrerequisite extends Model
{
    use HasFactory;

    protected $fillable = [
        'workshop_id',
        'title',
        'description',
        'is_required',
        'display_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'display_order' => 'integer',
    ];

    public function workshop()
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }
}
