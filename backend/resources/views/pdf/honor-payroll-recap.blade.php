<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Rincian Honor {{ $payroll->period_key }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
    h1 { font-size: 14px; margin: 0; }
    .meta { margin: 4px 0 12px; color: #444; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 4px 6px; vertical-align: top; }
    th { background: #eee; text-align: left; }
    .r { text-align: right; }
    tfoot td { font-weight: bold; background: #f5f5f5; }
</style>
</head>
<body>
@php
    $fmt = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $title = $payroll->period_type === 'SEMESTER' ? ($payroll->semester?->name ?? $payroll->period_key) : $payroll->period_key;
@endphp
<h1>{{ $institution?->name ?? 'Rincian Honor' }}</h1>
<div class="meta">
    Rincian Honor Dosen dan Tenaga Kependidikan — {{ $title }}<br>
    Periode: {{ $payroll->period_start?->format('d/m/Y') }} – {{ $payroll->period_end?->format('d/m/Y') }} · Status: {{ $payroll->status }}
    @if($filtered)<br><em>Hasil pencarian: hanya sebagian penerima yang ditampilkan.</em>@endif
</div>
<table>
    <thead>
    <tr>
        <th style="width:4%">No</th>
        <th style="width:20%">Penerima</th>
        <th>Aktivitas</th>
        <th style="width:22%">Jumlah × Tarif</th>
        <th style="width:11%" class="r">Honor</th>
        <th style="width:10%" class="r">Potongan</th>
        <th style="width:11%" class="r">Bersih</th>
    </tr>
    </thead>
    <tbody>
    @forelse($groups as $i => $group)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $group['name'] }}@if($group['identifier'])<br>{{ $group['identifier'] }}@endif</td>
            <td>@foreach($group['lines'] as $line){{ $line['label'] }}<br>@endforeach</td>
            <td>@foreach($group['lines'] as $line){{ rtrim(rtrim(number_format($line['quantity'], 2, ',', '.'), '0'), ',') }} × {{ $fmt($line['rate']) }} = {{ $fmt($line['amount']) }}<br>@endforeach</td>
            <td class="r">{{ $fmt($group['gross']) }}</td>
            <td class="r">{{ $fmt($group['deduction']) }}</td>
            <td class="r">{{ $fmt($group['gross'] - $group['deduction']) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" style="text-align:center">Tidak ada rincian honor.</td></tr>
    @endforelse
    </tbody>
    @if(count($groups))
    <tfoot>
    <tr>
        <td colspan="4">Jumlah keseluruhan</td>
        <td class="r">{{ $fmt($grossTotal) }}</td>
        <td class="r">{{ $fmt($deductionTotal) }}</td>
        <td class="r">{{ $fmt($grossTotal - $deductionTotal) }}</td>
    </tr>
    </tfoot>
    @endif
</table>
<p style="margin-top:10px;color:#666">Dicetak {{ $printedAt->format('d/m/Y H:i') }}</p>
</body>
</html>
