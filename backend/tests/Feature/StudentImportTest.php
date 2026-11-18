<?php

namespace Tests\Feature;

use App\Imports\StudentImport;
use App\Http\Controllers\Api\StudentController;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createMinimalSchema();

        DB::table('roles')->insert(['id' => 1, 'name' => 'MAHASISWA', 'guard_name' => 'web']);
        DB::table('study_programs')->insert(['id' => 1, 'code' => 'PAI', 'name' => 'Pendidikan Agama Islam']);
        DB::table('academic_years')->insert([
            'id' => 1,
            'name' => '2026/2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
        ]);
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Nama Sebelumnya',
            'username' => '2026001001',
            'email' => 'lama@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'id' => 1,
            'user_id' => 1,
            'study_program_id' => 1,
            'academic_year_id' => null,
            'advisor_id' => 77,
            'nim' => '2026001001',
            'name' => 'Nama Sebelumnya',
            'gender' => 'L',
            'status' => 'Cuti',
            'current_semester' => 7,
            'class_type' => 'KHUSUS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_profiles')->insert([
            'student_id' => 1,
            'photo_path' => 'students/private-photo.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_import_persists_exported_columns_and_preserves_application_only_fields(): void
    {
        $student = (new StudentImport)->model([
            'nim' => '2026001001',
            'nama' => 'Nama Terbaru',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '2005-02-03',
            'jenis_kelamin' => 'P',
            'nik' => '0012345678901234',
            'agama' => 'Islam',
            'nisn' => '0001234567',
            'jalur_pendaftaran' => '01',
            'npwp' => '001234567890123',
            'kewarganegaraan' => 'Indonesia',
            'jenis_pendaftaran' => '01',
            'tanggal_masuk_kuliah' => '2026-08-20',
            'mulai_semester' => '2026 Ganjil',
            'jalan' => 'Jl. Pendidikan',
            'rt' => '01',
            'rw' => '02',
            'nama_dusun' => 'Dusun Satu',
            'kelurahan' => 'Desa Satu',
            'kecamatan' => 'Kecamatan Satu',
            'kode_pos' => '00401',
            'jenis_tinggal' => '01',
            'alat_transportasi' => '01',
            'telp_rumah' => '0221234567',
            'no_hp' => '081234567890',
            'email' => 'baru@example.test',
            'terima_kps' => '1',
            'no_kps' => '0011223344',
            'nik_ayah' => '0022334455667788',
            'nama_ayah' => 'Bapak Baru',
            'tanggal_lahir_ayah' => '1970-01-01',
            'pendidikan_ayah' => 'SMA',
            'pekerjaan_ayah' => 'Petani',
            'penghasilan_ayah' => '5000000',
            'nik_ibu' => '0033445566778899',
            'nama_ibu' => 'Ibu Baru',
            'tanggal_lahir_ibu' => '1975-01-01',
            'pendidikan_ibu' => 'SMA',
            'pekerjaan_ibu' => 'Wiraswasta',
            'penghasilan_ibu' => '4000000',
            'nama_wali' => 'Wali Baru',
            'tanggal_lahir_wali' => '1980-01-01',
            'pendidikan_wali' => 'SMA',
            'pekerjaan_wali' => 'Pegawai',
            'penghasilan_wali' => '6000000',
            'kode_prodi' => 'PAI',
            'nama_prodi' => 'Pendidikan Agama Islam',
            'sks_diakui' => '12',
            'kode_pt_asal' => '001',
            'nama_pt_asal' => 'Perguruan Tinggi Asal',
            'kode_prodi_asal' => '002',
            'nama_prodi_asal' => 'Prodi Asal',
            'jenis_pembiayaan' => '01',
            'jumlah_biaya_masuk' => '5000000',
            'golongan_darah' => 'O',
            'kota_kabupaten' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp_ayah' => '081234567801',
            'email_ayah' => 'ayah@example.test',
            'ayah_masih_hidup' => '1',
            'no_hp_ibu' => '081234567802',
            'email_ibu' => 'ibu@example.test',
            'ibu_masih_hidup' => '1',
            'no_hp_wali' => '081234567803',
            'email_wali' => 'wali@example.test',
            'wali_masih_hidup' => '0',
            'nama_sekolah_sma_ma_smk' => 'SMA Negeri 1 Bandung',
            'alamat_sekolah' => 'Jl. Pendidikan No. 1',
            'jurusan_sekolah' => 'IPA',
            'tahun_lulus' => '2020',
            'nomor_ijazah' => 'DN-001234',
        ]);

        $this->assertSame(1, $student->id);
        $student->refresh();
        $student->load(['profile', 'domicileAddress', 'parents', 'educationHistories']);

        $this->assertSame('Nama Terbaru', $student->name);
        $this->assertSame('Cuti', $student->status);
        $this->assertSame('KHUSUS', $student->class_type);
        $this->assertSame(7, $student->current_semester);
        $this->assertSame(77, $student->advisor_id);
        $this->assertSame(1, $student->academic_year_id);
        $this->assertSame('0012345678901234', $student->profile->nik);
        $this->assertSame('0001234567', $student->profile->nisn);
        $this->assertSame('students/private-photo.jpg', $student->profile->photo_path);
        $this->assertSame('00401', $student->domicileAddress->postal_code);
        $this->assertSame('Bandung', $student->domicileAddress->city);
        $this->assertSame('Jawa Barat', $student->domicileAddress->province);
        $this->assertSame('O', $student->profile->blood_type);
        $this->assertSame('01', $student->domicileAddress->rt);
        $this->assertSame('0022334455667788', $student->parents->firstWhere('relation', 'Ayah')->nik);
        $this->assertSame('081234567801', $student->parents->firstWhere('relation', 'Ayah')->phone);
        $this->assertTrue($student->parents->firstWhere('relation', 'Ayah')->is_alive);
        $this->assertSame('0033445566778899', $student->parents->firstWhere('relation', 'Ibu')->nik);
        $this->assertSame('Wali Baru', $student->parents->firstWhere('relation', 'Wali')->name);
        $this->assertFalse($student->parents->firstWhere('relation', 'Wali')->is_alive);
        $this->assertDatabaseHas('student_education_histories', [
            'student_id' => $student->id,
            'level' => 'SMA/MA/SMK',
            'institution_name' => 'SMA Negeri 1 Bandung',
            'institution_address' => 'Jl. Pendidikan No. 1',
            'major' => 'IPA',
            'graduation_year' => 2020,
            'diploma_number' => 'DN-001234',
        ]);

        (new StudentImport)->model([
            'nim' => '2026001001',
            'nama' => 'Nama Terbaru',
            'kode_prodi' => 'PAI',
        ]);

        $student->refresh();
        $student->load(['profile', 'domicileAddress', 'parents', 'educationHistories']);
        $this->assertSame('Cuti', $student->status);
        $this->assertSame('KHUSUS', $student->class_type);
        $this->assertSame(7, $student->current_semester);
        $this->assertSame(77, $student->advisor_id);
        $this->assertSame('students/private-photo.jpg', $student->profile->photo_path);
        $this->assertSame('O', $student->profile->blood_type);
        $this->assertSame('Bandung', $student->domicileAddress->city);
        $this->assertSame('081234567801', $student->parents->firstWhere('relation', 'Ayah')->phone);
        $this->assertSame('DN-001234', $student->educationHistories->first()->diploma_number);
    }

    public function test_manual_student_creation_persists_address_and_origin_school(): void
    {
        $response = (new StudentController)->store(Request::create('/api/students', 'POST', [
            'study_program_id' => 1,
            'nim' => '2026002002',
            'name' => 'Mahasiswa Baru',
            'email' => 'baru@example.test',
            'entry_year' => 2026,
            'address' => 'Jl. Baru No. 2',
            'origin_school' => 'SMA Negeri 1 Bandung',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $studentId = $response->getData(true)['data']['id'];
        $this->assertDatabaseHas('student_addresses', [
            'student_id' => $studentId,
            'type' => 'Domisili',
            'address' => 'Jl. Baru No. 2',
        ]);
        $this->assertDatabaseHas('student_education_histories', [
            'student_id' => $studentId,
            'level' => 'SMA/MA/SMK',
            'institution_name' => 'SMA Negeri 1 Bandung',
        ]);
    }

    private function createMinimalSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('study_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->string('nim')->unique();
            $table->string('name');
            $table->string('gender')->nullable();
            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('home_phone')->nullable();
            $table->integer('entry_year')->nullable();
            $table->string('status')->default('Aktif');
            $table->integer('current_semester')->default(1);
            $table->string('class_type')->default('REGULER');
            $table->timestamps();
        });
        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id')->unique();
            $table->string('religion')->nullable();
            $table->string('nik')->nullable();
            $table->string('nisn')->nullable();
            $table->string('blood_type')->nullable();
            $table->string('nationality')->default('Indonesia');
            $table->string('photo_path')->nullable();
            $table->string('admission_path')->nullable();
            $table->string('npwp')->nullable();
            $table->string('registration_type')->nullable();
            $table->date('enrollment_date')->nullable();
            $table->string('start_semester')->nullable();
            $table->string('residence_type')->nullable();
            $table->string('transport_mode')->nullable();
            $table->boolean('kps_recipient')->nullable();
            $table->string('kps_number')->nullable();
            $table->string('financing_type')->nullable();
            $table->integer('recognized_credits')->nullable();
            $table->string('origin_institution_code')->nullable();
            $table->string('origin_institution_name')->nullable();
            $table->string('origin_program_code')->nullable();
            $table->string('origin_program_name')->nullable();
            $table->decimal('entry_fee', 14, 0)->nullable();
            $table->timestamps();
        });
        Schema::create('student_addresses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('type');
            $table->text('address')->nullable();
            $table->string('village')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('rt')->nullable();
            $table->string('rw')->nullable();
            $table->string('hamlet')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'type']);
        });
        Schema::create('student_education_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('level');
            $table->string('institution_name');
            $table->string('institution_address')->nullable();
            $table->string('major')->nullable();
            $table->integer('entry_year')->nullable();
            $table->integer('graduation_year')->nullable();
            $table->string('diploma_number')->nullable();
            $table->decimal('gpa', 4, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('student_parents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('relation');
            $table->string('name');
            $table->string('nik')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('education')->nullable();
            $table->string('occupation')->nullable();
            $table->string('income')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_alive')->default(true);
            $table->timestamps();
            $table->unique(['student_id', 'relation']);
        });
    }
}
