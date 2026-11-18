<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraduationRegistration extends Model
{
    protected $fillable = [
        'period_id', 'student_id', 'status', 'toga_size', 'phone', 'address_current',
        'total_credits', 'gpa', 'thesis_title', 'predicate', 'notes', 'submitted_at',
        'impression_message', 'payment_proof_path', 'photo_path',
    ];
    protected $casts = ['submitted_at' => 'datetime'];
    protected $appends = ['payment_proof_url', 'photo_url'];

    public function getPaymentProofUrlAttribute(): ?string
    {
        return $this->payment_proof_path ? url(\Illuminate\Support\Facades\Storage::disk('public')->url($this->payment_proof_path)) : null;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? url(\Illuminate\Support\Facades\Storage::disk('public')->url($this->photo_path)) : null;
    }

    public function period(): BelongsTo { return $this->belongsTo(GraduationPeriod::class, 'period_id'); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function verifications(): HasMany { return $this->hasMany(GraduationVerification::class, 'registration_id'); }
    public function documents(): HasMany { return $this->hasMany(GraduationDocument::class, 'registration_id'); }
}
