<?php

namespace App\Exports;

use App\Models\Student;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StudentAdvisorExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    /**
     * @param  array<int, int>|null  $managedStudyProgramIds  Null means unrestricted admin access.
     */
    public function __construct(
        private ?array $managedStudyProgramIds = null,
        private ?int $studyProgramId = null,
        private ?string $status = null,
    ) {}

    public function title(): string
    {
        return 'Dosen PA Mahasiswa';
    }

    public function query()
    {
        return Student::query()
            ->with(['studyProgram:id,code,name', 'advisor:id,nidn,full_name'])
            ->when($this->managedStudyProgramIds !== null, fn ($query) => $query->whereIn('study_program_id', $this->managedStudyProgramIds))
            ->when($this->studyProgramId, fn ($query) => $query->where('study_program_id', $this->studyProgramId))
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->orderBy('study_program_id')
            ->orderBy('nim');
    }

    public function headings(): array
    {
        return [
            'NIM',
            'Nama Mahasiswa',
            'Kode Prodi',
            'Nama Prodi',
            'NIDN Dosen PA',
            'Nama Dosen PA',
        ];
    }

    public function map($student): array
    {
        return [
            $student->nim,
            $student->name,
            $student->studyProgram?->code ?? '',
            $student->studyProgram?->name ?? '',
            $student->advisor?->nidn ?? '',
            $student->advisor?->full_name ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}
