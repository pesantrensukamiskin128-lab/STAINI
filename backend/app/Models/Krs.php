<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Krs extends Model
{
    protected $table = 'krs';

    protected $appends = ['display_status', 'display_status_label'];

    protected $fillable = [
        'student_id', 'semester_id', 'advisor_id',
        'total_credits', 'status', 'advisor_note',
        'submitted_at', 'approved_at', 'kaprodi_approved_at', 'kaprodi_approved_by',
        'kaprodi_note', 'rejected_by_stage', 'signed_by_kaprodi_at', 'signed_by_kaprodi_id',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'kaprodi_approved_at' => 'datetime',
        'signed_by_kaprodi_at' => 'datetime',
    ];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function semester(): BelongsTo { return $this->belongsTo(Semester::class); }
    public function advisor(): BelongsTo { return $this->belongsTo(Lecturer::class, 'advisor_id'); }
    public function kaprodiApprover(): BelongsTo { return $this->belongsTo(User::class, 'kaprodi_approved_by'); }
    public function details(): HasMany { return $this->hasMany(KrsDetail::class); }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->signed_by_kaprodi_at) {
            return 'DITANDATANGANI';
        }
        if ($this->kaprodi_approved_at) {
            return 'DISETUJUI_PRODI';
        }
        if ($this->status === 'REJECTED' && $this->rejected_by_stage === 'KAPRODI') {
            return 'DITOLAK_PRODI';
        }

        return $this->status;
    }

    public function getDisplayStatusLabelAttribute(): string
    {
        return match ($this->display_status) {
            'DRAFT' => 'Draf',
            'SUBMITTED' => 'Diajukan',
            'APPROVED' => 'Disetujui Dosen Wali',
            'REJECTED' => 'Ditolak',
            'DISETUJUI_PRODI' => 'Disetujui Prodi',
            'DITOLAK_PRODI' => 'Ditolak Prodi',
            'CANCELLED' => 'Dibatalkan',
            'DITANDATANGANI' => 'Ditandatangani',
            default => str($this->display_status)->replace('_', ' ')->toString(),
        };
    }

    public function scopeWhereDisplayStatus(Builder $query, string $status): Builder
    {
        if ($status === 'DITANDATANGANI') {
            return $query->whereNotNull('signed_by_kaprodi_at');
        }
        if ($status === 'DISETUJUI_PRODI') {
            return $query->whereNotNull('kaprodi_approved_at')
                ->whereNull('signed_by_kaprodi_at');
        }
        if ($status === 'DITOLAK_PRODI') {
            return $query->where('status', 'REJECTED')
                ->where('rejected_by_stage', 'KAPRODI');
        }

        $query->where('status', $status);

        if ($status === 'APPROVED') {
            return $query->whereNull('kaprodi_approved_at')
                ->whereNull('signed_by_kaprodi_at');
        }
        if ($status === 'REJECTED') {
            return $query->where(fn (Builder $rejected) => $rejected
                ->whereNull('rejected_by_stage')
                ->orWhere('rejected_by_stage', '!=', 'KAPRODI'));
        }

        return $query;
    }

    public function recalculateCredits(): void
    {
        $total = $this->details()
            ->where('krs_details.status', 'AKTIF')
            ->join('courses', 'krs_details.course_id', '=', 'courses.id')
            ->sum('courses.credits');
        $this->update(['total_credits' => $total]);
    }
}
