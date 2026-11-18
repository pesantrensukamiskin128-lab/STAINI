<?php

namespace App\Imports;

use App\Models\Lecturer;
use App\Models\Student;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentAdvisorImport implements ToCollection, WithHeadingRow
{
    private array $errors = [];

    private int $updated = 0;

    /**
     * @param  array<int, int>|null  $managedStudyProgramIds  Null means unrestricted admin access.
     */
    public function __construct(private ?array $managedStudyProgramIds = null) {}

    public function collection(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->errors[] = 'File tidak memiliki baris data.';

            return;
        }

        $firstRow = collect($rows->first())->all();
        if (! array_key_exists('nim', $firstRow) || ! array_key_exists('nidn_dosen_pa', $firstRow)) {
            $this->errors[] = 'Format kolom tidak sesuai. Gunakan template Dosen PA Mahasiswa.';

            return;
        }

        $seenNims = [];
        foreach ($rows as $index => $row) {
            $row = collect($row);
            $rowNumber = $index + 2;
            $nim = trim((string) ($row['nim'] ?? ''));
            $nidn = trim((string) ($row['nidn_dosen_pa'] ?? ''));

            if ($nim === '' && $nidn === '') {
                continue;
            }
            if ($nim === '') {
                $this->errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                continue;
            }
            if (isset($seenNims[$nim])) {
                $this->errors[] = "Baris {$rowNumber}: NIM {$nim} tercantum lebih dari sekali.";

                continue;
            }
            $seenNims[$nim] = true;

            $studentQuery = Student::query()->where('nim', $nim);
            if ($this->managedStudyProgramIds !== null) {
                $studentQuery->whereIn('study_program_id', $this->managedStudyProgramIds);
            }
            $student = $studentQuery->first();
            if (! $student) {
                $this->errors[] = "Baris {$rowNumber}: mahasiswa dengan NIM {$nim} tidak ditemukan atau di luar cakupan prodi Anda.";

                continue;
            }

            $advisorId = null;
            if ($nidn !== '') {
                $advisorId = Lecturer::query()->where('nidn', $nidn)->value('id');
                if (! $advisorId) {
                    $this->errors[] = "Baris {$rowNumber}: dosen dengan NIDN {$nidn} tidak ditemukan.";

                    continue;
                }
            }

            if ((int) $student->advisor_id !== (int) $advisorId) {
                $student->advisor_id = $advisorId;
                $student->save();
                $this->updated++;
                if ($advisorId !== null) {
                    NotificationService::advisorAssigned((int) $advisorId, $student->name, $student->nim);
                }
            }
        }
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function updatedCount(): int
    {
        return $this->updated;
    }
}
