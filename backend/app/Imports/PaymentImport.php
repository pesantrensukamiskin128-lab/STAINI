<?php

namespace App\Imports;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class PaymentImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public int $skippedPaid = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public function __construct(private readonly bool $preview = false, private readonly ?int $userId = null) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $nim = trim((string) ($row['nim'] ?? ''));
            if ($nim === '') {
                continue;
            }

            try {
                $this->processRow($line, $nim, $row);
            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$line} ({$nim}): ".$e->getMessage();
            }
        }
    }

    private function processRow(int $line, string $nim, Collection $row): void
    {
        $student = Student::where('nim', $nim)->first();
        if (! $student) {
            throw new \RuntimeException('NIM tidak ditemukan.');
        }

        $semesterName = trim((string) ($row['semester'] ?? ''));
        $feeCode = strtoupper(trim((string) ($row['kode_jenis_tagihan'] ?? '')));
        if ($semesterName === '' || $feeCode === '') {
            throw new \RuntimeException('Semester dan Kode Jenis Tagihan wajib diisi.');
        }

        $semester = Semester::whereRaw('LOWER(name) = ?', [mb_strtolower($semesterName)])->first();
        if (! $semester) {
            throw new \RuntimeException("Semester \"{$semesterName}\" tidak ditemukan.");
        }

        $invoice = Invoice::query()
            ->with('payments')
            ->where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->whereNotIn('status', ['CANCELLED', 'WAIVED'])
            ->whereHas('items.feeType', fn ($q) => $q->where('code', $feeCode))
            ->orderBy('id')
            ->first();
        if (! $invoice) {
            throw new \RuntimeException("Tagihan {$feeCode} semester {$semester->name} tidak ditemukan.");
        }

        $alreadyPaid = $invoice->status === 'PAID'
            || (float) $invoice->paid_amount > 0
            || $invoice->payments->whereIn('status', ['PENDING', 'VERIFIED'])->isNotEmpty();
        if ($alreadyPaid) {
            $this->skippedPaid++;

            return;
        }

        $net = (float) $invoice->total_amount - (float) $invoice->discount_amount - (float) $invoice->scholarship_amount;
        $amountRaw = $row['jumlah'] ?? null;
        $amount = ($amountRaw === null || trim((string) $amountRaw) === '')
            ? $net
            : (float) preg_replace('/[^\d.]/', '', str_replace(',', '.', (string) $amountRaw));
        if ($amount <= 0) {
            throw new \RuntimeException('Jumlah pembayaran tidak valid.');
        }
        if ($amount > $net) {
            throw new \RuntimeException('Jumlah melebihi sisa tagihan.');
        }

        $reference = trim((string) ($row['no_referensi'] ?? '')) ?: null;
        if ($reference && Payment::where('reference_number', $reference)->where('status', '!=', 'REJECTED')->exists()) {
            throw new \RuntimeException("No. referensi {$reference} sudah dipakai.");
        }

        $paymentDate = $this->parseDate($row['tanggal_bayar'] ?? null);

        if ($this->preview) {
            $this->imported++;

            return;
        }

        DB::transaction(function () use ($invoice, $amount, $row, $reference, $paymentDate): void {
            Payment::createWithGeneratedPaymentNumber([
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'amount' => $amount,
                'payment_method' => trim((string) ($row['metode'] ?? '')) ?: 'TRANSFER',
                'payment_date' => $paymentDate,
                'reference_number' => $reference,
                'bank_name' => trim((string) ($row['bank'] ?? '')) ?: null,
                'note' => trim((string) ($row['catatan'] ?? '')) ?: 'Import pembayaran',
                'status' => 'VERIFIED',
                'verified_by' => $this->userId,
                'verified_at' => now(),
            ]);
            $invoice->recalculateStatus();
        });
        $this->imported++;
    }

    private function parseDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return now()->toDateString();
        }
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw new \RuntimeException("Tanggal bayar \"{$value}\" tidak valid.");
        }
    }
}
