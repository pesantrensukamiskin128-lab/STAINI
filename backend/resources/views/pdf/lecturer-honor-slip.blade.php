<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $slipNumber }}</title>
    <style>
        @page { margin: 5mm 15mm 28mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 9pt; line-height: 1.2; }
        table { width: 100%; border-collapse: collapse; }
        .letterhead { width: 100%; margin: 0 0 5px; padding: 0; text-align: center; line-height: 0; }
        .letterhead img { display: block; width: 100%; height: auto; margin: 0 auto; padding: 0; }
        .fallback-header td { vertical-align: middle; }
        .logo { width: 70px; text-align: center; }
        .logo img { max-width: 58px; max-height: 58px; }
        .institution { text-align: center; font-family: DejaVu Serif, serif; }
        .institution strong { font-size: 14pt; }
        .institution div { font-size: 8pt; }
        .rule { border-top: 2px solid #111; border-bottom: 1px solid #111; height: 2px; margin: 6px 0 12px; }
        h1 { text-align: center; font-size: 14pt; margin: 0 0 3px; letter-spacing: .3px; }
        .meta { margin-bottom: 15px; }
        .meta td { padding: 4px 6px; vertical-align: top; }
        .meta .label { width: 19%; font-weight: bold; white-space: nowrap; }
        .meta .separator { width: 2%; }
        .items { margin-top: 8px; }
        .items th, .items td { border: 1px solid #374151; padding: 6px; }
        .items th { background: #e5e7eb; text-align: center; font-size: 8pt; }
        .number { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .total { font-weight: bold; background: #f3f4f6; }
        .signature-block { page-break-inside: avoid; margin-top: 25px; }
        .signature-dateline { width: 50%; margin: 0 0 8px auto; text-align: center; white-space: nowrap; }
        .signatures { page-break-inside: avoid; }
        .signatures td { width: 50%; text-align: center; vertical-align: top; padding: 0 10px; }
        .signature-qr { height: 67px; padding-top: 3px; }
        .signature-qr img { width: 62px; height: 62px; }
        .name { display: block; font-weight: bold; text-decoration: underline; min-height: 13px; }
        .position { display: block; margin-bottom: 2px; }
        .verify-footer { position: fixed; left: 0; right: 0; bottom: -18mm; border-top: 1px solid #d1d5db; padding-top: 4px; }
        .verify-footer td { border: none; padding: 0; vertical-align: middle; }
        .verify-footer .qr-cell { width: 72px; }
        .verify-footer img { width: 68px; height: 68px; }
        .verify-text { padding-left: 7px; color: #6b7280; font-size: 7pt; line-height: 1.2; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    @if($letterheadPath && file_exists($letterheadPath))
        <div class="letterhead"><img src="{{ $letterheadPath }}" alt="Kop institusi"></div>
    @else
        <table class="fallback-header"><tr>
            <td class="logo">@if($logoPath && file_exists($logoPath))<img src="{{ $logoPath }}" alt="Logo institusi">@endif</td>
            <td class="institution">
                <strong>{{ strtoupper($institution?->name ?? 'PERGURUAN TINGGI') }}</strong>
                <div>{{ $institution?->address }}</div>
                <div>{{ collect([$institution?->phone, $institution?->email, $institution?->website])->filter()->join(' | ') }}</div>
            </td>
            <td style="width:70px"></td>
        </tr></table>
        <div class="rule"></div>
    @endif

    <h1>SLIP HONOR {{ $recipientType === 'staff' ? 'TENAGA KEPENDIDIKAN' : 'DOSEN' }}</h1>

    <table class="meta">
        <tr>
            <td class="label">Nomor Slip</td><td class="separator">:</td><td>{{ $slipNumber }}</td>
            <td class="label">Periode</td><td class="separator">:</td>
            <td>{{ $payroll->period_type === 'MONTHLY' ? $payroll->period_start->locale('id')->translatedFormat('F Y') : ($payroll->period_type === 'INCIDENTAL' ? 'Insidental · '.$payroll->period_start->locale('id')->translatedFormat('d F Y') : ($payroll->semester?->name ?? $payroll->period_key)) }}</td>
        </tr>
        <tr>
            <td class="label">Nama</td><td class="separator">:</td><td>{{ $recipientName }}</td>
            <td class="label">{{ $recipientIdentifierLabel }}</td><td class="separator">:</td><td>{{ $recipientIdentifier ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Status</td><td class="separator">:</td><td>Telah dibayar</td>
            <td class="label">Tanggal bayar</td><td class="separator">:</td><td>{{ $payroll->paid_at?->locale('id')->translatedFormat('d F Y') }}</td>
        </tr>
        @if($payroll->payment_reference)
            <tr><td class="label">Referensi pembayaran</td><td class="separator">:</td><td colspan="4">{{ $payroll->payment_reference }}</td></tr>
        @endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 35px">No.</th>
                <th>Rincian aktivitas</th>
                <th style="width: 75px">Tanggal</th>
                <th style="width: 65px">Jumlah</th>
                <th style="width: 100px">Tarif</th>
                <th style="width: 110px">Honor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $index => $item)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="center">{{ $item->activity_date->format('d-m-Y') }}</td>
                    <td class="number">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="number">Rp {{ number_format($item->unit_rate_snapshot, 0, ',', '.') }}</td>
                    <td class="number">Rp {{ number_format($item->amount_snapshot, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5" class="number">HONOR BRUTO</td>
                <td class="number">Rp {{ number_format($grossTotal, 0, ',', '.') }}</td>
            </tr>
            @foreach($deductions as $deduction)
                <tr>
                    <td colspan="5" class="number">{{ $deduction->deduction_type === 'TAX' ? 'Pajak' : 'Potongan lain' }} — {{ $deduction->description }}</td>
                    <td class="number">− Rp {{ number_format($deduction->amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5" class="number">TOTAL BERSIH DIBAYARKAN</td>
                <td class="number">Rp {{ number_format($total, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="signature-block">
        <div class="signature-dateline">Bandung, {{ $printedAt->locale('id')->translatedFormat('d F Y') }}</div>
        <table class="signatures"><tr>
            <td>
                Mengetahui,<br><span class="position">Wakil Ketua II</span>
                <div class="signature-qr"><a href="{{ $verifyUrl }}?recipient={{ urlencode($recipientKey) }}&amp;finance_signer_id={{ $financeSignerId }}&amp;finance_signer_name={{ urlencode($financeSignerName ?? '') }}&amp;waket2_signer_id={{ $waket2SignerId }}&amp;waket2_signer_name={{ urlencode($waket2Name ?? '') }}&amp;signer=waket2"><img src="{{ $waket2SignatureUrl }}" alt="QR tanda tangan Wakil Ketua II"></a></div>
                <span class="name">{{ $waket2Name ?? '................................................' }}</span>
            </td>
            <td>
                Bagian Keuangan,<br><span class="position">&nbsp;</span>
                <div class="signature-qr"><a href="{{ $verifyUrl }}?recipient={{ urlencode($recipientKey) }}&amp;finance_signer_id={{ $financeSignerId }}&amp;finance_signer_name={{ urlencode($financeSignerName ?? '') }}&amp;waket2_signer_id={{ $waket2SignerId }}&amp;waket2_signer_name={{ urlencode($waket2Name ?? '') }}&amp;signer=finance"><img src="{{ $financeSignatureUrl }}" alt="QR tanda tangan Bagian Keuangan"></a></div>
                <span class="name">{{ $financeSignerName ?? '................................................' }}</span>
            </td>
        </tr></table>
    </div>

    <div class="verify-footer"><table><tr>
        <td class="qr-cell"><a href="{{ $publicVerifyUrl }}"><img src="{{ $qrVerification }}" alt="QR verifikasi slip honor"></a></td>
        <td><div class="verify-text"><strong>Verifikasi dokumen:</strong> pindai atau klik QR Code untuk memeriksa nomor, periode, dan keaslian slip honor melalui Al-Jawami Smart Campus.<br>Dicetak pada {{ $printedAt->format('d/m/Y H:i') }}</div></td>
    </tr></table></div>
</body>
</html>
