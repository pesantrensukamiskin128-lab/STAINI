<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ExitedStudentInvoiceService
{
    public const EXIT_STATUSES = ['Lulus', 'DO'];

    public const LEAVE_FEE_CODE = 'CUTI';

    /** Tagihan belum lunas dan belum ada pembayaran sama sekali milik mahasiswa Lulus/DO. */
    public function cancellableQuery(?int $studentId = null): Builder
    {
        return Invoice::query()
            ->whereIn('status', ['UNPAID', 'OVERDUE'])
            ->where('paid_amount', '<=', 0)
            ->whereDoesntHave('payments')
            ->whereHas('student', fn ($query) => $query->whereIn('status', self::EXIT_STATUSES))
            ->when($studentId, fn ($query) => $query->where('student_id', $studentId));
    }

    public function cancel(?int $studentId = null): int
    {
        return $this->cancellableQuery($studentId)->update([
            'status' => 'CANCELLED',
            'note' => DB::raw("CONCAT(COALESCE(note, ''), IF(note IS NULL OR note = '', '', ' | '), 'Dibatalkan otomatis: mahasiswa Lulus/DO')"),
        ]);
    }
}
