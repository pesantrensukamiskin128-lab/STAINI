<?php

namespace App\Services;

use App\Models\FeeStructure;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Collection;

class FeeStructureResolver
{
    /** @var array<string, Collection> */
    private array $resolvedCache = [];

    /**
     * Ambil tepat satu struktur paling spesifik untuk setiap jenis biaya.
     * Prioritas: prodi + tahun akademik + tingkat, lalu konfigurasi umum.
     */
    public function __construct(private readonly StudentAcademicProgress $progress) {}

    public function resolve(Student $student, Semester $semester, bool $recurringOnly = true): Collection
    {
        $academicLevel = $this->progress->semesterLevel($student, $semester);
        $cohortAcademicYearId = $student->academic_year_id
            ?? AcademicYear::query()
                ->whereYear('start_date', (int) $student->entry_year)
                ->value('id');
        $cacheKey = implode(':', [
            (int) $student->study_program_id,
            (int) $semester->academic_year_id,
            (int) $cohortAcademicYearId,
            $academicLevel ?? 'umum',
            $student->class_type ?? 'REGULER',
            $recurringOnly ? 'recurring' : 'all',
        ]);

        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $structures = FeeStructure::query()
            ->with('feeType')
            ->whereHas('feeType', function ($query) use ($recurringOnly): void {
                $query->where('is_active', true);
                if ($recurringOnly) {
                    $query->where('is_recurring', true);
                }
            })
            ->where(function ($query) use ($student): void {
                $query->whereNull('study_program_id')
                    ->orWhere('study_program_id', $student->study_program_id);
            })
            ->where(function ($query) use ($semester, $cohortAcademicYearId): void {
                $query->whereNull('academic_year_id')
                    ->orWhere('academic_year_id', $semester->academic_year_id)
                    ->when($cohortAcademicYearId, fn ($yearQuery) => $yearQuery
                        ->orWhere('academic_year_id', $cohortAcademicYearId));
            })
            ->when(
                $academicLevel,
                fn ($query) => $query->where(fn ($levelQuery) => $levelQuery
                    ->whereNull('academic_level')
                    ->orWhere('academic_level', $academicLevel)),
                fn ($query) => $query->whereNull('academic_level')
            )
            ->get();

        return $this->resolvedCache[$cacheKey] = $structures
            ->filter(function (FeeStructure $structure) use ($student): bool {
                if ($structure->feeType?->code !== 'SPP') {
                    return $structure->class_type === null;
                }

                return $structure->class_type === null
                    || $structure->class_type === $student->class_type;
            })
            ->sortByDesc(function (FeeStructure $structure) use ($student, $semester, $academicLevel, $cohortAcademicYearId): int {
                $score = (int) $structure->id;
                $score += (int) $structure->study_program_id === (int) $student->study_program_id ? 1_000_000 : 0;
                $isSpp = $structure->feeType?->code === 'SPP';
                $effectiveAcademicYearId = $isSpp
                    ? $cohortAcademicYearId
                    : $semester->academic_year_id;
                $score += (int) $structure->academic_year_id === (int) $effectiveAcademicYearId ? 100_000 : 0;
                $score += $academicLevel && (int) $structure->academic_level === $academicLevel ? 10_000 : 0;
                $score += $structure->feeType?->code === 'SPP'
                    && $structure->class_type === $student->class_type ? 100_000_000 : 0;

                return $score;
            })
            ->unique('fee_type_id')
            ->values();
    }
}
