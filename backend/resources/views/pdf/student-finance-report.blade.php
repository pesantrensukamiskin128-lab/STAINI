<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 9px; line-height: 1.3; }
        h1 { font-size: 13px; margin: 6px 0 2px; text-align: center; }
        p { margin: 2px 0; }
        .letterhead img { display: block; width: 100%; height: auto; margin: 0 auto 6px; }
        .institution { text-align: center; border-bottom: 2px solid #1f2937; padding-bottom: 6px; font-size: 14px; font-weight: bold; }
        .muted { color: #6b7280; text-align: center; font-size: 8px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data th, table.data td { border: 1px solid #d1d5db; padding: 4px 5px; }
        table.data th { background: #f3f4f6; text-align: left; font-size: 8px; font-weight: bold; }
        table.data tfoot td { font-weight: bold; background: #f9fafb; }
        .right { text-align: right; }
        .center { text-align: center; }
        .badge-lunas { color: #047857; font-weight: bold; }
        .badge-sebagian { color: #b45309; font-weight: bold; }
        .badge-belum { color: #b91c1c; font-weight: bold; }
        .footer { margin-top: 12px; color: #6b7280; font-size: 8px; }
    </style>
</head>
<body>
    @if($letterheadPath && file_exists($letterheadPath))
        <div class="letterhead"><img src="{{ $letterheadPath }}" alt="Kop Surat"></div>
    @else
        <div class="institution">{{ $institution?->name ?? 'Al-Jawami Smart Campus' }}</div>
    @endif
    <h1>LAPORAN KEUANGAN MAHASISWA</h1>
    <p class="muted"><strong>{{ $report['group_label'] }}</strong></p>
    <p class="muted">{{ $report['filters'] ? implode(' | ', $report['filters']) : 'Semua data' }}</p>

    @php
        $rp = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
        $isStudent = ($report['group_by'] ?? '') === 'student';
    @endphp

    <table class="data">
        <thead>
            @if($isStudent)
                <tr>
                    <th style="width:20px" class="center">No</th>
                    <th style="width:65px">NIM</th>
                    <th>Nama Mahasiswa</th>
                    <th style="width:110px">Program Studi</th>
                    <th style="width:45px" class="center">Kelas</th>
                    <th class="right" style="width:30px">Tagihan</th>
                    <th class="right">Total Tagihan</th>
                    <th class="right">Diskon</th>
                    <th class="right">Beasiswa</th>
                    <th class="right">Dibayar</th>
                    <th class="right">Tunggakan</th>
                    <th class="center" style="width:50px">Status</th>
                </tr>
            @else
                <tr>
                    <th style="width:24px" class="center">No</th>
                    <th>{{ $report['group_label'] }}</th>
                    <th class="right">Mhs</th>
                    <th class="right">Tagihan</th>
                    <th class="right">Total Tagihan</th>
                    <th class="right">Diskon</th>
                    <th class="right">Beasiswa</th>
                    <th class="right">Dibayar</th>
                    <th class="right">Tunggakan</th>
                </tr>
            @endif
        </thead>
        <tbody>
            @forelse($report['rows'] as $index => $r)
                @if($isStudent)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td>{{ $r['nim'] }}</td>
                        <td><strong>{{ $r['name'] }}</strong></td>
                        <td>{{ $r['prodi'] }}</td>
                        <td class="center">{{ $r['class_type'] }}</td>
                        <td class="right">{{ $r['invoices'] }}</td>
                        <td class="right">{{ $rp($r['invoiced']) }}</td>
                        <td class="right">{{ $rp($r['discount']) }}</td>
                        <td class="right">{{ $rp($r['scholarship']) }}</td>
                        <td class="right">{{ $rp($r['paid']) }}</td>
                        <td class="right">{{ $rp($r['outstanding']) }}</td>
                        <td class="center">
                            @if(($r['status'] ?? '') === 'LUNAS')
                                <span class="badge-lunas">LUNAS</span>
                            @elseif(($r['status'] ?? '') === 'SEBAGIAN')
                                <span class="badge-sebagian">SEBAGIAN</span>
                            @else
                                <span class="badge-belum">BELUM</span>
                            @endif
                        </td>
                    </tr>
                @else
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td><strong>{{ $r['label'] }}</strong></td>
                        <td class="right">{{ $r['students'] }}</td>
                        <td class="right">{{ $r['invoices'] }}</td>
                        <td class="right">{{ $rp($r['invoiced']) }}</td>
                        <td class="right">{{ $rp($r['discount']) }}</td>
                        <td class="right">{{ $rp($r['scholarship']) }}</td>
                        <td class="right">{{ $rp($r['paid']) }}</td>
                        <td class="right">{{ $rp($r['outstanding']) }}</td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ $isStudent ? 12 : 9 }}" class="center" style="padding:15px; color:#9ca3af;">Tidak ada data yang sesuai dengan filter.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            @if($isStudent)
                <tr>
                    <td colspan="5">TOTAL ({{ count($report['rows']) }} Mahasiswa)</td>
                    <td class="right">{{ $report['totals']['invoices'] }}</td>
                    <td class="right">{{ $rp($report['totals']['invoiced']) }}</td>
                    <td class="right">{{ $rp($report['totals']['discount']) }}</td>
                    <td class="right">{{ $rp($report['totals']['scholarship']) }}</td>
                    <td class="right">{{ $rp($report['totals']['paid']) }}</td>
                    <td class="right">{{ $rp($report['totals']['outstanding']) }}</td>
                    <td></td>
                </tr>
            @else
                <tr>
                    <td colspan="2">TOTAL</td>
                    <td class="right">{{ $report['totals']['students'] }}</td>
                    <td class="right">{{ $report['totals']['invoices'] }}</td>
                    <td class="right">{{ $rp($report['totals']['invoiced']) }}</td>
                    <td class="right">{{ $rp($report['totals']['discount']) }}</td>
                    <td class="right">{{ $rp($report['totals']['scholarship']) }}</td>
                    <td class="right">{{ $rp($report['totals']['paid']) }}</td>
                    <td class="right">{{ $rp($report['totals']['outstanding']) }}</td>
                </tr>
            @endif
        </tfoot>
    </table>
    <p class="footer">Dicetak pada {{ now()->format('d/m/Y H:i') }} WIB | Sistem Informasi Akademik Al-Jawami</p>
</body>
</html>
