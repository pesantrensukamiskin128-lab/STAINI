<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HonorRate extends Model
{
    public const CATEGORIES = ['TEACHING', 'TEACHING_SKS', 'STRUCTURAL', 'ADVISOR', 'INCIDENTAL', 'STAFF_MONTHLY'];

    public const UNITS = ['PER_MEETING', 'PER_SKS', 'MONTHLY', 'PER_STUDENT', 'PER_ACTIVITY'];

    public const DELIVERY_MODES = ['ONSITE', 'ONLINE'];

    protected $fillable = [
        'name', 'category', 'unit', 'position_code', 'delivery_mode',
        'amount', 'effective_from', 'effective_until', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(HonorActivity::class);
    }
}
