<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Presensi Kelas - {{ $class->course?->name }}</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: 'Times New Roman', serif; font-size: 10pt; }
        h1, h2 { text-align: center; margin: 0; }
        h1 { font-size: 12pt; }
        h2 { font-size: 16pt; margin: 5px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        .identity { margin-bottom: 10px; }
        .identity td { padding: 2px 4px; vertical-align: top; }
        .list { table-layout: fixed; }
        .list th, .list td { border: 1px solid #000; padding: 3px 2px; font-size: 10pt; vertical-align: middle; }
        .list th { text-align: center; background: #e5e7eb; white-space: pre-line; }
        .center { text-align: center; }
        .signature { margin-top: 18px; page-break-inside: avoid; }
        .signature td { width: 33.33%; text-align: center; vertical-align: top; padding: 0 12px; }
        .signature img { width: 62px; height: 62px; }
        .signature .name { display: block; margin-top: 4px; font-weight: bold; text-decoration: underline; }
        .verification { margin-top: 14px; page-break-inside: avoid; border-top: 1px solid #999; padding-top: 7px; }
        .verification td { vertical-align: middle; }
        .verification img { width: 60px; height: 60px; }
        .verification .label { font-size: 9pt; color: #444; }
        .verification .url { font-size: 7pt; word-break: break-all; color: #555; }
    </style>
</head>
<body>
    <h1>{{ $institution?->name ?? '' }}</h1>
    <h2>PRESENSI TATAP MUKA PERKULIAHAN</h2>
    <table class="identity">
        <tr>
            <td style="width: 12%;"><strong>Mata Kuliah</strong></td>
            <td style="width: 38%;">{{ $class->course?->code }} - {{ $class->course?->name }} ({{ $class->course?->credits ?? '-' }} SKS)</td>
            <td style="width: 12%;"><strong>Semester</strong></td>
            <td style="width: 38%;">{{ $class->semester?->name ?? '-' }} / {{ $class->semester?->academicYear?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Fakultas</strong></td>
            <td>{{ $class->studyProgram?->faculty?->name ?? '-' }}</td>
            <td><strong>Program Studi</strong></td>
            <td>{{ $class->studyProgram?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Kelas</strong></td>
            <td>{{ $class->name ?? '-' }}</td>
            <td><strong>Dosen Pengampu</strong></td>
            <td>{{ $lecturerName }}</td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th style="width:3%;">No</th>
                <th style="width:10%;">NPM</th>
                <th style="width:17%;">Nama Mahasiswa</th>
                @foreach($headers as $index => $header)
                    @if($index >= 3 && $index < 19)
                        <th style="width:2.5%;">{{ $header }}</th>
                    @endif
                @endforeach
                <th style="width:7%;">Sakit</th>
                <th style="width:7%;">Izin</th>
                <th style="width:7%;">Alpa</th>
                <th style="width:9%;">Hadir (%)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($attendanceRows as $row)
                <tr>
                    @foreach($row as $index => $value)
                        <td class="{{ $index === 0 || ($index >= 3 && $index <= 22) ? 'center' : '' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="23" class="center">Belum ada peserta kelas.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p style="font-size: 8pt; margin: 5px 0 0;">H = Hadir, S = Sakit, I = Izin, A = Alpa. Persentase hadir dihitung dari jumlah pertemuan berstatus selesai. Presensi yang belum tercatat dibiarkan kosong dan tidak otomatis dinyatakan alpa.</p>

    <table class="signature">
        <tr>
            <td>
                Ketua Program Studi,
                <div><img src="{{ $qrHeadLecturer }}" alt="QR tanda tangan Ketua Program Studi"></div>
                <span class="name">{{ $headLecturerName }}</span>
                <span>NIDN {{ $class->studyProgram?->headLecturer?->nidn ?? '-' }}</span>
            </td>
            <td></td>
            <td>
                Dosen Pengampu,
                <div><img src="{{ $qrLecturer }}" alt="QR tanda tangan Dosen Pengampu"></div>
                <span class="name">{{ $lecturerName }}</span>
                <span>NIDN {{ $class->lecturer?->nidn ?? '-' }}</span>
            </td>
        </tr>
    </table>

    <table class="verification">
        <tr>
            <td style="width: 70px;"><img src="{{ $qrVerification }}" alt="QR verifikasi"></td>
            <td>
                <div><strong>Verifikasi dokumen</strong></div>
                <div class="label">Scan QR untuk memeriksa data kelas dan identitas dokumen.</div>
                <div class="url">{{ $verifyUrl }}</div>
                <div class="label">Dicetak {{ now()->translatedFormat('d F Y, H:i') }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
