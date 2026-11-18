<?php

namespace App\Console\Commands;

use App\Services\ExitedStudentInvoiceService;
use Illuminate\Console\Command;

class CancelExitedStudentInvoices extends Command
{
    protected $signature = 'finance:cancel-exited-invoices {--dry-run : Hanya tampilkan jumlah tanpa mengubah data}';

    protected $description = 'Batalkan tagihan belum lunas dan belum ada pembayaran milik mahasiswa Lulus/DO';

    public function handle(ExitedStudentInvoiceService $service): int
    {
        $query = $service->cancellableQuery();
        $count = (clone $query)->count();
        $amount = (float) (clone $query)->selectRaw('SUM(total_amount - discount_amount - scholarship_amount) as total')->value('total');

        if ($this->option('dry-run')) {
            $this->info("Dry-run: {$count} tagihan (Rp ".number_format($amount, 0, ',', '.').') akan dibatalkan.');

            return self::SUCCESS;
        }

        $this->info($service->cancel().' tagihan mahasiswa Lulus/DO dibatalkan.');

        return self::SUCCESS;
    }
}
