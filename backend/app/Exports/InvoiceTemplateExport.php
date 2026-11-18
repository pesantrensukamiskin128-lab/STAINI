<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoiceTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['NIM', 'Semester', 'Kode Jenis Tagihan', 'Jumlah', 'Jatuh Tempo', 'Diskon', 'Beasiswa', 'Catatan'];
    }

    public function array(): array
    {
        return [
            ['2023120010', 'Ganjil 2025/2026', 'SPP', 1500000, '2026-11-30', 0, 0, 'Contoh baris'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
