<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoiceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private Collection $invoices) {}

    public function collection(): Collection
    {
        return $this->invoices;
    }

    public function headings(): array
    {
        return ['No. Invoice', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Jenis Kelas', 'Semester', 'Jenis Tagihan',
            'Tanggal Tagihan', 'Jatuh Tempo', 'Total', 'Diskon', 'Beasiswa', 'Terbayar', 'Status', 'Catatan'];
    }

    public function map($invoice): array
    {
        return [
            $invoice->invoice_number,
            $invoice->student?->nim,
            $invoice->student?->name,
            $invoice->student?->studyProgram?->name,
            $invoice->student?->class_type,
            $invoice->semester?->name,
            $invoice->items->pluck('feeType.name')->filter()->implode(', '),
            optional($invoice->invoice_date)->format('Y-m-d'),
            optional($invoice->due_date)->format('Y-m-d'),
            (float) $invoice->total_amount,
            (float) $invoice->discount_amount,
            (float) $invoice->scholarship_amount,
            (float) $invoice->payments->where('status', 'VERIFIED')->sum('amount'),
            $invoice->status,
            $invoice->note,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
