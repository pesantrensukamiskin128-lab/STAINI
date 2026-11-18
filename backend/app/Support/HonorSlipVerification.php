<?php

namespace App\Support;

use App\Models\HonorPayroll;
use App\Models\HonorPayrollDeduction;
use Illuminate\Support\Collection;

class HonorSlipVerification
{
    public static function reference(
        HonorPayroll $payroll,
        string $recipientType,
        int $recipientId,
        string $recipientName,
        ?string $recipientIdentifier,
        Collection $items,
        int $financeSignerId = 0,
        ?string $financeSignerName = null,
        int $waket2SignerId = 0,
        ?string $waket2SignerName = null,
        ?Collection $deductions = null,
    ): string {
        $snapshot = $items
            ->sortBy(fn ($item): string => $item->activity_date->format('Y-m-d').'-'.str_pad((string) $item->id, 12, '0', STR_PAD_LEFT))
            ->map(fn ($item): array => [
                'id' => (int) $item->id,
                'source_key' => (string) $item->source_key,
                'description' => (string) $item->description,
                'activity_date' => $item->activity_date->format('Y-m-d'),
                'quantity' => (string) $item->quantity,
                'unit_rate_snapshot' => (int) $item->unit_rate_snapshot,
                'amount_snapshot' => (int) $item->amount_snapshot,
            ])
            ->values()
            ->all();
        $deductionSnapshot = ($deductions ?? collect())
            ->sortBy('id')
            ->map(fn (HonorPayrollDeduction $deduction): array => [
                'id' => (int) $deduction->id,
                'deduction_type' => (string) $deduction->deduction_type,
                'description' => (string) $deduction->description,
                'amount' => (int) $deduction->amount,
            ])
            ->values()
            ->all();

        return implode('|', [
            $recipientType,
            $recipientId,
            $recipientName,
            $recipientIdentifier ?? '',
            $payroll->period_type,
            $payroll->period_key,
            $payroll->period_start->format('Y-m-d'),
            $payroll->period_end->format('Y-m-d'),
            $payroll->paid_at?->toIso8601String() ?? '',
            $payroll->payment_reference ?? '',
            $financeSignerId,
            $financeSignerName ?? '',
            $waket2SignerId,
            $waket2SignerName ?? '',
            hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($deductionSnapshot, JSON_THROW_ON_ERROR)),
        ]);
    }

    public static function number(HonorPayroll $payroll, string $recipientType, int $recipientId): string
    {
        $recipientCode = $recipientType === 'lecturer' ? 'D' : 'T';

        return sprintf('SLIP-HONOR-%d-%s-%d', $payroll->id, $recipientCode, $recipientId);
    }
}
