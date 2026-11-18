<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Jurnal Kegiatan Pembelajaran - {{ $class->course?->name }}</title>
    <style>
        @page { margin: 14mm 12mm 18mm; }
        body { font-family: 'Times New Roman', serif; font-size: 10pt; }
        h1 { text-align: center; font-size: 16pt; margin: 0 0 14px; }
        .info { margin-bottom: 12px; }
        .info td { padding: 2px 6px; vertical-align: top; }
        .info td:first-child { width: 130px; font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; }
        .list th, .list td { border: 1px solid #000; padding: 5px; vertical-align: top; }
        .list th { text-align: center; background: #e5e7eb; }
        .center { text-align: center; }
        .verification { page-break-inside: avoid; margin-top: 16px; border-top: 1px solid #999; padding-top: 8px; }
        .verification td { vertical-align: middle; }
        .verification img { width: 70px; height: 70px; }
        .verification .label { font-size: 9pt; color: #444; }
        .verification .url { font-size: 7pt; word-break: break-all; color: #555; }
    </style>
</head>
<body>
    <h1>JURNAL KEGIATAN PEMBELAJARAN</h1>
    <table class="info">
        <tr><td>Mata Kuliah</td><td>{{ $class->course?->code }} - {{ $class->course?->name }}</td></tr>
        <tr><td>Kelas</td><td>{{ $class->name }}</td></tr>
        <tr><td>Semester</td><td>{{ $class->semester?->name ?? '-' }}</td></tr>
        <tr><td>Dosen Pengampu</td><td>{{ $class->lecturer?->display_name ?? $class->lecturer?->full_name ?? '-' }}</td></tr>
    </table>
    <table class="list">
        <thead>
            <tr><th style="width:5%;">No</th><th style="width:8%;">Pertemuan</th><th style="width:12%;">Tanggal</th><th>Topik/Materi</th><th>Deskripsi</th><th>Kegiatan Pembelajaran</th><th style="width:11%;">Status</th><th style="width:8%;">Hadir</th></tr>
        </thead>
        <tbody>
            @forelse($journals as $journal)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td class="center">{{ $journal->meeting_number }}</td>
                    <td>{{ $journal->meeting_date?->translatedFormat('d F Y') ?? '-' }}</td>
                    <td>{{ $journal->topic }}</td>
                    <td>{{ $journal->description ?: '-' }}</td>
                    <td>{{ $journal->learning_activity ?: '-' }}</td>
                    <td class="center">{{ $journal->status ?: '-' }}</td>
                    <td class="center">{{ $journal->present_count ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center">Belum ada jurnal kegiatan pembelajaran.</td></tr>
            @endforelse
        </tbody>
    </table>
    <table class="verification">
        <tr>
            <td style="width:80px;"><img src="{{ $qrVerification }}" alt="QR Verifikasi"></td>
            <td>
                <div><strong>Verifikasi dokumen</strong></div>
                <div class="label">Scan QR Code untuk memeriksa keaslian jurnal kegiatan pembelajaran.</div>
                <div class="url">{{ $verifyUrl }}</div>
                <div class="label">Dicetak {{ now()->translatedFormat('d F Y, H:i') }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
