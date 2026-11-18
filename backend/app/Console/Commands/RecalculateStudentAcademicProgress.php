<?php

namespace App\Console\Commands;

use App\Models\Semester;
use App\Models\Student;
use App\Services\StudentAcademicProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateStudentAcademicProgress extends Command
{
    protected $signature = 'students:recalculate-academic-progress
        {--semester= : ID semester yang digunakan sebagai acuan}
        {--dry-run : Tampilkan perubahan tanpa mengubah database}
        {--max=14 : Batas maksimum semester sebelum status menjadi Lulus}';

    protected $description = 'Menghitung semester mahasiswa dari tahun masuk dan menandai mahasiswa yang melewati batas studi.';

    public function handle(StudentAcademicProgress $progress): int
    {
        $semester = Semester::with('academicYear')->find($this->option('semester')) ?? Semester::getActive()?->load('academicYear');
        if (! $semester) {
            $this->error('Semester acuan tidak ditemukan. Aktifkan semester atau gunakan --semester=ID.');

            return self::FAILURE;
        }

        $maximum = max(1, (int) $this->option('max'));
        $dryRun = (bool) $this->option('dry-run');
        $students = Student::query()
            ->whereIn('status', ['Aktif', 'Nonaktif'])
            ->whereNotNull('entry_year')
            ->get(['id', 'nim', 'name', 'entry_year', 'status', 'current_semester']);

        $updates = [];
        $progressUpdates = [];
        $graduates = [];
        foreach ($students as $student) {
            $level = $progress->semesterLevel($student, $semester);
            if ($level === null) {
                continue;
            }

            $updates[] = [$student->nim, $student->name, $student->entry_year, $student->status, $level];
            $progressUpdates[] = [$student, $level];
            if ($level > $maximum && $student->status === 'Aktif') {
                $graduates[] = $student;
            }
        }

        $this->table(
            ['NIM', 'Nama', 'Tahun masuk', 'Status', 'Semester acuan'],
            array_slice($updates, 0, 30)
        );
        $this->info("Semester acuan: {$semester->name}; mahasiswa diproses: ".count($updates)."; melewati batas: ".count($graduates).'.');

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan database.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($progressUpdates, $graduates, $semester, $maximum, $progress): void {
            foreach ($progressUpdates as [$student, $level]) {
                $student->update(['current_semester' => $level]);
            }

            foreach ($graduates as $student) {
                $level = $progress->semesterLevel($student, $semester);
                $student->recordStatus(
                    'Lulus',
                    $semester->id,
                    "Melewati batas studi {$maximum} semester berdasarkan tahun masuk {$student->entry_year}.",
                );
                $student->update(['current_semester' => $level]);
            }
        });

        $this->info('Perubahan status dan riwayat mahasiswa yang melewati batas studi selesai.');

        return self::SUCCESS;
    }
}
