<?php

namespace App\Exports;

use App\Models\ClassModel;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ClassOfferingExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ?int $semesterId = null,
        private readonly ?array $studyProgramIds = null,
        private readonly ?string $search = null,
    ) {}

    public function sheets(): array
    {
        $classes = ClassModel::query()
            ->with([
                'semester.academicYear', 'studyProgram', 'course.studyProgram',
                'offeredCourses.studyProgram', 'lecturer', 'room',
                'schedules.lecturer', 'schedules.room',
            ])
            ->when($this->semesterId, fn (Builder $query) => $query->where('semester_id', $this->semesterId))
            ->when($this->studyProgramIds !== null, fn (Builder $query) => $query->where(function (Builder $programQuery): void {
                $programQuery
                    ->whereIn('classes.study_program_id', $this->studyProgramIds)
                    ->orWhereHas('offeredCourses', fn (Builder $courseQuery) => $courseQuery->whereIn('courses.study_program_id', $this->studyProgramIds));
            }))
            ->when($this->search, fn (Builder $query) => $query->where(function (Builder $searchQuery): void {
                $searchQuery
                    ->where('classes.name', 'like', "%{$this->search}%")
                    ->orWhereHas('offeredCourses', fn (Builder $courseQuery) => $courseQuery
                        ->where('courses.name', 'like', "%{$this->search}%")
                        ->orWhere('courses.code', 'like', "%{$this->search}%"));
            }))
            ->orderBy('semester_id')
            ->orderBy('study_program_id')
            ->orderBy('course_id')
            ->orderBy('name')
            ->get();

        $classRows = [];
        $courseRows = [];
        $scheduleRows = [];

        foreach ($classes as $class) {
            $reference = 'KLS-'.$class->id;
            $classRows[] = [
                $reference,
                $class->id,
                $class->semester_id,
                $class->semester?->name,
                $class->studyProgram?->code,
                $class->course?->code,
                $class->name,
                $class->capacity,
                $class->academic_level,
                $class->lecturer?->nidn,
                $class->room?->code,
                $class->is_active ? 'Aktif' : 'Nonaktif',
            ];

            $offeredCourses = $class->offeredCourses->isNotEmpty()
                ? $class->offeredCourses
                : collect([$class->course]);
            foreach ($offeredCourses->filter() as $course) {
                $courseRows[] = [
                    $reference,
                    $course->studyProgram?->code,
                    $course->code,
                    $course->pivot?->quota,
                    ($course->pivot?->is_primary ?? $course->id === $class->course_id) ? 'Ya' : 'Tidak',
                    (int) $course->credits !== (int) $class->course?->credits ? 'Ya' : 'Tidak',
                ];
            }

            foreach ($class->schedules as $schedule) {
                $scheduleRows[] = [
                    $reference,
                    $schedule->day,
                    substr((string) $schedule->start_time, 0, 5),
                    substr((string) $schedule->end_time, 0, 5),
                    $schedule->lecturer?->nidn,
                    $schedule->room?->code,
                    $schedule->note,
                    $schedule->is_active ? 'Aktif' : 'Nonaktif',
                ];
            }
            if ($class->schedules->isEmpty()) {
                $scheduleRows[] = [$reference, '', '', '', '', '', '', 'Aktif'];
            }
        }

        return self::workbook($classRows, $courseRows, $scheduleRows);
    }

    public static function template(): WithMultipleSheets
    {
        return new class implements WithMultipleSheets
        {
            public function sheets(): array
            {
                return ClassOfferingExport::workbook(
                    [['KELAS-CONTOH-1', '', '', '2026/2027 Ganjil', 'PAI', 'PAI101', 'A', 40, 1, '0123456789', 'R101', 'Aktif']],
                    [
                        ['KELAS-CONTOH-1', 'PAI', 'PAI101', 25, 'Ya', 'Tidak'],
                        ['KELAS-CONTOH-1', 'HKI', 'HKI101', 15, 'Tidak', 'Tidak'],
                    ],
                    [
                        ['KELAS-CONTOH-1', 'Senin', '08:00', '09:40', '0123456789', 'R101', 'Tatap muka', 'Aktif'],
                        ['KELAS-CONTOH-1', 'Rabu', '10:00', '11:40', '0123456789', 'R101', 'Tatap muka', 'Aktif'],
                    ],
                    true,
                );
            }
        };
    }

    public static function workbook(array $classRows, array $courseRows, array $scheduleRows, bool $template = false): array
    {
        return [
            new ClassOfferingSheet('Kelas', [
                'referensi_kelas', 'id_kelas', 'id_semester', 'nama_semester',
                'kode_prodi_penyelenggara', 'kode_mk_utama', 'nama_kelas',
                'kapasitas', 'tingkat', 'nidn_dosen_utama', 'kode_ruangan_utama', 'status',
            ], $classRows, $template),
            new ClassOfferingSheet('Mata Kuliah', [
                'referensi_kelas', 'kode_prodi', 'kode_mata_kuliah', 'kuota',
                'utama', 'konfirmasi_beda_sks',
            ], $courseRows, $template),
            new ClassOfferingSheet('Jadwal', [
                'referensi_kelas', 'hari', 'jam_mulai', 'jam_selesai',
                'nidn_dosen', 'kode_ruangan', 'catatan', 'status',
            ], $scheduleRows, $template),
        ];
    }
}
