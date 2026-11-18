<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HonorActivity extends Model
{
    protected $fillable = [
        'lecturer_id', 'honor_rate_id', 'semester_id', 'source_key', 'source_type',
        'source_id', 'reference_number', 'description', 'activity_date', 'quantity', 'unit_rate_snapshot',
        'amount_snapshot', 'status', 'created_by', 'verified_by', 'verified_at',
        'verification_note',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'quantity' => 'decimal:2',
            'unit_rate_snapshot' => 'integer',
            'amount_snapshot' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(HonorRate::class, 'honor_rate_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(HonorPayrollItem::class, 'source_key', 'source_key');
    }
}
