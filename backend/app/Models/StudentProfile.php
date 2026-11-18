<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    protected $fillable = [
        'student_id', 'religion', 'nik', 'nisn',
        'blood_type', 'marital_status', 'nationality', 'photo_path',
        'admission_path', 'npwp', 'registration_type', 'enrollment_date',
        'start_semester', 'residence_type', 'transport_mode', 'kps_recipient',
        'kps_number', 'financing_type', 'recognized_credits',
        'origin_institution_code', 'origin_institution_name',
        'origin_program_code', 'origin_program_name', 'entry_fee',
    ];

    protected $casts = [
        'enrollment_date' => 'date',
        'kps_recipient' => 'boolean',
        'entry_fee' => 'decimal:0',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
