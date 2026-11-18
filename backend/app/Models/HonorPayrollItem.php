<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HonorPayrollItem extends Model
{
    protected $fillable = [
        'honor_payroll_id', 'lecturer_id', 'staff_id', 'honor_rate_id', 'source_key',
        'description', 'activity_date', 'quantity', 'unit_rate_snapshot',
        'amount_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'quantity' => 'decimal:2',
            'unit_rate_snapshot' => 'integer',
            'amount_snapshot' => 'integer',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(HonorPayroll::class, 'honor_payroll_id');
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(HonorRate::class, 'honor_rate_id');
    }
}
