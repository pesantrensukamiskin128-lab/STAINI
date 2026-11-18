<?php

namespace App\Services;

use App\Models\ClassMember;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Krs;
use App\Models\KrsDetail;
use DomainException;
use Illuminate\Support\Facades\DB;

class KrsEnrollmentService
{
    public function scheduleConflicts(Krs $krs): array
    {
        $details = $krs->details()
            ->where('krs_details.status', 'AKTIF')
            ->whereNotNull('class_id')
            ->with(['course:id,code,name', 'class_.schedules' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->filter(fn (KrsDetail $detail) => $detail->class_?->is_active
                && (int) $detail->class_?->semester_id === (int) $krs->semester_id)
            ->values();

        $conflicts = [];
        for ($leftIndex = 0; $leftIndex < $details->count(); $leftIndex++) {
            $left = $details[$leftIndex];
            foreach ($left->class_?->schedules ?? [] as $leftSchedule) {
                foreach ($details->slice($leftIndex + 1) as $right) {
                    if ((int) $left->class_id === (int) $right->class_id) {
                        continue;
                    }

                    foreach ($right->class_?->schedules ?? [] as $rightSchedule) {
                        if (
                            $leftSchedule->day !== $rightSchedule->day
                            || $leftSchedule->start_time >= $rightSchedule->end_time
                            || $leftSchedule->end_time <= $rightSchedule->start_time
                        ) {
                            continue;
                        }

                        $conflicts[] = [
                            'day' => $leftSchedule->day,
                            'course_a' => $left->course?->name ?? 'Mata kuliah',
                            'class_a' => $left->class_?->name ?? '-',
                            'start_a' => substr((string) $leftSchedule->start_time, 0, 5),
                            'end_a' => substr((string) $leftSchedule->end_time, 0, 5),
                            'course_b' => $right->course?->name ?? 'Mata kuliah',
                            'class_b' => $right->class_?->name ?? '-',
                            'start_b' => substr((string) $rightSchedule->start_time, 0, 5),
                            'end_b' => substr((string) $rightSchedule->end_time, 0, 5),
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    public function addCourse(Krs $krs, int $courseId, ?int $classId, bool $ignoreExisting = false, bool $allowAnyStatus = false): bool
    {
        if (! $allowAnyStatus && ! in_array($krs->status, ['DRAFT', 'REJECTED'], true)) {
            throw new DomainException('KRS sudah disubmit, tidak dapat diubah.');
        }

        $course = Course::findOrFail($courseId);
        $krs->loadMissing('student');
        if ((int) $course->study_program_id !== (int) $krs->student?->study_program_id) {
            throw new DomainException('Mata kuliah tidak berasal dari program studi mahasiswa.');
        }

        if ($krs->details()->where('course_id', $course->id)->where('krs_details.status', 'AKTIF')->exists()) {
            if ($ignoreExisting) {
                return false;
            }
            throw new DomainException('Mata kuliah ini sudah ada dalam KRS.');
        }

        $currentCredits = $krs->details()->where('krs_details.status', 'AKTIF')
            ->join('courses', 'krs_details.course_id', '=', 'courses.id')
            ->sum('courses.credits');
        if ($currentCredits + $course->credits > 24) {
            throw new DomainException('Batas SKS terlampaui. Sisa: '.(24 - $currentCredits).' SKS.');
        }

        return DB::transaction(function () use ($krs, $course, $classId): bool {
            $class = null;
            if ($classId) {
                $class = ClassModel::query()->lockForUpdate()->findOrFail($classId);
                if ((int) $class->semester_id !== (int) $krs->semester_id) {
                    throw new DomainException('Kelas tidak berada pada semester KRS yang dipilih.');
                }
                if (! $class->is_active) {
                    throw new DomainException('Kelas yang dipilih sudah tidak aktif.');
                }

                $classCourse = $class->offeredCourses()->where('courses.id', $course->id)->first();
                if (! $classCourse) {
                    throw new DomainException('Mata kuliah tidak ditawarkan pada kelas yang dipilih.');
                }
                if (! $class->isAvailable()) {
                    throw new DomainException('Kuota kelas sudah penuh.');
                }

                $courseQuota = $classCourse->pivot->quota;
                if ($courseQuota !== null) {
                    $courseMembers = KrsDetail::where('class_id', $class->id)
                        ->where('course_id', $course->id)
                        ->where('status', 'AKTIF')
                        ->count();
                    if ($courseMembers >= $courseQuota) {
                        throw new DomainException('Kuota mata kuliah pada kelas gabungan sudah penuh.');
                    }
                }
            }

            KrsDetail::create([
                'krs_id' => $krs->id,
                'course_id' => $course->id,
                'class_id' => $class?->id,
            ]);
            $krs->recalculateCredits();

            if ($class) {
                ClassMember::firstOrCreate([
                    'class_id' => $class->id,
                    'student_id' => $krs->student_id,
                ]);
            }

            return true;
        });
    }
}
