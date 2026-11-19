<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat - {{ $letter->letter_number ?? 'Draft' }}</title>
    @php
        $lhOk = $letterheadUrl && file_exists($letterheadUrl);
        $lhSize = $lhOk ? @getimagesize($letterheadUrl) : false;
        $lhHeightMm = ($lhSize && $lhSize[0] > 0) ? round(190 * $lhSize[1] / $lhSize[0], 2) : 0;
        $pageTopMm = $lhHeightMm > 0 ? 5 + $lhHeightMm + 3 : 5;
    @endphp
    <style>
        @page { margin: {{ $pageTopMm }}mm 10mm 12mm 10mm; }
        .letterhead-fixed { position: fixed; top: -{{ $lhHeightMm + 3 }}mm; left: 0; right: 0; text-align: center; }
        .letterhead-fixed img { width: 100%; }
    </style>
    <style>
        @php \App\Support\ArabicPdfText::ensureFontDirectory(); @endphp
        @font-face { font-family: 'Amiri'; font-style: normal; font-weight: normal;
        @font-face { font-family: 'Amiri'; font-style: normal; font-weight: normal; src: url('{{ str_replace('\\', '/', resource_path('fonts/Amiri-Regular.ttf')) }}') format('truetype'); }
        @font-face { font-family: 'Amiri'; font-style: normal; font-weight: bold; src: url('{{ str_replace('\\', '/', resource_path('fonts/Amiri-Bold.ttf')) }}') format('truetype'); }
        .ar { font-family: 'Amiri', serif; font-size: 1.25em; line-height: 1.3; }
        body { font-family: 'Times New Roman', serif; font-size: 12pt; margin: 0; padding: 0; line-height: 1.15; }
        .letterhead { text-align: center; margin-bottom: 3px; }
        .letterhead img { width: 100%; }
        .letter-title { text-align: center; margin-top: 14px; margin-bottom: 14px; padding: 0 40px; }
        .letter-title p { margin: 0; }
        .letter-title .title { font-weight: bold; text-decoration: underline; font-size: 13pt; }
        .letter-title .number { margin-top: 2px; }
        .decree-title { text-align: center; margin-top: 12px; margin-bottom: 12px; padding: 0 40px; }
        .decree-title p { margin: 0; font-weight: bold; }
        .decree-title .main { font-size: 14pt; }
        .decree-title .about { margin-top: 12px; }
        .decree-body table { width: 100%; border-collapse: collapse; margin: 0 0 6pt 0; }
        .decree-body table td { border: none; padding: 0 0 4pt 0; vertical-align: top; font-size: 12pt; }
        .decree-body table td p { margin: 0 0 3pt 0; }
        .decree-body ol { padding-left: 22px; margin: 0; }
        .decree-sign td { border: none; padding: 0; font-size: 12pt; vertical-align: top; }
        .is-decree, .is-decree .letter-body, .is-decree .decree-title p, .is-decree .decree-title .main,
        .is-decree .decree-body table td, .is-decree .letter-body table td, .is-decree .letter-body table th,
        .is-decree .decree-sign td, .is-decree .sign-area p, .is-decree .sign-area .nidn { font-size: 11pt; }
        .is-decree .ar { font-size: 14pt; }
        .decree-body table.appendix-head { width: auto; font-style: italic; margin: 0 0 14pt 0; }
        .decree-body table.appendix-head td { padding: 0; border: none; font-size: 11pt; }
        .decree-body table.appendix-head td:first-child { width: 62px; }
        .decree-body table.appendix-head td.colon { width: 10px; }
        .decree-sign { border-collapse: collapse; border-spacing: 0; margin: 0; }
        .decree-sign td { white-space: nowrap; }
        .letter-info { margin-top: 8px; font-size: 12pt; line-height: 1.15; padding: 0 40px; margin-bottom: 16px; }
        .letter-info table { border: none; width: auto; }
        .letter-info td { padding: 0; vertical-align: top; border: none; line-height: 1.15; }
        .letter-info .label { width: 75px; }
        .recipient-block { margin-top: 8px; font-size: 12pt; line-height: 1.15; padding: 0 40px; margin-bottom: 20px; }
        .recipient-block p { margin: 0; }
        .letter-body { margin-top: 8px; text-align: justify; font-size: 12pt; line-height: 1.15; padding: 0 40px; }
        .letter-body p { margin: 0 0 6pt 0; }
        .letter-body table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .letter-body table td, .letter-body table th { border: 1px solid #333; padding: 2px 5px; font-size: 11pt; line-height: 1.15; }
        .letter-body table p { margin: 0; }
        .letter-body table[data-borderless="true"] td,
        .letter-body table[data-borderless="true"] th { border: none; }
        .letter-body ul { list-style-type: disc; padding-left: 20px; margin: 2px 0; }
        .letter-body ol { list-style-type: decimal; padding-left: 20px; margin: 2px 0; }
        .letter-body li { margin: 0; }
        .sign-wrapper { margin-top: 15px; padding: 0 40px; }
        .sign-clear { clear: both; height: 0; font-size: 0; line-height: 0; }
        .sign-area { float: right; margin-right: 0; width: 240px; text-align: left; }
        .sign-area p { margin: 0; font-size: 11pt; line-height: 1.2; }
        .sign-area .qr { margin: 3px 0; }
        .sign-area .name { font-weight: bold; text-decoration: underline; margin-top: 2px; }
        .sign-area .nidn { font-size: 10pt; }
        .verify-footer { position: absolute; bottom: 0; left: 0; right: 0; padding: 0 40px 0 40px; }
        .verify-footer table { width: 100%; border-collapse: collapse; }
        .verify-footer td { border: none; padding: 0; vertical-align: middle; }
        .cc-block { padding: 0 40px; margin-top: 10px; font-size: 11pt; font-family: 'Times New Roman', serif; }
        .cc-block p { margin: 0 0 2px 0; }
        .cc-block table { border-collapse: collapse; }
        .cc-block td { border: none; padding: 0 0 1px 0; vertical-align: top; font-size: 11pt; }
        .cc-block td.no { width: 20px; }
        .verify-footer { padding-bottom: 0; font-family: 'Times New Roman', serif; }
        .verify-footer td { vertical-align: top; }
        .verify-footer img { display: block; }
        .verify-text { font-size: 10pt; color: #555; line-height: 1.2; padding-left: 6px; }
        .watermark { position: fixed; top: 40%; left: 10%; transform: rotate(-35deg); font-size: 80pt; color: rgba(200,200,200,0.25); font-weight: bold; z-index: -1; letter-spacing: 10px; }
    </style>
</head>
<body class="{{ preg_match('/\bkeputusan\b/i', (string) ($letter->letterType?->name ?? '')) || strtoupper((string) ($letter->letterType?->code ?? '')) === 'SK' ? 'is-decree' : '' }}">
    @if(!$isFinal)
    <div class="watermark">DRAFT</div>
    @endif

    {{-- Kop Surat (diulang di setiap halaman) --}}
    @if($lhOk)
        <div class="letterhead-fixed">
            <img src="{{ $letterheadUrl }}" alt="Kop Surat">
        </div>
    @endif

    @php
        $typeName = trim((string) ($letter->letterType?->name ?? ''));
        $isDeclaration = (bool) preg_match('/\b(keterangan|rekomendasi|tugas|pengumuman)\b/i', $typeName);
        $typeTitle = trim(preg_replace('/\s+eksternal$/i', '', $typeName));
        $isDecree = !$isDeclaration && (
            strtoupper((string) ($letter->letterType?->code ?? '')) === 'SK'
            || (bool) preg_match('/\bkeputusan\b/i', $typeName)
        );
        $decreeOfficial = mb_strtoupper(trim(($signerPosition ?? '') . ' ' . ($institution?->name ?? '')));
    @endphp

    @if($isDecree)
    {{-- Judul Surat Keputusan --}}
    <div class="decree-title">
        <p class="main">SURAT KEPUTUSAN</p>
        @if($decreeOfficial !== '')
        <p>{{ $decreeOfficial }}</p>
        @endif
        @if($letter->letter_number)
        <p>Nomor : {{ $letter->letter_number }}</p>
        @endif
        @if($letter->subject)
        <p class="about">TENTANG</p>
        <p>{!! nl2br(\App\Support\ArabicPdfText::text(mb_strtoupper($letter->subject))) !!}</p>
        @endif
    </div>
    @elseif($isDeclaration)
    {{-- Judul jenis surat (Keterangan/Rekomendasi/Tugas/Pengumuman) --}}
    <div class="letter-title">
        <p class="title">{{ mb_strtoupper($typeTitle) }}</p>
        @if($letter->letter_number)
        <p class="number">Nomor: {{ $letter->letter_number }}</p>
        @endif
    </div>
    @else
    {{-- Info Surat --}}
    <div class="letter-info">
        <table>
            <tr><td class="label">Nomor</td><td>: {{ $letter->letter_number ?? '-' }}</td></tr>
            <tr><td class="label">Lampiran</td><td>: {{ $letter->attachment_note ?? '-' }}</td></tr>
            <tr><td class="label">Perihal</td><td>: <em>{!! nl2br(\App\Support\ArabicPdfText::text($letter->subject)) !!}</em></td></tr>
        </table>
    </div>

    {{-- Tujuan --}}
    <div class="recipient-block">
        <p>Kepada Yang Terhormat,</p>
        <p><strong>{!! nl2br(\App\Support\ArabicPdfText::text($letter->recipient)) !!}</strong></p>
        <p>di-</p>
        <p style="padding-left: 20px;">Tempat</p>
    </div>
    @endif

    {{-- Isi Surat --}}
    <div class="letter-body{{ $isDecree ? ' decree-body' : '' }}">
        {!! \App\Support\ArabicPdfText::html($letter->body) !!}
    </div>

    {{-- Tanda Tangan --}}
    @include('pdf.partials.outgoing-sign')

    @php
        $ccLines = collect(preg_split('/\r\n|\r|\n/', (string) $letter->carbon_copy))
            ->map(fn ($l) => trim(preg_replace('/^\s*\d+[\.\)]\s*/', '', $l)))
            ->filter()->values();
    @endphp
    @if($ccLines->isNotEmpty())
    <div class="cc-block">
        <p>Tembusan:</p>
        <table>
            @foreach($ccLines as $i => $cc)
            <tr><td class="no">{{ $i + 1 }}.</td><td>{!! \App\Support\ArabicPdfText::text($cc) !!}</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- Footer Verifikasi --}}
    @if($isFinal)
    <div class="verify-footer">
        <table>
            <tr>
                <td style="width: 60px;">
                    <a href="{{ $verifyUrl }}"><img src="{{ $qrFooter }}" width="60" height="60" alt="QR Verifikasi"></a>
                </td>
                <td>
                    <div class="verify-text">
                        Dokumen ini ditandatangani dan distempel secara elektronik melalui aplikasi Nurul Iman Academic Integrated System. Untuk verifikasi, kunjungi <a href="{{ $verifyUrl }}" style="color: #1d4ed8; text-decoration: underline;">{{ preg_replace('#/[^/]+$#', '', $verifyUrl) }}</a> atau scan QR Code di samping.
                    </div>
                </td>
            </tr>
        </table>
    </div>
    @endif

    {{-- Lampiran isi surat (setelah tanda tangan + QR verifikasi) --}}
    @if($letter->appendix_body)
    <div style="page-break-before: always;"></div>
    <div class="letter-body{{ $isDecree ? ' decree-body' : '' }}" style="margin-top: 8px;">
        @if($isDecree)
        <table class="appendix-head">
            <tr><td>Lampiran</td><td class="colon"></td><td>SK {{ $signerPosition }} {{ $institution?->name }}</td></tr>
            <tr><td>Nomor</td><td class="colon">:</td><td>{{ $letter->letter_number }}</td></tr>
            <tr><td>Tentang</td><td class="colon">:</td><td>{!! nl2br(\App\Support\ArabicPdfText::text($letter->subject)) !!}</td></tr>
        </table>
        @else
        <p style="text-align: center; font-weight: bold; margin-bottom: 10px;">LAMPIRAN</p>
        @endif
        {!! \App\Support\ArabicPdfText::html($letter->appendix_body) !!}
    </div>
    @if($isDecree)
    @include('pdf.partials.outgoing-sign')
    @endif
    @endif

    {{-- Dokumen pendukung sebagai lampiran --}}
    @if(!empty($attachmentFiles))
    <div style="page-break-before: always;"></div>
    <div style="padding: 0 40px; font-size: 11pt; margin-top: 8px;">
        <p style="text-align: center; font-weight: bold; margin-bottom: 12px;">LAMPIRAN DOKUMEN PENDUKUNG</p>
        <ol style="line-height: 1.4;">
            @foreach($attachmentFiles as $file)
            <li style="margin-bottom: 4px;">
                {{ $file['name'] }}
                <span style="color: #666; font-size: 10pt;">({{ $file['size_label'] }})</span>
            </li>
            @endforeach
        </ol>
        <p style="font-size: 10pt; color: #666; margin-top: 12px;">
            Dokumen di atas merupakan bagian yang tidak terpisahkan dari surat ini.
            File asli dapat diakses melalui aplikasi Nurul Iman Academic Integrated System.
        </p>
    </div>
    @endif
</body>
</html>
