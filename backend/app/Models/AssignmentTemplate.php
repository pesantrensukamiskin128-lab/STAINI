<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'title',
        'description',
        'instructions',
        'type',
        'max_score',
        'allow_late',
    ];

    protected $casts = [
        'max_score' => 'integer',
        'allow_late' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
