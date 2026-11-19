<?php

namespace App\Imports;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudyProgram;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class StudentImport implements SkipsOnError, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    use SkipsErrors;

    private array $programCache = [];

    public function model(array $row): ?Student
    {
        $nim = trim($row['nim'] ?? '');
        $name = trim($row['nama'] ?? '');
        if (! $nim || ! $name) {
            return null;
        }

        $programId = $this->resolveProgramId($row['kode_prodi'] ?? '');
        if (! $programId) {
            return null;
        }

        $genderMap = ['laki-laki' => 'L', 'perempuan' => 'P', 'l' => 'L', 'p' => 'P'];
        $genderRaw = strtolower(trim($row['jenis_kelamin'] ?? ''));
        $genderMap += ['male' => 'L', 'female' => 'P', 'm' => 'L', 'f' => 'P'];
        $gender = $genderMap[$genderRaw] ?? null;

        $birthDate = $this->parseDate($row['tanggal_lahir'] ?? null);
        $entryYear = $this->entryYear($row);
        $academicYearId = $this->resolveAcademicYearId($entryYear);

        return DB::transaction(function () use ($row, $nim, $name, $programId, $gender, $birthDate, $entryYear, $academicYearId): Student {
            $student = Student::firstOrNew(['nim' => $nim]);
            $linkedUser = $student->user;
            $email = trim((string) ($row['email'] ?? ''));
            $email = $email ?: ($student->email ?: $linkedUser?->email ?: "{$nim}@student.stainibandung.ac.id");
            $user = $linkedUser ?? User::firstOrCreate(
                ['username' => $nim],
                ['name' => $name, 'email' => $email, 'password' => Hash::make($nim)]
            );
            if ($user->wasRecentlyCreated) {
                $user->assignRole('MAHASISWA');
            }

            $studentFields = [
                'user_id' => $student->user_id ?? $user->id,
                'study_program_id' => $programId,
                'name' => $name,
                'email' => $email,
            ];
            $optionalStudentFields = [
                'jenis_kelamin' => 'gender',
                'tempat_lahir' => 'birth_place',
                'tanggal_lahir' => 'birth_date',
                'no_hp' => 'phone',
                'telp_rumah' => 'home_phone',
            ];
            foreach (array_intersect_key($optionalStudentFields, $row) as $column => $attribute) {
                $studentFields[$attribute] = match ($attribute) {
                    'gender' => $gender,
                    'birth_date' => $birthDate,
                    default => trim((string) ($row[$column] ?? '')) ?: null,
                };
            }
            $student->fill($studentFields);
            if ($entryYear !== null) {
                $student->entry_year = $entryYear;
            }
            if ($academicYearId !== null) {
                $student->academic_year_id = $academicYearId;
            }
            $student->save();

            $profileFields = [];
            $profileColumnToAttribute = [
                'agama' => 'religion',
                'nik' => 'nik',
                'nisn' => 'nisn',
                'golongan_darah' => 'blood_type',
                'kewarganegaraan' => 'nationality',
                'jalur_pendaftaran' => 'admission_path',
                'npwp' => 'npwp',
                'jenis_pendaftaran' => 'registration_type',
                'tanggal_masuk_kuliah' => 'enrollment_date',
                'mulai_semester' => 'start_semester',
                'jenis_tinggal' => 'residence_type',
                'alat_transportasi' => 'transport_mode',
                'terima_kps' => 'kps_recipient',
                'no_kps' => 'kps_number',
                'jenis_pembiayaan' => 'financing_type',
                'sks_diakui' => 'recognized_credits',
                'kode_pt_asal' => 'origin_institution_code',
                'nama_pt_asal' => 'origin_institution_name',
                'kode_prodi_asal' => 'origin_program_code',
                'nama_prodi_asal' => 'origin_program_name',
                'jumlah_biaya_masuk' => 'entry_fee',
            ];
            foreach (array_intersect_key($profileColumnToAttribute, $row) as $column => $attribute) {
                $profileFields[$attribute] = match ($column) {
                    'tanggal_masuk_kuliah' => $this->parseDate($row[$column]),
                    'terima_kps' => $this->parseBoolean($row[$column]),
                    'kewarganegaraan' => $row[$column] ?? 'Indonesia',
                    default => $row[$column] ?? null,
                };
            }
            if ($profileFields !== []) {
                $student->profile()->updateOrCreate([], $profileFields);
            }

            $addressColumnToAttribute = [
                'jalan' => 'address',
                'rt' => 'rt',
                'rw' => 'rw',
                'nama_dusun' => 'hamlet',
                'kelurahan' => 'village',
                'kecamatan' => 'district',
                'kota_kabupaten' => 'city',
                'provinsi' => 'province',
                'kode_pos' => 'postal_code',
            ];
            $addressFields = [];
            foreach (array_intersect_key($addressColumnToAttribute, $row) as $column => $attribute) {
                $addressFields[$attribute] = $row[$column] ?? null;
            }
            if ($addressFields !== []) {
                $student->addresses()->updateOrCreate(['type' => 'Domisili'], $addressFields);
            }

            $this->upsertParent($student, 'Ayah', $row, 'ayah');
            $this->upsertParent($student, 'Ibu', $row, 'ibu');
            $this->upsertParent($student, 'Wali', $row, 'wali');
            $this->upsertEducation($student, $row);

            return $student;
        });
    }

    public function rules(): array
    {
        return [
            'nim' => 'required',
            'nama' => 'required',
            'kode_prodi' => 'required',
        ];
    }

    private function entryYear(array $row): ?int
    {
        if (! empty($row['mulai_semester']) && preg_match('/^(\\d{4})/', (string) $row['mulai_semester'], $match)) {
            return (int) $match[1];
        }

        $date = $this->parseDate($row['tanggal_masuk_kuliah'] ?? null);
        return $date?->year;
    }

    private function resolveAcademicYearId(?int $entryYear): ?int
    {
        if (! $entryYear) {
            return null;
        }

        return AcademicYear::whereYear('start_date', $entryYear)->value('id')
            ?? AcademicYear::where('name', 'like', "%{$entryYear}%")->value('id');
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
            }

            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(strtolower((string) $value), ['1', 'ya', 'yes', 'true'], true);
    }

    private function upsertParent(Student $student, string $relation, array $row, string $key): void
    {
        $columns = [
            "nik_{$key}" => 'nik',
            "tanggal_lahir_{$key}" => 'birth_date',
            "pendidikan_{$key}" => 'education',
            "pekerjaan_{$key}" => 'occupation',
            "penghasilan_{$key}" => 'income',
            "no_hp_{$key}" => 'phone',
            "email_{$key}" => 'email',
            "{$key}_masih_hidup" => 'is_alive',
        ];
        $provided = array_intersect_key($columns, $row);
        $nameColumn = "nama_{$key}";
        $nameProvided = array_key_exists($nameColumn, $row);
        if (! $nameProvided && $provided === []) {
            return;
        }

        $name = trim((string) ($row[$nameColumn] ?? ''));
        if ($nameProvided && $name === '') {
            $student->parents()->where('relation', $relation)->delete();

            return;
        }

        $parent = $student->parents()->where('relation', $relation)->first();
        if (! $parent && ! $nameProvided) {
            return;
        }

        $parentFields = $nameProvided ? ['name' => $name] : [];
        foreach ($provided as $column => $attribute) {
            $parentFields[$attribute] = match ($attribute) {
                'birth_date' => $this->parseDate($row[$column]),
                'is_alive' => $this->parseBoolean($row[$column]) ?? false,
                default => trim((string) ($row[$column] ?? '')) ?: null,
            };
        }

        if ($parent) {
            $parent->update($parentFields);
        } else {
            $student->parents()->create($parentFields + ['relation' => $relation]);
        }
    }

    private function upsertEducation(Student $student, array $row): void
    {
        $columns = [
            'nama_sekolah_sma_ma_smk' => 'institution_name',
            'alamat_sekolah' => 'institution_address',
            'jurusan_sekolah' => 'major',
            'tahun_lulus' => 'graduation_year',
            'nomor_ijazah' => 'diploma_number',
        ];
        $provided = array_intersect_key($columns, $row);
        if ($provided === []) {
            return;
        }

        $history = $student->educationHistories()->where('level', 'SMA/MA/SMK')->orderByDesc('id');
        $education = $history->first();
        $schoolNameProvided = array_key_exists('nama_sekolah_sma_ma_smk', $row);
        $schoolName = trim((string) ($row['nama_sekolah_sma_ma_smk'] ?? ''));
        if ($schoolNameProvided && $schoolName === '') {
            $history->delete();

            return;
        }
        if (! $schoolNameProvided && ! $education) {
            return;
        }

        $fields = [];
        foreach ($provided as $column => $attribute) {
            $value = trim((string) ($row[$column] ?? '')) ?: null;
            $fields[$attribute] = $attribute === 'graduation_year' && $value !== null
                ? (int) $value
                : $value;
        }

        if ($education) {
            $education->update($fields);
        } else {
            $student->educationHistories()->create($fields + ['level' => 'SMA/MA/SMK']);
        }
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 200;
    }

    private function resolveProgramId(string $code): ?int
    {
        $code = strtoupper(trim($code));
        if (! $code) {
            return null;
        }

        if (! isset($this->programCache[$code])) {
            $this->programCache[$code] = StudyProgram::where('code', $code)->value('id');
        }

        return $this->programCache[$code];
    }

}
