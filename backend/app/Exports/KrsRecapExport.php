<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class KrsRecapExport implements WithMultipleSheets
{
    public function __construct(private array $summary, private array $detail, private array $filters) {}

    public function sheets(): array
    {
        $filterText = $this->filters ? implode(' | ', $this->filters) : 'Semua data';

        return [
            new KrsRecapSheet('Rekap KRS', 'REKAP KRS MAHASISWA PER SEMESTER', $filterText,
                ['No', 'Semester', 'Tahun Akademik', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Angkatan', 'Jenis Kelas', 'Status Mahasiswa', 'Status KRS', 'Jml MK', 'Total SKS'],
                array_map(fn ($r) => [$r['semester'], $r['academic_year'], $r['nim'], $r['name'], $r['prodi'], $r['entry_year'], $r['class_type'], $r['student_status'], $r['status'], $r['course_count'], $r['total_credits']], $this->summary)),
            new KrsRecapSheet('Rincian Mata Kuliah', 'RINCIAN MATA KULIAH KRS', $filterText,
                ['No', 'Semester', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Jenis Kelas', 'Kode MK', 'Mata Kuliah', 'SKS', 'Kelas'],
                array_map(fn ($r) => [$r['semester'], $r['nim'], $r['name'], $r['prodi'], $r['class_type'], $r['course_code'], $r['course_name'], $r['credits'], $r['class_name']], $this->detail)),
        ];
    }
}

class KrsRecapSheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(private string $title, private string $heading, private string $filterText, private array $header, private array $rows) {}

    public function title(): string
    {
        return $this->title;
    }

    public function array(): array
    {
        $out = [[$this->heading], [$this->filterText], [], $this->header];
        foreach ($this->rows as $i => $row) {
            $out[] = array_merge([$i + 1], $row);
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            4 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']]],
        ];
    }
}
