<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryRepositoryItem extends Model
{
    protected $fillable = [
        'identifier', 'title', 'authors', 'abstract', 'keywords', 'item_type',
        'study_program_id', 'publication_year', 'language', 'publisher',
        'file_path', 'file_size', 'access_level', 'embargo_until', 'license_name',
        'status', 'review_note', 'submitted_by', 'reviewed_by', 'download_count',
        'published_at',
    ];

    protected $casts = [
        'embargo_until' => 'date',
        'published_at' => 'datetime',
    ];

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
