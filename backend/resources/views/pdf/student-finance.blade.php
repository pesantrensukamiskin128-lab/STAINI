<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10px; }
        h1 { font-size: 17px; margin: 0 0 3px; }
        h2 { font-size: 12px; margin: 18px 0 7px; border-bottom: 1px solid #9ca3af; padding-bottom: 4px; }
        p { margin: 2px 0; }
        .letterhead { width: 100%; margin: 0 0 8px; text-align: center; }
        .letterhead img { display: block; width: 100%; max-width: 100%; height: auto; object-fit: contain; margin: 0 auto; }
        .institution { text-align: center; border-bottom: 2px solid #1f2937; padding-bottom: 8px; }
        .muted { color: #6b7280; }
        .student { margin-top: 14px; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td { padding: 3px 0; vertical-align: top; }
        .summary { margin-top: 12px; }
        .summary td { width: 25%; border: 1px solid #d1d5db; padding: 7px; }
        .label { display: block; color: #6b7280; font-size: 8px; }
        .value { display: block; font-weight: bold; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #d1d5db; padding: 5px; }
        table.data th { background: #f3f4f6; text-align: left; font-size: 8px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .status { font-size: 8px; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 8px; }
    </style>
</head>
<body>
    @if($letterheadPath && file_exists($letterheadPath))
        <div class="letterhead">
            <img src="{{ $letterheadPath }}" alt="Kop Surat">
        </div>
    @else
        <div class="institution">
            <h1>{{ $institution?->name ?? 'Al-Jawami Smart Campus' }}</h1>
            <p class="muted">Laporan Keuangan Mahasiswa</p>
        </div>
    @endif
    <h1 style="text-align:center; font-size:14px; margin:8px 0 0;">LAPORAN KEUANGAN MAHASISWA</h1>

    <div class="student">
        <table class="grid">
            <tr>
                <td><span class="muted">Nama</span><br><strong>{{ $student->name }}</strong></td>
                <td><span class="muted">NIM</span><br><strong>{{ $student->nim }}</strong></td>
                <td><span class="muted">Program Studi</span><br>{{ $student->studyProgram?->name ?? '-' }}</td>
            </tr>
            <tr>
                <td><span class="muted">Status</span><br>{{ $student->status ?? '-' }}</td>
                <td><span class="muted">Tahun Masuk</span><br>{{ $student->entry_year ?? '-' }}</td>
                <td><span class="muted">Dicetak</span><br>{{ now()->format('d/m/Y H:i') }}</td>
            </tr>
        </table>
    </div>

    <table class="grid summary">
        <tr>
            <td><span class="label">Total Tagihan</span><span class="value">Rp {{ number_format($summary['totalInvoiced'], 0, ',', '.') }}</span></td>
            <td><span class="label">Total Dibayar</span><span class="value">Rp {{ number_format($summary['totalPaid'], 0, ',', '.') }}</span></td>
            <td><span class="label">Diskon/Beasiswa</span><span class="value">Rp {{ number_format($summary['totalDiscount'] + $summary['totalScholarship'], 0, ',', '.') }}</span></td>
            <td><span class="label">Sisa Tagihan</span><span class="value">Rp {{ number_format($summary['outstanding'], 0, ',', '.') }}</span></td>
        </tr>
    </table>

    <h2>Daftar Tagihan</h2>
    <table class="data">
        <thead><tr><th>No. Invoice</th><th>Semester</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="right">Total</th><th class="right">Dibayar</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($invoices as $invoice)
            <tr>
                <td>{{ $invoice->invoice_number }}</td>
                <td>{{ $invoice->semester?->name ?? '-' }}</td>
                <td>{{ $invoice->invoice_date?->format('d/m/Y') ?? '-' }}</td>
                <td>{{ $invoice->due_date?->format('d/m/Y') ?? '-' }}</td>
                <td class="right">Rp {{ number_format($invoice->total_amount, 0, ',', '.') }}</td>
                <td class="right">Rp {{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
                <td class="status">{{ $invoice->status }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="center muted">Belum ada tagihan.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Riwayat Pembayaran</h2>
    <table class="data">
        <thead><tr><th>No. Pembayaran</th><th>No. Invoice</th><th>Tanggal</th><th>Metode</th><th class="right">Jumlah</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($payments as $payment)
            <tr>
                <td>{{ $payment->payment_number }}</td>
                <td>{{ $payment->invoice?->invoice_number ?? '-' }}</td>
                <td>{{ $payment->payment_date?->format('d/m/Y') ?? '-' }}</td>
                <td>{{ $payment->payment_method }}</td>
                <td class="right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                <td class="status">{{ $payment->status }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center muted">Belum ada riwayat pembayaran.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="footer">Dokumen ini dibuat oleh sistem dan mencakup seluruh data tagihan serta pembayaran yang tercatat untuk mahasiswa tersebut.</p>
</body>
</html>
