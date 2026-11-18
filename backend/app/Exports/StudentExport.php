<?php

namespace App\Exports;

use App\Models\Student;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StudentExport extends DefaultValueBinder implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithCustomValueBinder, WithStyles, ShouldAutoSize, WithTitle
{
    public const TEXT_COLUMNS = [
        'A', 'F', 'H', 'I', 'J', 'L', 'N', 'P', 'Q', 'U', 'V', 'W',
        'X', 'Y', 'AB', 'AC', 'AI', 'AT', 'AW', 'AY',
        'BD', 'BE', 'BF', 'BG', 'BI', 'BJ', 'BL', 'BM', 'BS',
    ];

    public function __construct(
        private ?int $studyProgramId = null,
        private ?string $status = null,
        private ?int $entryYear = null,
        private ?int $currentSemester = null,
    ) {}

    public function title(): string
    {
        return 'Mahasiswa';
    }

    public function query()
    {
        return Student::with([
            'studyProgram',
            'advisor',
            'profile',
            'domicileAddress',
            'parents',
            'educationHistories' => fn ($query) => $query->where('level', 'SMA/MA/SMK')->orderByDesc('id'),
        ])
            ->when($this->studyProgramId, fn($q) => $q->where('study_program_id', $this->studyProgramId))
            ->when($this->status, fn($q) => $q->where('status', $this->status))
            ->when($this->entryYear, fn($q) => $q->where('entry_year', $this->entryYear))
            ->when($this->currentSemester, fn($q) => $q->where('current_semester', $this->currentSemester))
            ->orderBy('study_program_id')
            ->orderBy('nim');
    }

    public function headings(): array
    {
        return [
            'NIM', 'Nama', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Kelamin',
            'NIK', 'Agama', 'NISN', 'Jalur Pendaftaran', 'NPWP',
            'Kewarganegaraan', 'Jenis Pendaftaran', 'Tanggal Masuk Kuliah',
            'Mulai Semester', 'Jalan', 'RT', 'RW', 'Nama Dusun', 'Kelurahan',
            'Kecamatan', 'Kode Pos', 'Jenis Tinggal', 'Alat Transportasi',
            'Telp Rumah', 'No HP', 'Email', 'Terima KPS', 'No KPS',
            'NIK Ayah', 'Nama Ayah', 'Tanggal Lahir Ayah', 'Pendidikan Ayah',
            'Pekerjaan Ayah', 'Penghasilan Ayah', 'NIK Ibu', 'Nama Ibu',
            'Tanggal Lahir Ibu', 'Pendidikan Ibu', 'Pekerjaan Ibu',
            'Penghasilan Ibu', 'Nama Wali', 'Tanggal Lahir Wali',
            'Pendidikan Wali', 'Pekerjaan Wali', 'Penghasilan Wali',
            'Kode Prodi', 'Nama Prodi', 'SKS Diakui', 'Kode PT Asal',
            'Nama PT Asal', 'Kode Prodi Asal', 'Nama Prodi Asal',
            'Jenis Pembiayaan', 'Jumlah Biaya Masuk',
            'Golongan Darah', 'Kota/Kabupaten', 'Provinsi',
            'No HP Ayah', 'Email Ayah', 'Ayah Masih Hidup',
            'No HP Ibu', 'Email Ibu', 'Ibu Masih Hidup',
            'No HP Wali', 'Email Wali', 'Wali Masih Hidup',
            'Nama Sekolah SMA/MA/SMK', 'Alamat Sekolah', 'Jurusan Sekolah',
            'Tahun Lulus', 'Nomor Ijazah',
        ];
    }

    public function map($student): array
    {
        $profile = $student->profile;
        $address = $student->domicileAddress;
        $parent = fn (string $relation) => $student->parents->firstWhere('relation', $relation);
        $father = $parent('Ayah');
        $mother = $parent('Ibu');
        $guardian = $parent('Wali');
        $education = $student->educationHistories->first();

        return [
            $student->nim,
            $student->name,
            $student->birth_place ?? '',
            $student->birth_date?->format('Y-m-d') ?? '',
            $student->gender ?? '',
            $profile?->nik ?? '',
            $profile?->religion ?? '',
            $profile?->nisn ?? '',
            $profile?->admission_path ?? '',
            $profile?->npwp ?? '',
            $profile?->nationality ?? '',
            $profile?->registration_type ?? '',
            $profile?->enrollment_date?->format('Y-m-d') ?? '',
            $profile?->start_semester ?? '',
            $address?->address ?? '',
            $address?->rt ?? '',
            $address?->rw ?? '',
            $address?->hamlet ?? '',
            $address?->village ?? '',
            $address?->district ?? '',
            $address?->postal_code ?? '',
            $profile?->residence_type ?? '',
            $profile?->transport_mode ?? '',
            $student->home_phone ?? '',
            $student->phone ?? '',
            $student->email ?? '',
            $profile?->kps_recipient === null ? '' : (int) $profile->kps_recipient,
            $profile?->kps_number ?? '',
            $father?->nik ?? '',
            $father?->name ?? '',
            $father?->birth_date?->format('Y-m-d') ?? '',
            $father?->education ?? '',
            $father?->occupation ?? '',
            $father?->income ?? '',
            $mother?->nik ?? '',
            $mother?->name ?? '',
            $mother?->birth_date?->format('Y-m-d') ?? '',
            $mother?->education ?? '',
            $mother?->occupation ?? '',
            $mother?->income ?? '',
            $guardian?->name ?? '',
            $guardian?->birth_date?->format('Y-m-d') ?? '',
            $guardian?->education ?? '',
            $guardian?->occupation ?? '',
            $guardian?->income ?? '',
            $student->studyProgram?->code ?? '',
            $student->studyProgram?->name ?? '',
            $profile?->recognized_credits ?? '',
            $profile?->origin_institution_code ?? '',
            $profile?->origin_institution_name ?? '',
            $profile?->origin_program_code ?? '',
            $profile?->origin_program_name ?? '',
            $profile?->financing_type ?? '',
            $profile?->entry_fee ?? '',
            $profile?->blood_type ?? '',
            $address?->city ?? '',
            $address?->province ?? '',
            $father?->phone ?? '',
            $father?->email ?? '',
            $father === null ? '' : (int) $father->is_alive,
            $mother?->phone ?? '',
            $mother?->email ?? '',
            $mother === null ? '' : (int) $mother->is_alive,
            $guardian?->phone ?? '',
            $guardian?->email ?? '',
            $guardian === null ? '' : (int) $guardian->is_alive,
            $education?->institution_name ?? '',
            $education?->institution_address ?? '',
            $education?->major ?? '',
            $education?->graduation_year ?? '',
            $education?->diploma_number ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnFormats(): array
    {
        return array_fill_keys(self::TEXT_COLUMNS, NumberFormat::FORMAT_TEXT);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (in_array($cell->getColumn(), self::TEXT_COLUMNS, true)) {
            $cell->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
