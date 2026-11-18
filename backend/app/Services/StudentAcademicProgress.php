<?php

namespace App\Services;

use App\Models\Semester;
use App\Models\Student;

class StudentAcademicProgress
{
    public function semesterLevel(Student $student, Semester $semester): ?int
    {
        $entryYear = (int) $student->entry_year;
        $academicYear = $semester->academicYear;
        $academicYearStart = $academicYear?->start_date?->year;

        if ($entryYear < 1900 || ! $academicYearStart) {
            return null;
        }

        $term = match ($semester->type) {
            'Genap' => 2,
            default => 1,
        };

        return (($academicYearStart - $entryYear) * 2) + $term;
    }

    public function exceedsMaximum(Student $student, Semester $semester, int $maximum = 14): bool
    {
        $level = $this->semesterLevel($student, $semester);

        return $level !== null && $level > $maximum;
    }
}
