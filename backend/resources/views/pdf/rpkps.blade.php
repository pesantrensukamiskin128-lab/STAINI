<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>RPKPS - {{ $rpkps->course?->name }}</title>
    <style>
        @page { margin: 10mm 12mm 20mm 12mm; }
        body { font-family: 'Times New Roman', serif; font-size: 11pt; margin: 0; padding: 0; line-height: 1.5; position: relative; }
        table { width: 100%; border-collapse: collapse; }

        /* Semua border seragam 1px solid */
        .bordered td, .bordered th { border: 1px solid #000; padding: 5px 8px; vertical-align: top; }
        .bordered th { font-weight: bold; text-align: center; }
        .outcome-table { page-break-before: avoid; page-break-inside: auto; }
        .outcome-table thead { display: table-header-group; }
        .outcome-table tr { page-break-inside: avoid; }
        .mapped-cell { background: #d9d9d9; }

        /* Header */
        .header-table { border: 1px solid #000; }
        .header-table td { padding: 10px; vertical-align: middle; border: none; }
        .header-logo { width: 80px; text-align: center; border-right: 1px solid #000 !important; }
        .header-logo img { max-width: 65px; max-height: 65px; }
        .header-text { font-size: 14pt; font-weight: bold; }

        /* Title row */
        .title-row { border: 1px solid #000; border-top: none; }
        .title-row td { text-align: center; padding: 10px; font-size: 14pt; font-weight: bold; }

        /* Auth box */
        .course-info, .auth-table { page-break-after: avoid; }
        .auth-table { page-break-before: avoid; }
        .intro-block { page-break-inside: auto; }
        .intro-block > table { page-break-inside: auto; }
        .intro-block > .header-table, .intro-block > .title-row { page-break-inside: avoid; }
        .auth-box { text-align: center; padding: 3px 5px; line-height: 1.2; }
        .auth-box img { width: 65px; height: 65px; margin: 4px 0; }
        .auth-box .name { font-weight: bold; text-decoration: underline; }
        .auth-box .nidn { font-size: 10pt; }

        .page-break { page-break-before: always; }
        .rubric-page { page-break-before: always; position: relative; padding-bottom: 24mm; }
        .verification-footer {
            position: absolute;
            right: 0;
            bottom: 0;
            left: 0;
            padding: 8px 0 0;
            border-top: 1px solid #ccc;
        }

        /* Weekly table - sedikit lebih kecil karena banyak kolom */
        .weekly-table td, .weekly-table th { border: 1px solid #000; padding: 4px 5px; font-size: 10pt; vertical-align: top; }
        .weekly-table th { text-align: center; font-weight: bold; }

        .ref-list { margin: 0; padding-left: 20px; }
        .ref-list li { margin-bottom: 3px; }

        .footer-info { margin-top: 15px; text-align: center; font-size: 9pt; color: #555; }
    </style>
</head>
<body>

@php
    $isElectronicallyApproved = in_array($rpkps->status, ['DISETUJUI', 'DIKUNCI', 'DIARSIPKAN'], true);
    $practicalCredits = (int) ($rpkps->course?->practical_credits ?? 0);
    $storedTheoryCredits = (int) ($rpkps->course?->theory_credits ?? 0);
    $theoryCredits = $storedTheoryCredits ?: ($practicalCredits ? 0 : (int) ($rpkps->course?->credits ?? 0));
    $semesterLabel = $rpkps->semester?->name
        ?? ($rpkps->course?->semester ? 'Semester '.$rpkps->course->semester : '-');
@endphp

@php
    $subCpmkByCode = collect($rpkps->cpmks ?? [])
        ->flatMap(fn ($cpmk) => $cpmk->subCpmks ?? [])
        ->keyBy(fn ($sub) => strtoupper(trim((string) $sub->code)));
    $subCpmkLabel = function ($value, bool $includeCode = true) use ($subCpmkByCode): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return collect(preg_split('/[|,;\r\n]+/', $value))
            ->map(function ($code) use ($subCpmkByCode, $includeCode): string {
                $code = trim((string) $code);
                $sub = $subCpmkByCode->get(strtoupper($code));
                if (!$sub) {
                    return $code;
                }
                return $includeCode ? $sub->code.' - '.$sub->description : (string) $sub->description;
            })
            ->filter()
            ->implode('; ');
    };
    $rpkpsCpls = collect($rpkps->cpls ?? [])->sortBy('order')->values();
@endphp

<div class="intro-block">

{{-- HEADER --}}
<table class="header-table">
    <tr>
        <td class="header-logo">
            @if($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="Logo">
            @endif
        </td>
        <td class="header-text">
            {{ strtoupper($institution?->name ?? 'PERGURUAN TINGGI') }}<br>
            @if($rpkps->course?->studyProgram?->faculty)
                {{ strtoupper($rpkps->course->studyProgram->faculty->name) }}<br>
            @endif
            PROGRAM STUDI {{ strtoupper($rpkps->course?->studyProgram?->name ?? '') }}
        </td>
    </tr>
</table>
<table class="title-row">
    <tr><td>RENCANA PEMBELAJARAN SEMESTER</td></tr>
</table>

{{-- INFO MK --}}
<table class="bordered course-info" style="border-top:none;">
    <tr>
        <th rowspan="2">Nama Mata Kuliah</th>
        <th rowspan="2">Kode</th>
        <th rowspan="2">Rumpun Mata Kuliah</th>
        <th colspan="3">Bobot (SKS)</th>
        <th rowspan="2">Semester / Tahun Akademik</th>
        <th rowspan="2">Tgl. Penyusunan</th>
    </tr>
    <tr>
        <th style="width:4%;">T</th>
        <th style="width:4%;">P</th>
        <th style="width:5%;">Total</th>
    </tr>
    <tr>
        <td>{{ $rpkps->course?->name }}</td>
        <td style="text-align:center;">{{ $rpkps->course?->code }}</td>
        <td style="text-align:center;">{{ $rpkps->course?->course_group ?: ($rpkps->course?->type ?? '-') }}</td>
        <td style="text-align:center;">{{ $theoryCredits }}</td>
        <td style="text-align:center;">{{ $practicalCredits }}</td>
        <td style="text-align:center;">{{ $rpkps->course?->credits }}</td>
        <td style="text-align:center;">
            {{ $semesterLabel }}<br>
            <span style="font-size:9pt;">{{ $rpkps->academicYear?->name ?? '-' }}</span>
        </td>
        <td style="text-align:center;">{{ $rpkps->created_at?->locale('id')->translatedFormat('d F Y') }}</td>
    </tr>
</table>

{{-- OTORISASI --}}
<table class="bordered auth-table" style="border-top:none;">
    <tr>
        <td style="width:13%; vertical-align:top;"><strong>OTORISASI</strong></td>
        <td class="auth-box">
            <strong>Dosen Pengembang RPS</strong><br>
            @if($isElectronicallyApproved && $qrDosenUrl)
                <img src="{{ $qrDosenUrl }}">
            @else
                <div style="height:20px;"></div>
            @endif
            <div class="name">{{ $rpkps->lecturer?->display_name ?? $rpkps->lecturer?->full_name ?? '-' }}</div>
            <div class="nidn">NIDN: {{ $rpkps->lecturer?->nidn ?? '-' }}</div>
        </td>
        <td class="auth-box">
            <strong>Ketua Program Studi</strong><br>
            @if($isElectronicallyApproved && $qrKaprodiUrl)
                <img src="{{ $qrKaprodiUrl }}">
            @else
                <div style="height:20px;"></div>
            @endif
            <div class="name">{{ $kaprodi?->display_name ?? $kaprodi?->full_name ?? '-' }}</div>
            <div class="nidn">NIDN: {{ $kaprodi?->nidn ?? '-' }}</div>
        </td>
        <td class="auth-box">
            <strong>Lembaga Penjaminan Mutu</strong><br>
            @if($isElectronicallyApproved && $qrLpmUrl)
                <img src="{{ $qrLpmUrl }}">
            @else
                <div style="height:20px;"></div>
            @endif
            <div class="name">{{ $lpm?->display_name ?? $lpm?->full_name ?? '-' }}</div>
            <div class="nidn">NIDN: {{ $lpm?->nidn ?? '-' }}</div>
        </td>
    </tr>
</table>

{{-- CAPAIAN PEMBELAJARAN --}}
<table class="bordered outcome-table">
    <thead>
        <tr>
            <th style="width:15%; text-align:left;">Capaian Pembelajaran (CP)</th>
            <th style="text-align:left; background-color:#d9d9d9;">CPL-PRODI yang dibebankan pada MK</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rpkpsCpls as $cpl)
            <tr>
                <td style="width:15%; vertical-align:middle;"><strong>{{ $cpl->code }}</strong></td>
                <td><strong>{{ $cpl->code }}:</strong> {{ $cpl->description }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2" style="color:#666; font-style:italic;">Belum ada CPL yang dipetakan.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="bordered outcome-table" style="border-top:none;">
    <thead>
        <tr>
            <th style="width:15%; text-align:left;">CPMK</th>
            <th style="text-align:left; background-color:#d9d9d9;">Capaian Pembelajaran Mata Kuliah (CPMK)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rpkps->cpmks ?? [] as $cpmk)
            <tr>
                <td style="width:15%; vertical-align:middle;"><strong>{{ $cpmk->code }}</strong></td>
                <td>{{ $cpmk->description }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2" style="color:#666; font-style:italic;">Belum ada CPMK.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="bordered outcome-table" style="border-top:none;">
    <thead>
        <tr>
            <th style="width:15%; text-align:left;">Sub-CPMK</th>
            <th style="text-align:left; background-color:#d9d9d9;">
                Kemampuan akhir tiap tahapan belajar (Sub-CPMK)
            </th>
        </tr>
    </thead>
    <tbody>
        @php $hasSubCpmk = false; @endphp
        @foreach($rpkps->cpmks ?? [] as $cpmk)
            @foreach($cpmk->subCpmks ?? [] as $subCpmk)
                @php $hasSubCpmk = true; @endphp
                <tr>
                    <td style="width:15%; vertical-align:middle;"><strong>{{ $subCpmk->code }}</strong></td>
                    <td>{{ $subCpmk->description }}</td>
                </tr>
            @endforeach
        @endforeach
        @unless($hasSubCpmk)
            <tr>
                <td colspan="2" style="color:#666; font-style:italic;">Belum ada Sub-CPMK.</td>
            </tr>
        @endunless
    </tbody>
</table>

{{-- MATRIKS KORELASI CPL - CPMK --}}
@if(($rpkps->cpls?->count() ?? 0) && ($rpkps->cpmks?->count() ?? 0))
<table class="bordered" style="border-top:none;">
    <thead>
        <tr>
            <th style="width:22%; text-align:left;">Korelasi CPL terhadap CPMK</th>
            @foreach($rpkps->cpmks as $cpmk)
                <th>{{ $cpmk->code }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($rpkps->cpls as $cpl)
        <tr>
            <td><strong>{{ $cpl->code }}</strong></td>
            @foreach($rpkps->cpmks as $cpmk)
                <td
                    class="{{ $cpmk->cpls->contains('id', $cpl->id) ? 'mapped-cell' : '' }}"
                    style="text-align:center;"
                    aria-label="{{ $cpmk->cpls->contains('id', $cpl->id) ? 'CPL terkait CPMK' : 'Tidak ada korelasi' }}"
                ></td>
            @endforeach
        </tr>
        @endforeach
    </tbody>
</table>
@endif

</div>

{{-- DESKRIPSI --}}
<table class="bordered" style="border-top:none;">
    <tr>
        <td style="width:22%; vertical-align:top;"><strong>Deskripsi Singkat Matakuliah</strong></td>
        <td>{{ $rpkps->course_description ?? '-' }}</td>
    </tr>
</table>

{{-- BAHAN KAJIAN --}}
<table class="bordered" style="border-top:none;">
    <tr>
        <td style="width:22%; vertical-align:top;"><strong>Bahan Kajian</strong></td>
        <td>
            @if($rpkps->learningMaterials && $rpkps->learningMaterials->count())
                <ol style="margin:0; padding-left:20px;">
                    @foreach($rpkps->learningMaterials->sortBy('order') as $mat)
                        <li>{{ $mat->topic }}{{ $mat->subtopics ? ': ' . $mat->subtopics : '' }}</li>
                    @endforeach
                </ol>
            @elseif(trim((string) ($rpkps->course_scope ?? '')) !== '')
                <ol style="margin:0; padding-left:20px;">
                    @foreach(preg_split('/\r\n|\r|\n/', $rpkps->course_scope) as $topic)
                        @if(trim($topic) !== '')
                            <li>{{ trim($topic) }}</li>
                        @endif
                    @endforeach
                </ol>
            @else
                -
            @endif
        </td>
    </tr>
</table>

{{-- PUSTAKA --}}
@if($rpkps->references && $rpkps->references->count())
<table class="bordered" style="border-top:none;">
    <tr>
        <td style="width:22%; vertical-align:top;"><strong>Pustaka</strong></td>
        <td>
            @php $utama = $rpkps->references->where('type', 'Utama'); $pendukung = $rpkps->references->where('type', 'Pendukung'); @endphp
            @if($utama->count())
                <strong>Pustaka Utama</strong>
                <ol class="ref-list">
                    @foreach($utama as $ref)
                        <li>{{ $ref->author ? $ref->author . ', ' : '' }}<em>{{ $ref->title }}</em>{{ $ref->publisher ? ', ' . $ref->publisher : '' }}{{ $ref->year ? ', ' . $ref->year : '' }}</li>
                    @endforeach
                </ol>
            @endif
            @if($pendukung->count())
                <br><strong>Pustaka Pendukung</strong>
                <ol class="ref-list">
                    @foreach($pendukung as $ref)
                        <li>{{ $ref->author ? $ref->author . ', ' : '' }}<em>{{ $ref->title }}</em>{{ $ref->publisher ? ', ' . $ref->publisher : '' }}{{ $ref->year ? ', ' . $ref->year : '' }}</li>
                    @endforeach
                </ol>
            @endif
        </td>
    </tr>
</table>
@endif

{{-- PRASYARAT --}}
<table class="bordered" style="border-top:none;">
    <tr>
        <td style="width:22%;"><strong>Mata Kuliah Prasyarat</strong></td>
        <td>{{ $rpkps->prerequisites ?? '-' }}</td>
    </tr>
</table>

{{-- RENCANA MINGGUAN --}}
@if($rpkps->weeklyPlans && $rpkps->weeklyPlans->count())
<div class="page-break"></div>
<table class="weekly-table">
    <thead>
        <tr>
            <th rowspan="2" style="width:4%;">Mg.</th>
            <th rowspan="2" style="width:17%;">Kemampuan Akhir Tiap Tahapan<br>(Sub-CPMK)</th>
            <th colspan="2" style="width:25%;">Penilaian</th>
            <th colspan="2" style="width:29%;">Bentuk Pembelajaran, Metode, Penugasan, dan Alokasi Waktu</th>
            <th rowspan="2" style="width:18%;">Materi Pembelajaran / Media</th>
            <th rowspan="2" style="width:7%;">Bobot (%)</th>
        </tr>
        <tr>
            <th style="width:12%;">Indikator</th>
            <th style="width:13%;">Kriteria dan Teknik</th>
            <th style="width:16%;">Luring</th>
            <th style="width:13%;">Daring</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rpkps->weeklyPlans->sortBy('week_number') as $w)
        <tr>
            <td style="text-align:center;">{{ $w->week_number }}</td>
            <td>
                @if(in_array((int) $w->week_number, [8, 16], true))
                    {{ (int) $w->week_number === 8 ? 'Evaluasi Tengah Semester' : 'Evaluasi Akhir Semester' }}
                @else
                    {{ $subCpmkLabel($w->sub_cpmk) }}
                @endif
            </td>
            <td>{{ $w->indicators ?? '' }}</td>
            <td>
                @if($w->assessment_criteria)<strong>Kriteria:</strong> {{ $w->assessment_criteria }}<br>@endif
                @if($w->assessment_form)<strong>Teknik/Bentuk:</strong> {{ $w->assessment_form }}@endif
            </td>
            <td>
                @if($w->offline_learning)
                    {{ $w->offline_learning }}
                @else
                    @if($w->methods)<strong>Metode:</strong> {{ is_array($w->methods) ? implode(', ', $w->methods) : $w->methods }}<br>@endif
                    @if($w->lecturer_activity)<strong>Aktivitas dosen:</strong> {{ $w->lecturer_activity }}<br>@endif
                    @if($w->student_activity)<strong>Aktivitas mahasiswa:</strong> {{ $w->student_activity }}@endif
                @endif
                @if($w->duration)<br><strong>Waktu:</strong> {{ $w->duration }}@endif
            </td>
            <td>
                {{ $w->online_learning ?? '' }}
                @if($w->online_learning && $w->duration)<br><strong>Waktu:</strong> {{ $w->duration }}@endif
            </td>
            <td>
                {{ $w->learning_material ?? '' }}
                @if($w->media)<br><strong>Media:</strong> {{ $w->media }}@endif
            </td>
            <td style="text-align:center;">{{ $w->weight ?? '' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- KOMPONEN DAN KONVERSI PENILAIAN --}}
@if(($rpkps->assessments?->count() ?? 0) || ($rpkps->gradeScales?->count() ?? 0))
<div class="page-break"></div>
<table style="width:100%;">
    <tr>
        <td style="width:58%; vertical-align:top; padding-right:12px;">
            <table class="bordered">
                <thead>
                    <tr><th colspan="3">Komponen Penilaian</th></tr>
                    <tr><th>Aspek/Komponen</th><th>Teknik atau Keterangan</th><th style="width:15%;">Bobot (%)</th></tr>
                </thead>
                <tbody>
                    @foreach($rpkps->assessments as $assessment)
                    <tr>
                        <td>{{ $assessment->name }}</td>
                        <td>{{ $assessment->description ?? '-' }}</td>
                        <td style="text-align:center;">{{ $assessment->weight }}</td>
                    </tr>
                    @endforeach
                    <tr>
                        <td colspan="2"><strong>Total</strong></td>
                        <td style="text-align:center;"><strong>{{ $rpkps->assessments->sum('weight') }}</strong></td>
                    </tr>
                </tbody>
            </table>
            @if($rpkps->final_grade_formula)
                <p style="margin-top:8px;"><strong>Rumus Nilai Akhir:</strong><br>{{ $rpkps->final_grade_formula }}</p>
            @endif
        </td>
        <td style="width:42%; vertical-align:top;">
            @if($rpkps->gradeScales?->count())
            <table class="bordered">
                <thead>
                    <tr><th colspan="4">Konversi Nilai</th></tr>
                    <tr><th>Minimum</th><th>Maksimum</th><th>Huruf Mutu</th><th>Bobot</th></tr>
                </thead>
                <tbody>
                    @foreach($rpkps->gradeScales as $scale)
                    <tr>
                        <td style="text-align:center;">{{ rtrim(rtrim(number_format((float) $scale->min_score, 2, '.', ''), '0'), '.') }}</td>
                        <td style="text-align:center;">{{ rtrim(rtrim(number_format((float) $scale->max_score, 2, '.', ''), '0'), '.') }}</td>
                        <td style="text-align:center;"><strong>{{ $scale->letter }}</strong></td>
                        <td style="text-align:center;">{{ number_format((float) $scale->grade_point, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </td>
    </tr>
</table>
@endif

{{-- KISI-KISI INSTRUMEN PENILAIAN --}}
@if($rpkps->assessmentInstruments?->count())
<div class="page-break"></div>
<h3 style="text-align:center; margin:0 0 8px;">KISI-KISI INSTRUMEN PENILAIAN</h3>
<table class="weekly-table">
    <thead>
        <tr>
            <th>Sub-CPMK yang Diukur</th>
            <th>Instrumen</th>
            <th>Jenis / Teknik</th>
            <th>Kognitif</th>
            <th>Afektif</th>
            <th>Psikomotor</th>
            <th>Nomor Butir</th>
            <th>Bobot (%)</th>
            <th>Waktu</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rpkps->assessmentInstruments as $instrument)
        <tr>
            <td>{{ $subCpmkLabel($instrument->sub_cpmk) ?: '-' }}</td>
            <td><strong>{{ $instrument->instrument_name }}</strong>@if($instrument->description)<br>{{ $instrument->description }}@endif</td>
            <td>{{ $instrument->instrument_type ?? '-' }}@if($instrument->technique)<br>{{ $instrument->technique }}@endif</td>
            <td style="text-align:center;">{{ $instrument->cognitive_level ?? '-' }}</td>
            <td style="text-align:center;">{{ $instrument->affective_level ?? '-' }}</td>
            <td style="text-align:center;">{{ $instrument->psychomotor_level ?? '-' }}</td>
            <td style="text-align:center;">{{ $instrument->item_numbers ?? '-' }}</td>
            <td style="text-align:center;">{{ $instrument->weight }}</td>
            <td>{{ $instrument->timing ?? '-' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- RUBRIK PENILAIAN --}}
@if($rpkps->rubrics?->count())
<div class="rubric-page">
<h3 style="text-align:center; margin:0 0 8px;">RUBRIK PENILAIAN</h3>
<table class="weekly-table">
    <thead>
        <tr>
            <th>Rubrik / Kriteria</th>
            <th style="width:7%;">Bobot</th>
            <th>Sangat Baik</th>
            <th>Baik</th>
            <th>Cukup</th>
            <th>Kurang</th>
            <th>Sangat Kurang</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rpkps->rubrics as $rubric)
        <tr>
            <td>@if($rubric->title)<strong>{{ $rubric->title }}</strong><br>@endif{{ $rubric->criteria }}</td>
            <td style="text-align:center;">{{ $rubric->weight ?? '-' }}</td>
            <td>{{ $rubric->excellent ?? '-' }}</td>
            <td>{{ $rubric->good ?? '-' }}</td>
            <td>{{ $rubric->fair ?? '-' }}</td>
            <td>{{ $rubric->poor ?? '-' }}</td>
            <td>{{ $rubric->very_poor ?? '-' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@if($rpkps->verification_code)
<div class="verification-footer">
    <table style="width:100%; border:none;">
        <tr>
            <td style="width:60px; border:none; padding:0; vertical-align:middle;">
                <a href="{{ $verifyUrl }}"><img src="{{ $qrFooter }}" width="60" height="60" alt="QR Verifikasi"></a>
            </td>
            <td style="border:none; padding:0 0 0 8px; vertical-align:middle; font-size:10pt; color:#555; line-height:1.3;">
                @if($isElectronicallyApproved)
                    Dokumen ini telah disetujui secara elektronik melalui aplikasi Nurul Iman Academic Integrated System. Klik atau scan QR Code untuk memeriksa keaslian dan status dokumen.
                @else
                    Dokumen ini berstatus {{ str_replace('_', ' ', $rpkps->status) }}. QR Code digunakan untuk memeriksa keaslian dan status dokumen dan belum merupakan tanda persetujuan elektronik.
                @endif
            </td>
        </tr>
    </table>
</div>
</div>
@endif
@endif

<div class="footer-info">
    Kode: {{ $rpkps->code }}
</div>

</body>
</html>
