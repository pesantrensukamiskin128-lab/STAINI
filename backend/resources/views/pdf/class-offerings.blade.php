<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pembagian Kelas dan Jadwal</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #111827; }
        h1 { margin: 0 0 4px; text-align: center; font-size: 15pt; }
        .subtitle { margin: 0 0 14px; text-align: center; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #374151; padding: 5px 4px; font-size: 10pt; vertical-align: middle; }
        th { background: #e5e7eb; text-align: center; }
        .center { text-align: center; }
        .multiline { white-space: pre-line; }
        .footer { margin-top: 8px; text-align: right; color: #4b5563; font-size: 9pt; }
    </style>
</head>
<body>
    <h1>PEMBAGIAN KELAS DAN JADWAL MATA KULIAH</h1>
    <p class="subtitle">{{ $semesterName }}</p>
    <table>
        <thead>
            <tr>
                <th style="width:4%;">No</th>
                <th style="width:9%;">Kode MK</th>
                <th style="width:20%;">Nama MK</th>
                <th style="width:16%;">Dosen</th>
                <th style="width:7%;">Hari</th>
                <th style="width:13%;">Waktu</th>
                <th style="width:12%;">Ruang</th>
                <th style="width:10%;">Jumlah Offline</th>
                <th style="width:9%;">Jumlah Online</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="center">{{ $row['number'] }}</td>
                    <td class="multiline">{{ $row['course_codes'] }}</td>
                    <td class="multiline">{{ $row['course_names'] }}</td>
                    <td class="multiline">{{ $row['lecturer'] }}</td>
                    <td class="multiline">{{ $row['days'] }}</td>
                    <td class="multiline">{{ $row['times'] }}</td>
                    <td class="multiline">{{ $row['rooms'] }}</td>
                    <td class="center">{{ $row['offline_count'] }}</td>
                    <td class="center">{{ $row['online_count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="center">Tidak ada kelas mata kuliah pada filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="footer">Jumlah kelas: {{ $rows->count() }} · Dicetak {{ now()->translatedFormat('d F Y, H:i') }}</p>
</body>
</html>
