<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HonorPayrollDeduction extends Model
{
    protected $fillable = [
        'honor_payroll_id', 'lecturer_id', 'staff_id', 'deduction_type',
        'description', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
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
}
