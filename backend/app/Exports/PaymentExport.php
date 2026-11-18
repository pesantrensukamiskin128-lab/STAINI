<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private Collection $payments) {}

    public function collection(): Collection
    {
        return $this->payments;
    }

    public function headings(): array
    {
        return ['No. Pembayaran', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Jenis Kelas', 'No. Invoice', 'Semester',
            'Jenis Tagihan', 'Jumlah', 'Metode', 'Tanggal Bayar', 'No. Referensi', 'Bank', 'Status', 'Catatan'];
    }

    public function map($payment): array
    {
        $invoice = $payment->invoice;

        return [
            $payment->payment_number,
            $payment->student?->nim,
            $payment->student?->name,
            $payment->student?->studyProgram?->name,
            $payment->student?->class_type,
            $invoice?->invoice_number,
            $invoice?->semester?->name,
            $invoice?->items?->pluck('feeType.name')->filter()->implode(', '),
            (float) $payment->amount,
            $payment->payment_method,
            optional($payment->payment_date)->format('Y-m-d'),
            $payment->reference_number,
            $payment->bank_name,
            $payment->status,
            $payment->note,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
