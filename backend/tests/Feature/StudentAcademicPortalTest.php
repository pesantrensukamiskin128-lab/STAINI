<?php

namespace Tests\Feature;

use App\Exports\StudentExport;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\VerifyController;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use App\Support\AcademicDocumentVerification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudentAcademicPortalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createMinimalSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'MAHASISWA', 'guard_name' => 'web'],
            ['id' => 2, 'name' => 'ADMIN_AKADEMIK', 'guard_name' => 'web'],
            ['id' => 3, 'name' => 'KAPRODI', 'guard_name' => 'web'],
        ]);
        DB::table('users')->insert([
            [
                'id' => 1, 'name' => 'Mahasiswa Satu', 'username' => 'M001',
                'email' => 'm001@example.test', 'password' => bcrypt('secret'), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 3, 'name' => 'Admin Akademik', 'username' => 'admin.akademik',
                'email' => 'admin@example.test', 'password' => bcrypt('secret'), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 4, 'name' => 'Kaprodi', 'username' => 'kaprodi',
                'email' => 'kaprodi@example.test', 'password' => bcrypt('secret'), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 1],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => 3],
            ['role_id' => 3, 'model_type' => User::class, 'model_id' => 4],
        ]);
        DB::table('institutions')->insert([
            'code' => 'ASC', 'name' => 'Sekolah Tinggi Agama Islam Al-Jawami',
            'address' => 'Jl. Pendidikan No. 1, Bandung',
            'letterhead_path' => '../../../tests/Fixtures/letterhead.svg',
        ]);
        DB::table('faculties')->insert(['id' => 1, 'code' => 'FT', 'name' => 'Fakultas Tarbiyah']);
        DB::table('lecturers')->insert([
            [
                'id' => 1, 'nidn' => '0011223344', 'full_name' => 'Ahmad Fauzi',
                'degree_front' => 'Dr.', 'degree_back' => 'M.Pd', 'status' => true,
            ],
            [
                'id' => 2, 'nidn' => '0099887766', 'full_name' => 'Siti Rahmawati',
                'degree_front' => 'Dr.', 'degree_back' => 'M.Pd', 'status' => true,
            ],
        ]);
        DB::table('lecturers')->insert([
            'id' => 3, 'user_id' => 4, 'nidn' => '0077665544',
            'full_name' => 'Kaprodi', 'status' => true,
        ]);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 2, 'position_code' => 'WK1', 'position_name' => 'Wakil Ketua I',
            'is_active' => true,
        ]);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 3, 'position_code' => 'KAPRODI', 'position_name' => 'Ketua Program Studi',
            'scope_type' => 'study_program', 'scope_id' => 1, 'is_active' => true,
        ]);
        DB::table('study_programs')->insert([
            'id' => 1, 'faculty_id' => 1, 'head_lecturer_id' => 1,
            'code' => 'P1', 'name' => 'Pendidikan Agama Islam', 'level' => 'S1',
        ]);
        DB::table('study_programs')->insert([
            'id' => 2, 'faculty_id' => 1, 'head_lecturer_id' => 1,
            'code' => 'P2', 'name' => 'Program Studi Lain', 'level' => 'S1',
        ]);
        DB::table('students')->insert([
            [
                'id' => 1, 'user_id' => 1, 'study_program_id' => 1, 'advisor_id' => 1, 'nim' => 'M001',
                    'name' => 'Mahasiswa Satu', 'birth_date' => null, 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'user_id' => null, 'study_program_id' => 1, 'advisor_id' => null, 'nim' => 'M002',
                    'name' => 'Mahasiswa Dua', 'birth_date' => null, 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 3, 'user_id' => null, 'study_program_id' => 2, 'advisor_id' => null, 'nim' => 'M003',
                    'name' => 'Mahasiswa Prodi Lain', 'birth_date' => null, 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('semesters')->insert([
            'id' => 1, 'name' => 'Ganjil 2020/2021', 'type' => 'Ganjil',
            'start_date' => '2020-09-01', 'end_date' => '2021-01-31',
        ]);
        DB::table('student_semester_summaries')->insert([
            'student_id' => 1, 'semester_id' => 1, 'status' => 'AKTIF',
            'semester_gpa' => 3.50, 'cumulative_gpa' => 3.25,
            'credits_taken' => 20, 'total_credits' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('courses')->insert(['id' => 1, 'code' => 'MK1', 'name' => 'Mata Kuliah 1', 'credits' => 2]);
        DB::table('student_grades')->insert([
            [
                'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
                'letter_grade' => 'A', 'grade_point' => 4, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'student_id' => 2, 'course_id' => 1, 'semester_id' => 1,
                'letter_grade' => 'E', 'grade_point' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function test_student_list_and_export_filter_semester_and_entry_year(): void
    {
        DB::table('students')->where('id', 1)->update(['entry_year' => 2024, 'current_semester' => 5]);
        DB::table('students')->where('id', 2)->update(['entry_year' => 2024, 'current_semester' => 3]);
        DB::table('students')->where('id', 3)->update(['entry_year' => 2023, 'current_semester' => 5]);
        $this->actingAs(User::findOrFail(3));

        $controller = app(StudentController::class);
        $all = $controller->index(Request::create('/api/students', 'GET'))->getData(true);
        $semester = $controller->index(Request::create('/api/students', 'GET', ['current_semester' => 5]))->getData(true);
        $year = $controller->index(Request::create('/api/students', 'GET', ['entry_year' => 2024]))->getData(true);
        $both = $controller->index(Request::create('/api/students', 'GET', [
            'entry_year' => 2024, 'current_semester' => 5, 'search' => 'M00',
        ]))->getData(true);

        $this->assertSame(3, $all['total']);
        $this->assertSame(2, $semester['total']);
        $this->assertSame(2, $year['total']);
        $this->assertSame([1], array_column($both['data'], 'id'));
        $this->assertSame([1], (new StudentExport(null, null, 2024, 5))->query()->pluck('id')->all());

        $this->actingAs(User::findOrFail(4));
        $scoped = $controller->index(Request::create('/api/students', 'GET', ['search' => 'M00']))->getData(true);
        $this->assertSame([2, 1], array_column($scoped['data'], 'id'));
    }

    public function test_portal_returns_authenticated_students_history_and_grades_only(): void
    {
        $user = User::findOrFail(1);

        $historyRequest = Request::create('/api/students/me/academic-history', 'GET');
        $historyRequest->setUserResolver(fn () => $user);
        $history = app(StudentController::class)->myAcademicHistory($historyRequest)->getData(true);

        $this->assertSame('M001', $history['student']['nim']);
        $this->assertCount(1, $history['summaries']);
        $this->assertSame('3.50', $history['summaries'][0]['semester_gpa']);
        $this->assertSame(20, $history['summaries'][0]['credits_taken']);

        // Meskipun ID mahasiswa lain dikirim, akun mahasiswa tetap menerima nilainya sendiri.
        $khsRequest = Request::create('/api/grades/khs', 'GET', [
            'student_id' => 2,
            'semester_id' => 1,
        ]);
        $khsRequest->setUserResolver(fn () => $user);
        $khs = app(GradeController::class)->khs($khsRequest)->getData(true);

        $this->assertCount(1, $khs['grades']);
        $this->assertSame('A', $khs['grades'][0]['letter_grade']);
        $this->assertEquals(3.50, $khs['ips']);

        $transcriptRequest = Request::create('/api/grades/transcript', 'GET', ['student_id' => 2]);
        $transcriptRequest->setUserResolver(fn () => $user);
        $transcript = app(GradeController::class)->transcript($transcriptRequest)->getData(true);

        $this->assertCount(1, $transcript['grades']);
        $this->assertSame('A', $transcript['grades'][0]['letter_grade']);
        $this->assertEquals(3.25, $transcript['ipk']);
    }

    public function test_students_can_update_only_their_own_biodata_fields(): void
    {
        $user = User::findOrFail(1);
        $request = Request::create('/api/students/me/profile', 'PUT', [
            'student' => [
                'name' => 'Nama Diperbarui',
                'gender' => 'P',
                'birth_place' => 'Bandung',
                'birth_date' => '2004-02-03',
                'email' => 'm001-new@example.test',
                'phone' => '08123456789',
                'home_phone' => '022123456',
                'nim' => 'DIUBAH',
                'status' => 'Lulus',
                'study_program_id' => 999,
            ],
            'profile' => ['nik' => '3200000000000001', 'religion' => 'Islam'],
            'address' => ['address' => 'Jl. Contoh', 'city' => 'Bandung'],
            'parents' => [
                'father' => ['name' => 'Ayah Baru', 'phone' => '0811111111'],
                'mother' => ['name' => 'Ibu Baru'],
                'guardian' => ['name' => 'Wali Baru'],
            ],
            'education' => ['institution_name' => 'SMK Contoh', 'graduation_year' => 2022],
            'documents' => ['diploma_link' => 'https://example.test/ijazah'],
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(StudentController::class)->updateMyProfile($request)->getData(true);

        $this->assertSame('Biodata berhasil diperbarui.', $response['message']);
        $this->assertSame('Nama Diperbarui', DB::table('students')->where('id', 1)->value('name'));
        $this->assertSame('m001-new@example.test', DB::table('users')->where('id', 1)->value('email'));
        $this->assertSame('M001', DB::table('students')->where('id', 1)->value('nim'));
        $this->assertSame('Aktif', DB::table('students')->where('id', 1)->value('status'));
        $this->assertSame(1, DB::table('students')->where('id', 1)->value('study_program_id'));
        $this->assertSame('3200000000000001', DB::table('student_profiles')->where('student_id', 1)->value('nik'));
        $this->assertSame('Bandung', DB::table('student_addresses')->where('student_id', 1)->value('city'));
        $this->assertSame('Ayah Baru', DB::table('student_parents')->where('student_id', 1)->where('relation', 'Ayah')->value('name'));
        $this->assertSame('SMK Contoh', DB::table('student_education_histories')->where('student_id', 1)->value('institution_name'));
        $this->assertSame('https://example.test/ijazah', DB::table('student_documents')->where('student_id', 1)->value('file_url'));
        $this->assertSame('2004-02-03', $response['data']['student']['birth_date']);
        $this->assertSame('https://example.test/ijazah', $response['data']['documents']['diploma_link']);
        $this->assertSame('Mahasiswa Dua', DB::table('students')->where('id', 2)->value('name'));
    }

    public function test_admin_can_read_and_update_student_biodata_and_document_links(): void
    {
        DB::table('student_documents')->insert([
            'student_id' => 1,
            'type' => 'IJAZAH',
            'name' => 'Ijazah',
            'file_url' => 'https://example.test/ijazah-lama',
            'is_verified' => true,
            'verified_by' => 3,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::findOrFail(3);
        $showRequest = Request::create('/api/students/1/biodata', 'GET');
        $showRequest->setUserResolver(fn () => $admin);
        $profile = app(StudentController::class)->showBiodata($showRequest, Student::findOrFail(1))->getData(true);
        $this->assertSame('https://example.test/ijazah-lama', $profile['documents']['diploma_link']);

        $updateRequest = Request::create('/api/students/1/biodata', 'PUT', [
            'student' => [
                'name' => 'Nama dari Admin',
                'email' => 'm001-admin@example.test',
                'birth_date' => '2004-02-03',
            ],
            'profile' => ['nik' => '3200000000000001', 'blood_type' => 'O', 'nationality' => null],
            'address' => ['city' => 'Bandung', 'province' => 'Jawa Barat'],
            'parents' => [
                'father' => ['name' => 'Ayah Admin', 'nik' => '3200000000000002', 'birth_date' => '1970-01-01', 'phone' => '08123456789', 'is_alive' => true],
                'mother' => ['name' => 'Ibu Admin', 'is_alive' => true],
                'guardian' => ['name' => '', 'is_alive' => true],
            ],
            'education' => ['institution_name' => 'SMA Admin', 'diploma_number' => 'DN-123'],
            'documents' => ['diploma_link' => 'https://example.test/ijazah-baru'],
        ]);
        $updateRequest->setUserResolver(fn () => $admin);

        $response = app(StudentController::class)->updateBiodata($updateRequest, Student::findOrFail(1))->getData(true);

        $this->assertSame('Nama dari Admin', DB::table('students')->where('id', 1)->value('name'));
        $this->assertSame('m001-admin@example.test', DB::table('users')->where('id', 1)->value('email'));
        $this->assertSame('Aktif', DB::table('students')->where('id', 1)->value('status'));
        $this->assertSame('O', DB::table('student_profiles')->where('student_id', 1)->value('blood_type'));
        $this->assertSame('Indonesia', DB::table('student_profiles')->where('student_id', 1)->value('nationality'));
        $this->assertSame('Bandung', DB::table('student_addresses')->where('student_id', 1)->value('city'));
        $this->assertSame('3200000000000002', DB::table('student_parents')->where('student_id', 1)->where('relation', 'Ayah')->value('nik'));
        $this->assertStringStartsWith('1970-01-01', DB::table('student_parents')->where('student_id', 1)->where('relation', 'Ayah')->value('birth_date'));
        $this->assertSame('08123456789', DB::table('student_parents')->where('student_id', 1)->where('relation', 'Ayah')->value('phone'));
        $this->assertSame('SMA Admin', DB::table('student_education_histories')->where('student_id', 1)->value('institution_name'));
        $this->assertSame('https://example.test/ijazah-baru', DB::table('student_documents')->where('student_id', 1)->value('file_url'));
        $this->assertFalse((bool) DB::table('student_documents')->where('student_id', 1)->value('is_verified'));
        $this->assertSame('https://example.test/ijazah-baru', $response['data']['documents']['diploma_link']);
    }

    public function test_existing_contact_emails_owned_by_another_account_do_not_block_biodata_edits(): void
    {
        DB::table('users')->insert([
            [
                'id' => 5, 'name' => 'Akun Lain', 'username' => 'other.user',
                'email' => 'existing-contact@example.test', 'password' => bcrypt('secret'), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 6, 'name' => 'Akun Lain Dua', 'username' => 'other.user.two',
                'email' => 'unlinked-contact@example.test', 'password' => bcrypt('secret'), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('students')->where('id', 1)->update(['email' => 'existing-contact@example.test']);
        DB::table('students')->where('id', 2)->update(['email' => 'unlinked-contact@example.test']);

        $studentRequest = Request::create('/api/students/me/profile', 'PUT', [
            'student' => ['name' => 'Mahasiswa Satu Diperbarui', 'email' => 'existing-contact@example.test'],
            'parents' => ['father' => ['name' => null], 'mother' => ['name' => null], 'guardian' => ['name' => null]],
        ]);
        $studentRequest->setUserResolver(fn () => User::findOrFail(1));
        $studentResponse = app(StudentController::class)->updateMyProfile($studentRequest)->getData(true);

        $this->assertStringContainsString('tidak mengubah email login', $studentResponse['message']);
        $this->assertSame('existing-contact@example.test', DB::table('students')->where('id', 1)->value('email'));
        $this->assertSame('Mahasiswa Satu Diperbarui', DB::table('users')->where('id', 1)->value('name'));
        $this->assertSame('m001@example.test', DB::table('users')->where('id', 1)->value('email'));

        $adminRequest = Request::create('/api/students/2/biodata', 'PUT', [
            'student' => ['name' => 'Mahasiswa Dua Diperbarui', 'email' => 'unlinked-contact@example.test'],
            'parents' => ['father' => ['name' => null], 'mother' => ['name' => null], 'guardian' => ['name' => null]],
        ]);
        $adminRequest->setUserResolver(fn () => User::findOrFail(3));
        $adminResponse = app(StudentController::class)
            ->updateBiodata($adminRequest, Student::findOrFail(2))
            ->getData(true);

        $this->assertStringContainsString('tidak mengubah email login', $adminResponse['message']);
        $this->assertSame('unlinked-contact@example.test', DB::table('students')->where('id', 2)->value('email'));
        $this->assertSame('unlinked-contact@example.test', DB::table('users')->where('id', 6)->value('email'));
    }

    public function test_changing_biodata_email_to_another_accounts_email_has_a_clear_validation_message(): void
    {
        DB::table('users')->insert([
            'id' => 5, 'name' => 'Akun Lain', 'username' => 'other.user',
            'email' => 'taken@example.test', 'password' => bcrypt('secret'), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('students')->where('id', 1)->update(['email' => 'old-contact@example.test']);

        $request = Request::create('/api/students/me/profile', 'PUT', [
            'student' => ['name' => 'Mahasiswa Satu', 'email' => 'taken@example.test'],
            'parents' => ['father' => ['name' => null], 'mother' => ['name' => null], 'guardian' => ['name' => null]],
        ]);
        $request->setUserResolver(fn () => User::findOrFail(1));

        try {
            app(StudentController::class)->updateMyProfile($request);
            $this->fail('An email already assigned to another account must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Email tersebut sudah digunakan akun lain. Gunakan email yang berbeda.'],
                $exception->errors()['student.email'],
            );
        }

        $this->assertSame('old-contact@example.test', DB::table('students')->where('id', 1)->value('email'));
        $this->assertSame('m001@example.test', DB::table('users')->where('id', 1)->value('email'));
    }

    public function test_program_leader_cannot_manage_biodata_outside_program_scope(): void
    {
        $request = Request::create('/api/students/3/biodata', 'GET');
        $request->setUserResolver(fn () => User::findOrFail(4));

        try {
            app(StudentController::class)->showBiodata($request, Student::findOrFail(3));
            $this->fail('A program leader must not access biodata outside their program.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_admin_can_update_or_clear_a_student_advisor(): void
    {
        $user = User::findOrFail(3);
        $request = Request::create('/api/students/1/advisor', 'PATCH', ['advisor_id' => 2]);
        $request->setUserResolver(fn () => $user);

        $response = app(StudentController::class)->updateAdvisor($request, Student::findOrFail(1))->getData(true);

        $this->assertSame('Pembimbing akademik berhasil diperbarui.', $response['message']);
        $this->assertSame(2, DB::table('students')->where('id', 1)->value('advisor_id'));

        $clearRequest = Request::create('/api/students/1/advisor', 'PATCH', ['advisor_id' => null]);
        $clearRequest->setUserResolver(fn () => $user);
        app(StudentController::class)->updateAdvisor($clearRequest, Student::findOrFail(1));

        $this->assertNull(DB::table('students')->where('id', 1)->value('advisor_id'));
    }

    public function test_prodi_advisor_options_are_scoped_and_search_is_grouped(): void
    {
        $user = User::findOrFail(4);
        $request = Request::create('/api/students/advisor-options', 'GET', [
            'search' => 'Mahasiswa',
            'per_page' => 50,
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(StudentController::class)->advisorOptions($request)->getData(true);

        $this->assertSame(['M002', 'M001'], array_column($response['data'], 'nim'));

        $outsideProgramRequest = Request::create('/api/students/advisor-options', 'GET', [
            'search' => 'Prodi Lain',
        ]);
        $outsideProgramRequest->setUserResolver(fn () => $user);

        $outsideProgramResponse = app(StudentController::class)->advisorOptions($outsideProgramRequest)->getData(true);

        $this->assertSame([], $outsideProgramResponse['data']);
    }

    public function test_program_leader_can_update_advisor_only_within_their_program_scope(): void
    {
        $user = User::findOrFail(4);
        $request = Request::create('/api/students/1/advisor', 'PATCH', ['advisor_id' => 2]);
        $request->setUserResolver(fn () => $user);

        app(StudentController::class)->updateAdvisor($request, Student::findOrFail(1));
        $this->assertSame(2, DB::table('students')->where('id', 1)->value('advisor_id'));

        $outsideScopeRequest = Request::create('/api/students/3/advisor', 'PATCH', ['advisor_id' => 2]);
        $outsideScopeRequest->setUserResolver(fn () => $user);

        try {
            app(StudentController::class)->updateAdvisor($outsideScopeRequest, Student::findOrFail(3));
            $this->fail('A program leader must not update students outside their program.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull(DB::table('students')->where('id', 3)->value('advisor_id'));
    }

    public function test_family_card_document_link_round_trips_for_legacy_and_current_types(): void
    {
        $user = User::findOrFail(1);
        DB::table('student_documents')->insert([
            'student_id' => 1, 'type' => 'KK', 'name' => 'Kartu Keluarga',
            'file_url' => 'https://example.test/kk-old', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request = Request::create('/api/students/me/profile', 'PUT', [
            'student' => ['name' => 'Mahasiswa Satu', 'email' => 'm001@example.test'],
            'parents' => ['father' => ['name' => 'Ayah']],
            'documents' => ['family_card_link' => 'https://example.test/kk-new'],
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(StudentController::class)->updateMyProfile($request)->getData(true);

        $this->assertSame(1, DB::table('student_documents')->where('student_id', 1)->count());
        $this->assertSame('KK', DB::table('student_documents')->where('student_id', 1)->value('type'));
        $this->assertSame('https://example.test/kk-new', $response['data']['documents']['family_card_link']);
    }

    public function test_pdf_and_admin_excel_exports_are_valid_and_protected(): void
    {
        $studentUser = User::findOrFail(1);
        $adminUser = User::findOrFail(3);

        $khsRequest = Request::create('/api/grades/khs/pdf', 'GET', ['semester_id' => 1]);
        $khsRequest->setUserResolver(fn () => $studentUser);
        $khsResponse = app(GradeController::class)->khsPdf($khsRequest);
        $khsBytes = (string) $khsResponse->getContent();
        $this->assertStringStartsWith('%PDF-', $khsBytes);
        $this->assertStringContainsString('KHS-M001-Ganjil-2020-2021.pdf', (string) $khsResponse->headers->get('content-disposition'));

        $transcriptRequest = Request::create('/api/grades/transcript/pdf', 'GET');
        $transcriptRequest->setUserResolver(fn () => $studentUser);
        $transcriptResponse = app(GradeController::class)->transcriptPdf($transcriptRequest);
        $transcriptBytes = (string) $transcriptResponse->getContent();
        $this->assertStringStartsWith('%PDF-', $transcriptBytes);

        $excelRequest = Request::create('/api/grades/transcript/excel', 'GET', ['student_id' => 1]);
        $excelRequest->setUserResolver(fn () => $adminUser);
        $excelResponse = app(GradeController::class)->transcriptExcel($excelRequest);
        $this->assertInstanceOf(BinaryFileResponse::class, $excelResponse);
        $excelBytes = file_get_contents($excelResponse->getFile()->getPathname());
        $this->assertIsString($excelBytes);
        $this->assertStringStartsWith('PK', $excelBytes);

        $this->actingAs($studentUser)
            ->get('/api/grades/transcript/excel?student_id=1')
            ->assertForbidden();

        DB::table('student_grades')->where('student_id', 1)->delete();
        $emptyExcelResponse = app(GradeController::class)->transcriptExcel($excelRequest);
        $this->assertStringStartsWith('PK', file_get_contents($emptyExcelResponse->getFile()->getPathname()));
    }

    public function test_academic_document_token_verifies_current_data_and_rejects_changes(): void
    {
        $student = Student::with(['studyProgram.faculty', 'studyProgram.headLecturer'])->findOrFail(1);
        $grades = StudentGrade::where('student_id', 1)->get();
        $token = AcademicDocumentVerification::issue('khs', $student, $grades, 1);
        $this->assertNotNull(AcademicDocumentVerification::decode(str_replace('_', '.', $token)));

        $request = Request::create('/api/verify/khs/'.$token, 'GET', ['signer' => 'kaprodi']);
        $verified = app(VerifyController::class)->verifyKhs($request, $token);
        $verifiedData = $verified->getData(true);

        $this->assertTrue($verifiedData['valid']);
        $this->assertSame('M001', $verifiedData['student']['nim']);
        $this->assertTrue($verifiedData['signer_info']['signed']);

        $advisorRequest = Request::create('/api/verify/khs/'.$token, 'GET', ['signer' => 'dosen_wali']);
        $advisorData = app(VerifyController::class)->verifyKhs($advisorRequest, $token)->getData(true);
        $this->assertSame('Dosen Pembimbing Akademik', $advisorData['signer_info']['label']);
        $this->assertSame('Dr. Ahmad Fauzi, M.Pd.', $advisorData['signer_info']['name']);
        $this->assertTrue($advisorData['signer_info']['signed']);

        $studentRequest = Request::create('/api/verify/khs/'.$token, 'GET', ['signer' => 'mahasiswa']);
        $studentData = app(VerifyController::class)->verifyKhs($studentRequest, $token)->getData(true);
        $this->assertSame('Mahasiswa Satu', $studentData['signer_info']['name']);
        $this->assertTrue($studentData['signer_info']['signed']);

        $transcriptToken = AcademicDocumentVerification::issue('transcript', $student, $grades);
        $wk1Request = Request::create('/api/verify/transcript/'.$transcriptToken, 'GET', ['signer' => 'waket1']);
        $wk1Data = app(VerifyController::class)->verifyTranscript($wk1Request, $transcriptToken)->getData(true);
        $this->assertSame('Wakil Ketua I', $wk1Data['signer_info']['label']);
        $this->assertSame('Dr. Siti Rahmawati, M.Pd.', $wk1Data['signer_info']['name']);
        $this->assertTrue($wk1Data['signer_info']['signed']);

        DB::table('student_grades')->where('student_id', 1)->update([
            'grade_point' => 3.5,
            'updated_at' => now()->addMinute(),
        ]);
        $obsolete = app(VerifyController::class)->verifyKhs($request, $token);

        $this->assertSame(409, $obsolete->getStatusCode());
        $this->assertFalse($obsolete->getData(true)['valid']);
        $this->assertNull(AcademicDocumentVerification::decode($token.'tampered'));
    }

    private function createMinimalSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
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
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->unsignedBigInteger('head_lecturer_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('level')->nullable();
        });
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('letterhead_path')->nullable();
            $table->timestamps();
        });
        Schema::create('faculties', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nidn')->nullable();
            $table->string('degree_front')->nullable();
            $table->string('degree_back')->nullable();
            $table->string('full_name');
            $table->boolean('status')->default(true);
        });
        Schema::create('lecturer_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lecturer_id');
            $table->string('position_code');
            $table->string('position_name');
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('home_phone')->nullable();
            $table->string('status');
            $table->unsignedInteger('entry_year')->nullable();
            $table->unsignedInteger('current_semester')->nullable();
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
        Schema::create('student_parents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('relation');
            $table->string('name');
            $table->string('nik')->nullable();
            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('occupation')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('education')->nullable();
            $table->string('income')->nullable();
            $table->boolean('is_alive')->default(true);
            $table->timestamps();
            $table->unique(['student_id', 'relation']);
        });
        Schema::create('student_education_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('level');
            $table->string('institution_name');
            $table->string('institution_address')->nullable();
            $table->string('major')->nullable();
            $table->integer('graduation_year')->nullable();
            $table->string('diploma_number')->nullable();
            $table->timestamps();
        });
        Schema::create('student_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('type');
            $table->string('name');
            $table->string('file_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('document_number')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
        });
        Schema::create('student_semester_summaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id');
            $table->string('status');
            $table->decimal('semester_gpa', 4, 2)->nullable();
            $table->decimal('cumulative_gpa', 4, 2)->nullable();
            $table->unsignedSmallInteger('credit_limit')->nullable();
            $table->unsignedSmallInteger('credits_taken')->nullable();
            $table->unsignedSmallInteger('required_credits')->nullable();
            $table->unsignedSmallInteger('elective_credits')->nullable();
            $table->unsignedSmallInteger('total_credits')->nullable();
            $table->timestamps();
        });
        Schema::create('student_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->string('status');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('reason')->nullable();
            $table->string('decree_number')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('credits');
        });
        Schema::create('student_grades', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('semester_id');
            $table->string('letter_grade')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->decimal('grade_point', 3, 2)->nullable();
            $table->timestamps();
        });
    }
}
