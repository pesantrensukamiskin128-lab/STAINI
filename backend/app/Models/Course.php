<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    protected $fillable = [
        'study_program_id', 'code', 'name', 'course_group', 'credits',
        'theory_credits', 'practical_credits', 'semester', 'type', 'status',
        'delivery_mode',
    ];

    protected $casts = ['status' => 'boolean'];

    public const DELIVERY_FACE_TO_FACE = 'TATAP_MUKA';

    public const DELIVERY_NON_FACE_TO_FACE = 'NON_TATAP_MUKA';

    public function requiresClassLogistics(): bool
    {
        return $this->delivery_mode !== self::DELIVERY_NON_FACE_TO_FACE;
    }

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function curriculums(): BelongsToMany
    {
        return $this->belongsToMany(Curriculum::class, 'curriculum_courses')
            ->withPivot('semester', 'is_required')
            ->withTimestamps();
    }

    public function offeredClasses(): BelongsToMany
    {
        return $this->belongsToMany(ClassModel::class, 'class_courses', 'course_id', 'class_id')
            ->withPivot('curriculum_id', 'is_primary', 'quota')
            ->withTimestamps();
    }

    public function scopeInActiveCurriculum(Builder $query, ?int $studyProgramId = null): Builder
    {
        return $query->whereHas('curriculums', function (Builder $curriculumQuery) use ($studyProgramId): void {
            $curriculumQuery
                ->where('status', 'Aktif')
                ->when($studyProgramId, fn (Builder $query) => $query->where('study_program_id', $studyProgramId));
        });
    }
}
