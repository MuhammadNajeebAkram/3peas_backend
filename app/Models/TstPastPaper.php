<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TstPastPaper extends Model
{
    use HasFactory;

    protected $fillable = [
        'paper_title',
        'paper_slug',
        'board_id',
        'subject_id',
        'class_id',
        'session_id',
        'group',
        'year',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'group' => 'integer',
        'year' => 'integer',
    ];

    public function pages()
    {
        return $this->hasMany(TstPastPaperPage::class, 'past_paper_id')
            ->orderBy('paper_type')
            ->orderBy('page_no');
    }

    public function board()
    {
        return $this->belongsTo(ExamBoard::class, 'board_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function userClass()
    {
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
