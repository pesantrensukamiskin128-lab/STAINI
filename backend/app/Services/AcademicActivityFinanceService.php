<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Semester;
use App\Models\Student;
use Carbon\Carbon;
use DomainException;

class AcademicActivityFinanceService
{
    public function __construct(private readonly FeeStructureResolver $feeStructures) {}

    public function assertSppPaidThrough(
        Student $student,
        ?Semester $semester,
        ?Carbon $activityDate = null
    ): void {
        $eligibility = $this->evaluateSpp($student, $semester, $activityDate);
        if (! $eligibility['is_clear']) {
            throw new DomainException($eligibility['message']);
        }
    }

    public function evaluateSpp(
        Student $student,
        ?Semester $semester,
        ?Carbon $activityDate = null
    ): array {
        if (! $semester) {
            return [
                'is_clear' => false,
                'message' => 'Semester kegiatan akademik belum dikonfigurasi.',
                'required_months' => 0,
                'required_amount' => 0,
                'paid_amount' => 0,
            ];
        }

        $activityDate ??= now();
        $sppStructure = $this->feeStructures
            ->resolve($student, $semester, false)
            ->first(fn ($structure) => $structure->feeType?->code === 'SPP');
        $sppAmount = (float) ($sppStructure?->amount ?? 0);
        $requiredMonths = $this->requiredMonths($semester->start_date, $activityDate);
        $requiredAmount = $sppAmount > 0 ? ceil($sppAmount * $requiredMonths / 6) : 0;

        $invoices = Invoice::query()
            ->with(['items.feeType', 'payments'])
            ->where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->where('status', '!=', 'CANCELLED')
            ->get();
        $paidAmount = (float) $invoices->sum(function (Invoice $invoice): float {
            $sppAmount = (float) $invoice->items
                ->filter(fn (InvoiceItem $item) => $item->feeType?->code === 'SPP')
                ->sum('amount');
            if ($sppAmount <= 0) {
                return 0;
            }

            $invoiceAmount = (float) $invoice->items->sum('amount');
            $verifiedPayment = max(
                (float) $invoice->paid_amount,
                (float) $invoice->payments->where('status', 'VERIFIED')->sum('amount')
            );

            return $invoiceAmount > 0
                ? min($sppAmount, $verifiedPayment * ($sppAmount / $invoiceAmount))
                : 0;
        });
        $isClear = $sppStructure !== null && $requiredAmount > 0 && $paidAmount >= $requiredAmount;

        return [
            'is_clear' => $isClear,
            'message' => $isClear
                ? 'Pembayaran SPP sampai bulan kegiatan sudah terpenuhi.'
                : ($sppStructure === null
                    ? 'Struktur SPP untuk angkatan mahasiswa belum dikonfigurasi.'
                    : "Pembayaran SPP sampai bulan kegiatan belum terpenuhi. Minimal {$requiredMonths}/6 SPP harus terverifikasi."),
            'required_months' => $requiredMonths,
            'required_amount' => $requiredAmount,
            'paid_amount' => $paidAmount,
            'semester_id' => $semester->id,
        ];
    }

    private function requiredMonths(?Carbon $semesterStart, Carbon $activityDate): int
    {
        if (! $semesterStart) {
            return 6;
        }

        return min(6, max(1, $semesterStart->copy()->startOfMonth()->diffInMonths(
            $activityDate->copy()->startOfMonth()
        ) + 1));
    }
}
