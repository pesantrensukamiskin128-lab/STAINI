<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Semester;
use App\Models\Student;
use DomainException;

class KrsEligibilityService
{
    private const KRS_REQUIRED_FEE_TYPE_CODES = ['HER', 'SPP'];

    public function __construct(private readonly FeeStructureResolver $feeStructures) {}

    public function evaluate(Student $student, Semester $semester): array
    {
        $today = now()->startOfDay();
        $periodConfigured = (bool) ($semester->krs_start && $semester->krs_end);
        $periodOpen = ! $periodConfigured || (
            $today->greaterThanOrEqualTo($semester->krs_start->startOfDay())
            && $today->lessThanOrEqualTo($semester->krs_end->endOfDay())
        );

        $exemptedFeeTypeCodes = $this->isFirstSemester2026Freshman($student, $semester)
            ? ['HER', 'SPP']
            : [];
        $requiredStructures = $this->feeStructures->resolve($student, $semester)
            ->filter(fn ($structure) => (bool) $structure->feeType?->is_mandatory
                && in_array($structure->feeType?->code, self::KRS_REQUIRED_FEE_TYPE_CODES, true))
            ->reject(fn ($structure) => in_array($structure->feeType?->code, $exemptedFeeTypeCodes, true));
        $requiredFeeTypeIds = $requiredStructures->pluck('fee_type_id')->map(fn ($id) => (int) $id)->unique()->values();
        $requiredSppFeeTypeIds = $requiredStructures
            ->filter(fn ($structure) => $structure->feeType?->code === 'SPP')
            ->pluck('fee_type_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Data migrasi lama dapat memiliki tagihan wajib sebelum struktur biaya dikonfigurasi.
        if ($requiredFeeTypeIds->isEmpty()) {
            $requiredFeeTypeIds = InvoiceItem::query()
                ->whereHas('invoice', fn ($query) => $query
                    ->where('student_id', $student->id)
                    ->where('semester_id', $semester->id)
                    ->where('status', '!=', 'CANCELLED'))
                ->whereHas('feeType', fn ($query) => $query
                    ->where('is_mandatory', true)
                    ->whereIn('code', self::KRS_REQUIRED_FEE_TYPE_CODES)
                    ->when($exemptedFeeTypeCodes !== [], fn ($feeQuery) => $feeQuery->whereNotIn('code', $exemptedFeeTypeCodes)))
                ->pluck('fee_type_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $invoices = Invoice::query()
            ->with(['items.feeType', 'payments'])
            ->where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->where('status', '!=', 'CANCELLED')
            ->get();

        if ($requiredSppFeeTypeIds->isEmpty() && $requiredFeeTypeIds->isNotEmpty()) {
            $requiredSppFeeTypeIds = $invoices
                ->flatMap(fn (Invoice $invoice) => $invoice->items
                    ->filter(fn (InvoiceItem $item) => $item->feeType?->code === 'SPP'
                        && $requiredFeeTypeIds->contains((int) $item->fee_type_id))
                    ->pluck('fee_type_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $sppInvoicesWithBalance = $invoices->filter(function (Invoice $invoice) use ($requiredSppFeeTypeIds): bool {
            $requiredSppAmount = $invoice->items
                ->filter(fn (InvoiceItem $item) => $item->feeType?->code === 'SPP'
                    && $requiredSppFeeTypeIds->contains((int) $item->fee_type_id))
                ->sum('amount');
            $sppCoveredByAdjustments = (float) $invoice->discount_amount + (float) $invoice->scholarship_amount;

            return $requiredSppAmount > $sppCoveredByAdjustments;
        });
        $sppPaymentCount = $sppInvoicesWithBalance->sum(fn (Invoice $invoice): int =>
            $invoice->payments->where('status', 'VERIFIED')->filter(fn ($payment) => (float) $payment->amount > 0)->count()
        );
        $requiresSppPayments = $sppInvoicesWithBalance->isNotEmpty();
        $sppPaymentsClear = ! $requiresSppPayments || $sppPaymentCount >= 1;

        $coveredFeeTypeIds = $invoices
            ->flatMap(fn (Invoice $invoice) => $invoice->items
                ->whereIn('fee_type_id', $requiredFeeTypeIds->all())
                ->pluck('fee_type_id'))
            ->map(fn ($id) => (int) $id)
            ->unique();

        $missingFeeTypeIds = $requiredFeeTypeIds->diff($coveredFeeTypeIds)->values();
        $blockingInvoices = $invoices
            ->filter(function (Invoice $invoice) use ($requiredFeeTypeIds): bool {
                $requiredItems = $invoice->items->whereIn('fee_type_id', $requiredFeeTypeIds->all());
                if ($requiredItems->isEmpty()) {
                    return false;
                }

                $otherRequiredAmount = $requiredItems
                    ->reject(fn (InvoiceItem $item) => $item->feeType?->code === 'SPP')
                    ->sum('amount');
                $minimumPayment = $otherRequiredAmount;
                $discounts = (float) $invoice->discount_amount + (float) $invoice->scholarship_amount;
                $minimumPayment = max(0, $minimumPayment - $discounts);
                $verifiedPayment = max(
                    (float) $invoice->paid_amount,
                    (float) $invoice->payments->where('status', 'VERIFIED')->sum('amount')
                );

                return $verifiedPayment < $minimumPayment;
            })
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'due_date' => $invoice->due_date,
                'total_amount' => $invoice->total_amount,
                'paid_amount' => $invoice->paid_amount,
            ])
            ->values();

        $financeConfigured = $requiredFeeTypeIds->isNotEmpty();
        $financeConfigured = $financeConfigured || $exemptedFeeTypeCodes !== [];
        $financeClear = $financeConfigured
            && $missingFeeTypeIds->isEmpty()
            && $blockingInvoices->isEmpty()
            && $sppPaymentsClear;
        $krsPaymentExempt = $this->isKrsPaymentExemptFreshman($student, $semester);

        return [
            'semester_id' => $semester->id,
            'period' => [
                'configured' => $periodConfigured,
                'is_open' => $periodOpen,
                'start_date' => $semester->krs_start?->format('Y-m-d'),
                'end_date' => $semester->krs_end?->format('Y-m-d'),
                'message' => ! $periodConfigured
                    ? 'Periode KRS belum dikonfigurasi; akses sementara tetap dibuka.'
                    : ($periodOpen ? 'Periode pengisian KRS sedang dibuka.' : 'Periode pengisian KRS sedang ditutup.'),
            ],
            'finance' => [
                'configured' => $financeConfigured,
                'is_clear' => $financeClear,
                'krs_payment_exempt' => $krsPaymentExempt,
                'required_fee_type_ids' => $requiredFeeTypeIds->all(),
                'exempted_fee_type_codes' => $exemptedFeeTypeCodes,
                'missing_fee_type_ids' => $missingFeeTypeIds->all(),
                'blocking_invoices' => $blockingInvoices,
                'verified_spp_payments' => $sppPaymentCount,
                'required_spp_payments' => $requiresSppPayments ? 1 : 0,
                'message' => $krsPaymentExempt
                    ? 'Mahasiswa Angkatan 2026 dapat membuat, mengajukan, dan memproses KRS pada Tahun Akademik 2026/2027 tanpa pelunasan tagihan. Tagihan tetap tercatat dan kewajiban pembayarannya tidak dihapus.'
                    : ($financeClear && $exemptedFeeTypeCodes !== []
                    ? 'Herregistrasi dan SPP dikecualikan sebagai syarat KRS mahasiswa baru Angkatan 2026 semester 1.'
                    : (! $financeConfigured
                    ? 'Struktur/tagihan biaya wajib semester ini belum dikonfigurasi.'
                    : ($missingFeeTypeIds->isNotEmpty()
                        ? 'Tagihan wajib semester ini belum lengkap.'
                        : (! $sppPaymentsClear
                            ? 'Minimal satu pembayaran SPP harus terverifikasi. Nominal pembayaran bebas.'
                            : ($blockingInvoices->isNotEmpty()
                                ? 'Pembayaran tagihan wajib selain SPP semester ini belum terpenuhi.'
                                : 'Kewajiban keuangan untuk KRS sudah terpenuhi.'))))),
            ],
            'can_submit' => $periodOpen && ($financeClear || $krsPaymentExempt),
        ];
    }

    private function isFirstSemester2026Freshman(Student $student, Semester $semester): bool
    {
        return (int) $student->entry_year === 2026
            && $semester->type === 'Ganjil'
            && (int) $semester->academicYear?->start_date?->year === 2026;
    }

    private function isKrsPaymentExemptFreshman(Student $student, Semester $semester): bool
    {
        return (int) $student->entry_year === 2026
            && (int) $semester->academicYear?->start_date?->year === 2026;
    }

    public function assertPeriodOpen(Student $student, Semester $semester): void
    {
        $eligibility = $this->evaluate($student, $semester);
        if (! $eligibility['period']['is_open']) {
            throw new DomainException($eligibility['period']['message']);
        }
    }

    public function assertFinanciallyClear(Student $student, Semester $semester): void
    {
        if ($this->isKrsPaymentExemptFreshman($student, $semester)) {
            return;
        }

        $eligibility = $this->evaluate($student, $semester);
        if (! $eligibility['finance']['is_clear']) {
            throw new DomainException($eligibility['finance']['message']);
        }
    }
}
