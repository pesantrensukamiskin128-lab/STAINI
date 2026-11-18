<?php

namespace App\Imports;

use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Semester;
use App\Models\Student;
use App\Services\ExitedStudentInvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class InvoiceImport implements ToCollection, WithHeadingRow
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
                $this->processRow($nim, $row);
            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$line} ({$nim}): ".$e->getMessage();
            }
        }
    }

    private function processRow(string $nim, Collection $row): void
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

        $feeType = FeeType::where('code', $feeCode)->first();
        if (! $feeType) {
            throw new \RuntimeException("Jenis tagihan {$feeCode} tidak ditemukan.");
        }

        if (in_array($student->status, ExitedStudentInvoiceService::EXIT_STATUSES, true)) {
            throw new \RuntimeException("Mahasiswa berstatus {$student->status}, tagihan baru tidak dapat dibuat.");
        }
        if ($student->status === 'Cuti' && $feeCode !== ExitedStudentInvoiceService::LEAVE_FEE_CODE) {
            throw new \RuntimeException('Mahasiswa berstatus Cuti hanya dapat ditagih Biaya Cuti.');
        }

        $existing = Invoice::query()
            ->with('payments')
            ->where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->whereNotIn('status', ['CANCELLED'])
            ->whereHas('items', fn ($q) => $q->where('fee_type_id', $feeType->id))
            ->get();
        if ($existing->isNotEmpty()) {
            $paid = $existing->contains(fn ($i) => $i->status === 'PAID'
                || (float) $i->paid_amount > 0
                || $i->payments->whereIn('status', ['PENDING', 'VERIFIED'])->isNotEmpty());
            if ($paid) {
                $this->skippedPaid++;

                return;
            }
            throw new \RuntimeException("Tagihan {$feeCode} semester {$semester->name} sudah ada.");
        }

        $amount = $this->number($row['jumlah'] ?? null);
        if ($amount <= 0) {
            throw new \RuntimeException('Jumlah tagihan wajib diisi dan lebih dari 0.');
        }
        $discount = $this->number($row['diskon'] ?? null);
        $scholarship = $this->number($row['beasiswa'] ?? null);
        if ($discount + $scholarship > $amount) {
            throw new \RuntimeException('Diskon + beasiswa melebihi jumlah tagihan.');
        }
        $dueDate = $this->parseDate($row['jatuh_tempo'] ?? null);

        if ($this->preview) {
            $this->imported++;

            return;
        }

        $invoice = DB::transaction(function () use ($student, $semester, $feeType, $amount, $discount, $scholarship, $dueDate, $row) {
            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateInvoiceNumber($semester->id),
                'student_id' => $student->id,
                'semester_id' => $semester->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => $dueDate,
                'total_amount' => $amount,
                'discount_amount' => $discount,
                'scholarship_amount' => $scholarship,
                'status' => 'UNPAID',
                'note' => trim((string) ($row['catatan'] ?? '')) ?: 'Import tagihan',
                'created_by' => $this->userId,
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'fee_type_id' => $feeType->id,
                'description' => $feeType->name,
                'amount' => $amount,
            ]);

            return $invoice;
        });
        $this->imported++;
    }

    private function number(mixed $value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) preg_replace('/[^\d]/', '', (string) $value);
    }

    private function parseDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return now()->addDays(30)->toDateString();
        }
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw new \RuntimeException("Jatuh tempo \"{$value}\" tidak valid.");
        }
    }
}
