<?php

namespace App\Models;

use App\Traits\HasFiles;
use App\Support\RpkpsSubCpmkCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Rpkps extends Model
{
    use HasFiles;

    protected $table = 'rpkps';

    protected $fillable = [
        'course_id', 'curriculum_id', 'academic_year_id', 'semester_id',
        'lecturer_id', 'coordinator_id', 'code',
        'course_description', 'course_urgency', 'course_scope',
        'course_position', 'prerequisites',
        'assessment_components', 'final_grade_formula',
        'status', 'version', 'parent_id',
        'approved_by', 'approved_at', 'revision_note',
        'verification_code', 'pdf_path',
    ];

    protected $casts = [
        'assessment_components' => 'array',
        'approved_at'          => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Rpkps $rpkps) {
            if (!$rpkps->verification_code) {
                $rpkps->verification_code = Str::uuid()->toString();
            }
        });
    }

    // === Relations ===

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function curriculum(): BelongsTo { return $this->belongsTo(Curriculum::class); }
    public function academicYear(): BelongsTo { return $this->belongsTo(AcademicYear::class); }
    public function semester(): BelongsTo { return $this->belongsTo(Semester::class); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class); }
    public function coordinator(): BelongsTo { return $this->belongsTo(Lecturer::class, 'coordinator_id'); }
    public function approvedByUser(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function parent(): BelongsTo { return $this->belongsTo(Rpkps::class, 'parent_id'); }

    /** CPL yang dipilih */
    public function cpls(): BelongsToMany
    {
        return $this->belongsToMany(LearningOutcome::class, 'rpkps_cpls', 'rpkps_id', 'learning_outcome_id')
            ->withTimestamps();
    }

    public function cpmks(): HasMany { return $this->hasMany(RpkpsCpmk::class)->orderBy('order')->orderBy('id'); }
    public function learningMaterials(): HasMany { return $this->hasMany(RpkpsLearningMaterial::class)->orderBy('order'); }
    public function weeklyPlans(): HasMany { return $this->hasMany(RpkpsWeeklyPlan::class)->orderBy('week_number'); }
    public function assessments(): HasMany { return $this->hasMany(RpkpsAssessment::class)->orderBy('order'); }
    public function gradeScales(): HasMany { return $this->hasMany(RpkpsGradeScale::class)->orderBy('order'); }
    public function assessmentInstruments(): HasMany { return $this->hasMany(RpkpsAssessmentInstrument::class)->orderBy('order'); }
    public function rubrics(): HasMany { return $this->hasMany(RpkpsRubric::class)->orderBy('order'); }
    public function references(): HasMany { return $this->hasMany(RpkpsReference::class)->orderBy('type')->orderBy('order'); }
    public function approvals(): HasMany { return $this->hasMany(RpkpsApproval::class)->orderByDesc('created_at'); }

    public function refreshSubCpmkCodes(): void
    {
        $codeChanges = [];
        $this->cpmks()->orderBy('order')->orderBy('id')->get()->each(
            function (RpkpsCpmk $cpmk, int $cpmkIndex) use (&$codeChanges): void {
                $cpmk->subCpmks()->orderBy('order')->orderBy('id')->get()->each(
                    function (RpkpsSubCpmk $subCpmk, int $subIndex) use ($cpmkIndex, &$codeChanges): void {
                        $code = RpkpsSubCpmkCode::make($cpmkIndex + 1, $subIndex + 1);
                        $codeChanges[$subCpmk->code][] = $code;
                        $subCpmk->update(['code' => $code]);
                    }
                );
            }
        );

        $replacements = collect($codeChanges)
            ->filter(fn (array $codes): bool => count(array_unique($codes)) === 1)
            ->map(fn (array $codes): string => $codes[0])
            ->all();
        if (!$replacements) {
            return;
        }

        $pattern = '/(?<![\pL\pN_.-])(?:'.implode('|', array_map(
            fn (string $code): string => preg_quote($code, '/'),
            array_keys($replacements)
        )).')(?![\pL\pN_.-])/iu';
        foreach (['rpkps_weekly_plans', 'rpkps_assessment_instruments'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->where('rpkps_id', $this->id)->get()->each(
                function ($record) use ($table, $pattern, $replacements): void {
                    if (!is_string($record->sub_cpmk) || $record->sub_cpmk === '') {
                        return;
                    }
                    $value = preg_replace_callback($pattern, function (array $match) use ($replacements): string {
                        foreach ($replacements as $oldCode => $newCode) {
                            if (strcasecmp($match[0], (string) $oldCode) === 0) {
                                return $newCode;
                            }
                        }
                        return $match[0];
                    }, $record->sub_cpmk);
                    DB::table($table)->where('id', $record->id)->update(['sub_cpmk' => $value]);
                }
            );
        }
    }

    // === Helpers ===

    public static function generateCode(int $courseId): string
    {
        $course = Course::find($courseId);
        $year   = now()->format('Y');
        $count  = self::whereYear('created_at', $year)->count() + 1;
        return "RPKPS-{$course->code}-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    /** Duplikasi RPKPS ke semester baru */
    public function duplicate(int $academicYearId, ?int $semesterId = null): self
    {
        $new = $this->replicate(['code', 'status', 'approved_by', 'approved_at', 'revision_note', 'verification_code', 'pdf_path']);
        $new->academic_year_id = $academicYearId;
        $new->semester_id      = $semesterId;
        $new->code             = self::generateCode($this->course_id);
        $new->status           = 'DRAFT';
        $new->version          = $this->version + 1;
        $new->parent_id        = $this->id;
        $new->save();

        // Duplikasi relasi
        $this->cpls->each(fn($cpl) => $new->cpls()->attach($cpl->id));

        $this->cpmks->each(function ($cpmk) use ($new) {
            $newCpmk = $new->cpmks()->create($cpmk->only(['code', 'description', 'order']));
            $cpmk->subCpmks->each(fn($sub) => $newCpmk->subCpmks()->create($sub->only(['code', 'description', 'order'])));
            $cpmk->cpls->each(fn($cpl) => $newCpmk->cpls()->attach($cpl->id));
        });

        $this->learningMaterials->each(fn($m) => $new->learningMaterials()->create($m->only(['topic', 'subtopics', 'order'])));
        $this->weeklyPlans->each(fn($w) => $new->weeklyPlans()->create($w->only([
            'week_number', 'sub_cpmk', 'indicators', 'learning_material', 'methods',
            'offline_learning', 'online_learning',
            'lecturer_activity', 'student_activity', 'assessment_form', 'assessment_criteria',
            'media', 'duration', 'weight',
        ])));
        $assessmentMap = [];
        $this->assessments->each(function ($assessment) use ($new, &$assessmentMap): void {
            $newAssessment = $new->assessments()->create($assessment->only(['name', 'weight', 'description', 'order']));
            $assessmentMap[$assessment->id] = $newAssessment->id;
        });
        $this->gradeScales->each(fn($scale) => $new->gradeScales()->create($scale->only(['min_score', 'max_score', 'letter', 'grade_point', 'order'])));
        $this->assessmentInstruments->each(function ($instrument) use ($new, $assessmentMap): void {
            $data = $instrument->only([
                'sub_cpmk', 'instrument_name', 'instrument_type', 'technique', 'cognitive_level',
                'affective_level', 'psychomotor_level', 'item_numbers', 'weight', 'timing', 'description', 'order',
            ]);
            $data['rpkps_assessment_id'] = $assessmentMap[$instrument->rpkps_assessment_id] ?? null;
            $new->assessmentInstruments()->create($data);
        });
        $this->rubrics->each(function ($rubric) use ($new, $assessmentMap): void {
            $data = $rubric->only([
                'title', 'criteria', 'weight', 'excellent', 'good', 'fair', 'poor', 'very_poor', 'order',
            ]);
            $data['rpkps_assessment_id'] = $assessmentMap[$rubric->rpkps_assessment_id] ?? null;
            $new->rubrics()->create($data);
        });
        $this->references->each(fn($r) => $new->references()->create($r->only(['type', 'category', 'title', 'author', 'year', 'publisher', 'isbn_doi', 'url', 'order'])));

        return $new;
    }
}
