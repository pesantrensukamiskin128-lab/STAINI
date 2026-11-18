<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClassJournalsExport implements FromArray, WithStyles, ShouldAutoSize
{
    public function __construct(private readonly object $class, private readonly Collection $journals, private readonly string $verifyUrl) {}

    public function array(): array
    {
        return [
            ['JURNAL KEGIATAN PEMBELAJARAN'],
            ['Mata Kuliah', $this->class->course?->code.' - '.$this->class->course?->name],
            ['Kelas', $this->class->name],
            ['Semester', $this->class->semester?->name ?? '-'],
            ['Dosen Pengampu', $this->class->lecturer?->display_name ?? $this->class->lecturer?->full_name ?? '-'],
            ['URL Verifikasi', $this->verifyUrl],
            [],
            ['No', 'Pertemuan', 'Tanggal', 'Topik/Materi', 'Deskripsi', 'Kegiatan Pembelajaran', 'Status', 'Jumlah Hadir'],
            ...$this->journals->values()->map(fn ($journal, $index) => [
                $index + 1,
                $journal->meeting_number,
                $journal->meeting_date?->format('d/m/Y') ?? '-',
                $journal->topic,
                $journal->description ?? '-',
                $journal->learning_activity ?? '-',
                $journal->status ?? '-',
                $journal->present_count ?? 0,
            ])->all(),
            [],
            ['Dokumen ini dapat diverifikasi melalui URL pada baris di atas.'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            8 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ],
            'A1:H1' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'A8:H8' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
        ];
    }
}
