<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StudentFinanceReportExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    private int $headerRow = 4;

    public function __construct(private array $report) {}

    public function title(): string
    {
        return 'Laporan Keuangan Mahasiswa';
    }

    public function array(): array
    {
        $isStudent = ($this->report['group_by'] ?? '') === 'student';

        $rows = [
            ['LAPORAN KEUANGAN MAHASISWA'],
            [$this->report['group_label'].($isStudent ? ' (Rincian Mahasiswa)' : '')],
            [$this->report['filters'] ? implode(' | ', $this->report['filters']) : 'Semua data'],
        ];

        if ($isStudent) {
            $rows[] = ['No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Kelas', 'Jml Tagihan', 'Total Tagihan', 'Diskon', 'Beasiswa', 'Dibayar', 'Tunggakan', 'Status'];
            $no = 1;
            foreach ($this->report['rows'] as $r) {
                $rows[] = [
                    $no++,
                    $r['nim'] ?? '-',
                    $r['name'] ?? '-',
                    $r['prodi'] ?? '-',
                    $r['class_type'] ?? '-',
                    $r['invoices'],
                    $r['invoiced'],
                    $r['discount'],
                    $r['scholarship'],
                    $r['paid'],
                    $r['outstanding'],
                    $r['status'] ?? '-',
                ];
            }
            $t = $this->report['totals'];
            $rows[] = ['TOTAL', '', count($this->report['rows']).' Mahasiswa', '', '', $t['invoices'], $t['invoiced'], $t['discount'], $t['scholarship'], $t['paid'], $t['outstanding'], ''];
        } else {
            $rows[] = ['No', $this->report['group_label'], 'Mahasiswa', 'Tagihan', 'Total Tagihan', 'Diskon', 'Beasiswa', 'Dibayar', 'Tunggakan'];
            $no = 1;
            foreach ($this->report['rows'] as $r) {
                $rows[] = [$no++, $r['label'], $r['students'], $r['invoices'], $r['invoiced'], $r['discount'], $r['scholarship'], $r['paid'], $r['outstanding']];
            }
            $t = $this->report['totals'];
            $rows[] = ['TOTAL', '', $t['students'], $t['invoices'], $t['invoiced'], $t['discount'], $t['scholarship'], $t['paid'], $t['outstanding']];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $isStudent = ($this->report['group_by'] ?? '') === 'student';
        $last = $this->headerRow + count($this->report['rows']) + 1;

        if ($isStudent) {
            $sheet->getStyle("G5:K{$last}")->getNumberFormat()->setFormatCode('#,##0');
        } else {
            $sheet->getStyle("E5:I{$last}")->getNumberFormat()->setFormatCode('#,##0');
        }

        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->headerRow => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']]],
            $last => ['font' => ['bold' => true]],
        ];
    }
}
