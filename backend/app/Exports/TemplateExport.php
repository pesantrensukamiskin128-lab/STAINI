<?php

namespace App\Exports;

use App\Exports\StudentExport;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TemplateExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle, WithColumnFormatting, WithCustomValueBinder
{
    private string $type;

    private const HEADINGS = [
        'courses' => [
            'kode_mk', 'nama_mata_kuliah', 'kode_prodi',
            'rumpun_mata_kuliah', 'sks_teori', 'sks_praktik',
            'semester', 'jenis', 'status',
        ],
        'lecturers' => [
            'nidn', 'nuptk', 'nip', 'gelar_depan', 'nama_lengkap', 'gelar_belakang',
            'kode_prodi', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
            'email', 'no_hp', 'jabatan_akademik', 'status_kepegawaian', 'status',
        ],
        'student-advisors' => [
            'NIM', 'Nama Mahasiswa', 'Kode Prodi', 'Nama Prodi', 'NIDN Dosen PA', 'Nama Dosen PA',
        ],
        'staff' => [
            'nip', 'nama_lengkap', 'jenis_kelamin',
            'tempat_lahir', 'tanggal_lahir',
            'email', 'no_hp', 'jabatan', 'unit_bagian',
            'status_kepegawaian', 'status',
        ],
        'krs' => [
            'nim', 'nama_mahasiswa', 'kode_prodi', 'id_semester', 'nama_semester',
            'kode_mata_kuliah', 'nama_mata_kuliah', 'sks', 'id_kelas', 'nama_kelas', 'dosen_pengampu', 'status_krs', 'total_sks',
        ],
    ];

    /** Baris contoh agar user paham format yang diharapkan */
    private const EXAMPLES = [
        'courses' => [['TI999', 'Contoh Mata Kuliah', 'TI', 'Mata Kuliah Keahlian', 2, 1, 1, 'Wajib', 'Aktif']],
        'lecturers' => [['1234567890', '', '', 'Dr.', 'Nama Dosen Contoh', 'S.Kom., M.T.', 'TI', 'Laki-laki', 'Bandung', '1980-01-15', 'dosen@example.com', '08123456789', 'Asisten Ahli', 'Tetap', 'Aktif']],
        'students' => [[
            '2024001001', 'Nama Mahasiswa Contoh', 'Jakarta', '2002-05-20', 'L',
            '3200000000000000', '1', '0040000000', '1', '', 'ID', '1',
            '2024-08-20', '20241', 'Jl. Contoh No. 1', '01', '02',
            'Dusun Contoh', 'Kelurahan Contoh', 'Kecamatan Contoh', '40123',
            '1', '1', '021000000', '08198765432', 'mhs@example.com', '0', '',
            '', 'Nama Ayah Contoh', '1970-01-01', '2', '1', '5',
            '', 'Nama Ibu Contoh', '1975-01-01', '2', '2', '5',
            'Nama Wali Contoh', '1980-01-01', '2', '3', '5',
            'TI', 'Teknik Informatika', '', '', '', '', '', '1', '5000000',
            'O', 'Bandung', 'Jawa Barat',
            '081234567801', 'ayah@example.com', 1,
            '081234567802', 'ibu@example.com', 1,
            '081234567803', 'wali@example.com', 1,
            'SMA Negeri 1 Bandung', 'Jl. Pendidikan No. 1', 'IPA', 2020, 'DN-001234',
        ]],
        'student-advisors' => [[
            '2024001001', 'Nama Mahasiswa Contoh', 'PAI', 'Pendidikan Agama Islam',
            '0011223344', 'Nama Dosen PA Contoh',
        ]],
        'staff' => [['198001012010011001', 'Nama Staff Contoh', 'Laki-laki', 'Surabaya', '1980-01-01', 'staff@example.com', '08111111111', 'Kepala Bagian', 'Akademik', 'Tetap', 'Aktif']],
        'krs' => [
            ['2026001001', 'Nama Mahasiswa Contoh', 'PAI', '', '2026/2027 Ganjil', 'PAI101', 'Contoh Mata Kuliah 1', 2, 101, 'A', 'Nama Dosen Contoh, M.Pd.', 'DRAFT', ''],
            ['2026001001', 'Nama Mahasiswa Contoh', 'PAI', '', '2026/2027 Ganjil', 'PAI102', 'Contoh Mata Kuliah 2', 3, 102, 'A', 'Nama Dosen Contoh, M.Pd.', 'DRAFT', ''],
        ],
    ];

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public function title(): string
    {
        return 'Template';
    }

    public function array(): array
    {
        return self::EXAMPLES[$this->type] ?? [];
    }

    public function headings(): array
    {
        if ($this->type === 'students') {
            return (new StudentExport)->headings();
        }

        return self::HEADINGS[$this->type] ?? [];
    }

    public function columnFormats(): array
    {
        if ($this->type !== 'students') {
            return [];
        }

        return array_fill_keys(StudentExport::TEXT_COLUMNS, NumberFormat::FORMAT_TEXT);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($this->type === 'students' && in_array($cell->getColumn(), StudentExport::TEXT_COLUMNS, true)) {
            $cell->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '16A34A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            2 => [
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
            ],
        ];
    }
}
