<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpkpsGradeScale extends Model
{
    protected $fillable = [
        'rpkps_id', 'min_score', 'max_score', 'letter', 'grade_point', 'order',
    ];

    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'grade_point' => 'decimal:2',
    ];

    public function rpkps(): BelongsTo
    {
        return $this->belongsTo(Rpkps::class);
    }
}
