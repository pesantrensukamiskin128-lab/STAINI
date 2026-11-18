<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use Carbon\Carbon;
use DomainException;

class ExamEligibilityService
{
    public function __construct(private readonly FeeStructureResolver $feeStructures) {}

    public function evaluate(Student $student, Exam $exam): array
    {
        $exam->loadMissing('class_.semester');
        $semester = $exam->class_?->semester;

        if ($student->status !== 'Aktif') {
            return [
                'is_clear' => false,
                'message' => "Mahasiswa berstatus {$student->status}. Ujian hanya untuk mahasiswa Aktif.",
                'required_amount' => 0,
                'paid_amount' => 0,
                'required_months' => 0,
            ];
        }

        if (! $semester) {
            return [
                'is_clear' => false,
                'message' => 'Semester ujian belum dikonfigurasi.',
                'required_amount' => 0,
                'paid_amount' => 0,
                'required_months' => 0,
            ];
        }

        $structures = $this->feeStructures->resolve($student, $semester, false);
        $sppStructure = $structures->first(fn ($structure) => $structure->feeType?->code === 'SPP');
        $examStructure = $structures->first(fn ($structure) => $structure->feeType?->code === $exam->type);
        $examDate = $this->examDate($exam, $semester);
        $requiredMonths = $this->requiredSppMonths($semester->start_date, $examDate);

        $invoices = Invoice::query()
            ->with(['items.feeType', 'payments'])
            ->where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->where('status', '!=', 'CANCELLED')
            ->get();

        $coveredCodes = $invoices
            ->flatMap(fn (Invoice $invoice) => $invoice->items->pluck('feeType.code'))
            ->filter()
            ->unique();
        $requiredCodes = collect(['SPP', $exam->type]);
        $missingCodes = $requiredCodes->diff($coveredCodes)->values();

        $sppAmount = (float) ($sppStructure?->amount ?? 0);
        $requiredSppAmount = $sppAmount > 0 ? ceil($sppAmount * $requiredMonths / 6) : 0;
        $requiredExamAmount = (float) ($examStructure?->amount ?? 0);
        $requiredAmount = $requiredSppAmount + $requiredExamAmount;
        $paidAmount = (float) $invoices->sum(function (Invoice $invoice) use ($requiredCodes): float {
            $requiredAmount = (float) $invoice->items
                ->filter(fn (InvoiceItem $item) => $requiredCodes->contains($item->feeType?->code))
                ->sum('amount');
            $invoiceAmount = (float) $invoice->items->sum('amount');
            if ($requiredAmount <= 0 || $invoiceAmount <= 0) {
                return 0;
            }

            $verifiedPayment = max(
                (float) $invoice->paid_amount,
                (float) $invoice->payments->where('status', 'VERIFIED')->sum('amount')
            );
            return min($requiredAmount, $verifiedPayment * ($requiredAmount / $invoiceAmount));
        });
        $isClear = $sppStructure !== null
            && $examStructure !== null
            && $missingCodes->isEmpty()
            && $paidAmount >= $requiredAmount;

        return [
            'is_clear' => $isClear,
            'message' => $isClear
                ? "Pembayaran {$exam->type} dan SPP sampai bulan kegiatan sudah terpenuhi."
                : $this->blockingMessage($exam->type, $missingCodes, $requiredSppAmount, $requiredExamAmount, $paidAmount),
            'required_amount' => $requiredAmount,
            'paid_amount' => $paidAmount,
            'required_months' => $requiredMonths,
            'required_spp_amount' => $requiredSppAmount,
            'required_exam_amount' => $requiredExamAmount,
            'missing_fee_types' => $missingCodes->values()->all(),
            'semester_id' => $semester->id,
        ];
    }

    public function assertEligible(Student $student, Exam $exam): void
    {
        $eligibility = $this->evaluate($student, $exam);
        if (! $eligibility['is_clear']) {
            throw new DomainException($eligibility['message']);
        }
    }

    private function examDate(Exam $exam, object $semester): Carbon
    {
        if ($exam->start_time) {
            return $exam->start_time->copy()->startOfDay();
        }

        return ($exam->type === 'UTS' ? $semester->exam_mid_start : $semester->exam_final_start)
            ?? $semester->start_date
            ?? now();
    }

    private function requiredSppMonths(?Carbon $semesterStart, Carbon $examDate): int
    {
        if (! $semesterStart) {
            return 6;
        }

        return min(6, max(1, $semesterStart->copy()->startOfMonth()->diffInMonths($examDate->copy()->startOfMonth()) + 1));
    }

    private function blockingMessage(string $type, $missingCodes, float $sppAmount, float $examAmount, float $paidAmount): string
    {
        if ($missingCodes->isNotEmpty() || $sppAmount <= 0 || $examAmount <= 0) {
            return 'Tagihan SPP dan/atau '.$type.' untuk semester ini belum dibuat.';
        }

        return "Pembayaran belum memenuhi syarat {$type}: SPP minimum Rp "
            .number_format($sppAmount, 0, ',', '.')
            ." dan {$type} Rp ".number_format($examAmount, 0, ',', '.')
            .". Total terverifikasi Rp ".number_format($paidAmount, 0, ',', '.').'.';
    }
}
