<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['NIM', 'Semester', 'Kode Jenis Tagihan', 'Jumlah', 'Tanggal Bayar', 'Metode', 'No Referensi', 'Bank', 'Catatan'];
    }

    public function array(): array
    {
        return [
            ['2023120010', 'Ganjil 2025/2026', 'SPP', 1000000, '2026-10-13', 'TRANSFER', 'REF-0001', 'BSI', 'Contoh: kosongkan Jumlah untuk melunasi sisa tagihan'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
