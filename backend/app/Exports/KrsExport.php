<?php

namespace App\Exports;

use App\Models\Krs;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class KrsExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(
        private readonly ?int $semesterId = null,
        private readonly ?array $studyProgramIds = null,
        private readonly ?string $status = null,
        private readonly ?string $search = null,
        private readonly ?int $entryYear = null,
        private readonly ?int $currentSemester = null,
    ) {}

    public function array(): array
    {
        $krsList = Krs::query()
            ->with(['student.studyProgram', 'semester', 'details.course', 'details.class_.lecturer'])
            ->when($this->semesterId, fn (Builder $query) => $query->where('semester_id', $this->semesterId))
            ->when($this->studyProgramIds !== null, fn (Builder $query) => $query->whereHas(
                'student', fn (Builder $studentQuery) => $studentQuery->whereIn('study_program_id', $this->studyProgramIds)
            ))
            ->when($this->status, fn (Builder $query) => $query->whereDisplayStatus($this->status))
            ->when($this->search, fn (Builder $query) => $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery
                ->where('nim', 'like', "%{$this->search}%")
                ->orWhere('name', 'like', "%{$this->search}%")))
            ->when($this->entryYear, fn (Builder $query) => $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery->where('entry_year', $this->entryYear)))
            ->when($this->currentSemester, fn (Builder $query) => $query->whereHas('student', fn (Builder $studentQuery) => $studentQuery->where('current_semester', $this->currentSemester)))
            ->orderBy('semester_id')
            ->orderBy('student_id')
            ->get();

        $rows = [];
        foreach ($krsList as $krs) {
            $details = $krs->details->where('status', 'AKTIF');
            if ($details->isEmpty()) {
                $details = collect([null]);
            }
            foreach ($details as $detail) {
                $rows[] = [
                    $krs->student?->nim,
                    $krs->student?->name,
                    $krs->student?->studyProgram?->code,
                    $krs->semester_id,
                    $krs->semester?->name,
                    $detail?->course?->code,
                    $detail?->course?->name,
                    $detail?->course?->credits,
                    $detail?->class_id,
                    $detail?->class_?->name,
                    $detail?->class_?->lecturer?->name,
                    $krs->display_status_label,
                    $krs->total_credits,
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return self::headingsForImport();
    }

    public static function headingsForImport(): array
    {
        return [
            'nim', 'nama_mahasiswa', 'kode_prodi', 'id_semester', 'nama_semester',
            'kode_mata_kuliah', 'nama_mata_kuliah', 'sks', 'id_kelas', 'nama_kelas', 'dosen_pengampu', 'status_krs', 'total_sks',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]];
    }
}
