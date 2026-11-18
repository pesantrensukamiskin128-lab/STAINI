<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LecturerWorkExport implements FromArray, WithColumnFormatting, WithStyles, ShouldAutoSize
{
    public function __construct(
        private Collection $works = new Collection(),
        private bool $template = false,
    ) {}

    public function array(): array
    {
        $headings = [
            'lecturer_nidn', 'type', 'title', 'year', 'description', 'keywords',
            'publisher', 'isbn_issn', 'hki_number', 'published_date',
            'status', 'repository_url', 'co_authors',
        ];

        if ($this->template) {
            return [
                $headings,
                ['','buku','Contoh judul karya dosen', 2026, 'Deskripsi singkat karya.', 'pendidikan, inovasi', 'Penerbit Contoh', '978-000-000-000-0', '', '2026-09-24', 'draft', '', 'dosen:NIDN;mahasiswa:NIM'],
                [],
                ['PETUNJUK: Untuk admin, isi lecturer_nidn. Untuk dosen, kolom tersebut boleh dikosongkan dan otomatis memakai NIDN akun sendiri.'],
                ['PETUNJUK: type harus salah satu: buku, modul_ajar, hki_paten, penelitian_mandiri, pengabdian_mandiri.'],
                ['PETUNJUK: co_authors opsional. Pisahkan penulis dengan titik koma. Gunakan dosen:NIDN/mahasiswa:NIM atau dosen_id:ID/mahasiswa_id:ID. Contoh: dosen:1234567890;mahasiswa:2026123456.'],
                ['PETUNJUK: Import hanya membuat karya baru berstatus draft. File utama dan file pendukung diunggah dari halaman detail karya.'],
            ];
        }

        return [
            $headings,
            ...$this->works->map(fn ($work) => [
                $work->lecturer?->nidn ?? '',
                $work->type,
                $work->title,
                $work->year,
                $work->description ?? '',
                $work->keywords ?? '',
                $work->publisher ?? '',
                $work->isbn_issn ?? '',
                $work->hki_number ?? '',
                $work->published_date?->format('Y-m-d') ?? '',
                $work->status,
                $work->repository_url ?? '',
                $work->coAuthors->map(function ($author) {
                    if ($author->lecturer) {
                        return $author->lecturer->nidn
                            ? 'dosen:' . $author->lecturer->nidn
                            : 'dosen_id:' . $author->lecturer_id;
                    }
                    if ($author->student) {
                        return $author->student->nim
                            ? 'mahasiswa:' . $author->student->nim
                            : 'mahasiswa_id:' . $author->student_id;
                    }

                    return null;
                })->filter()->implode(';'),
            ])->all(),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'M' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
