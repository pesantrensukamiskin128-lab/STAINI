<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LecturerWorkAuthor extends Model
{
    protected $fillable = [
        'lecturer_id',
        'student_id',
        'author_order',
    ];

    public function lecturerWork(): BelongsTo
    {
        return $this->belongsTo(LecturerWork::class);
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
