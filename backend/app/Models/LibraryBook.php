<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LibraryBook extends Model
{
    protected $fillable = [
        'legacy_id', 'accession_no', 'barcode', 'title', 'subtitle', 'author',
        'isbn', 'publisher', 'publish_year', 'language', 'category',
        'study_program_id', 'classification', 'shelf', 'description',
        'cover_path', 'digital_path', 'access_type', 'total_copies',
        'available_copies', 'view_count', 'status', 'created_by',
    ];

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class, 'book_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LibraryReservation::class, 'book_id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'library_favorites', 'book_id', 'user_id')->withTimestamps();
    }
}
