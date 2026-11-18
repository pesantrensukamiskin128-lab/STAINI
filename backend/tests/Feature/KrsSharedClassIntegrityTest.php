<?php

namespace Tests\Feature;

use App\Exports\KrsExport;
use App\Http\Controllers\Api\KrsController;
use App\Models\Krs;
use App\Models\KrsDetail;
use App\Services\KrsEnrollmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KrsSharedClassIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->string('nim')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('Aktif');
            $table->unsignedInteger('entry_year')->nullable();
            $table->unsignedInteger('current_semester')->nullable();
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('credits');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('capacity')->default(40);
            $table->unsignedInteger('academic_level')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('class_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('curriculum_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();
        });
        Schema::create('krs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->unsignedInteger('total_credits')->default(0);
            $table->string('status')->default('DRAFT');
            $table->text('advisor_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('kaprodi_approved_at')->nullable();
            $table->unsignedBigInteger('kaprodi_approved_by')->nullable();
            $table->text('kaprodi_note')->nullable();
            $table->string('rejected_by_stage', 20)->nullable();
            $table->timestamp('signed_by_kaprodi_at')->nullable();
            $table->unsignedBigInteger('signed_by_kaprodi_id')->nullable();
            $table->timestamps();
        });
        Schema::create('lecturer_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lecturer_id');
            $table->string('position_code');
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('krs_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('krs_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('status')->default('AKTIF');
            $table->timestamps();
        });
        Schema::create('class_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');
            $table->timestamps();
        });
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->string('day');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('students')->insert([
            ['id' => 1, 'study_program_id' => 1, 'nim' => 'MHS-001', 'name' => 'Mahasiswa Satu'],
            ['id' => 2, 'study_program_id' => 2, 'nim' => 'MHS-002', 'name' => 'Mahasiswa Dua'],
        ]);
        DB::table('courses')->insert([
            ['id' => 1, 'study_program_id' => 1, 'code' => 'A-1', 'name' => 'Mata Kuliah A', 'credits' => 2],
            ['id' => 2, 'study_program_id' => 1, 'code' => 'A-2', 'name' => 'Mata Kuliah B', 'credits' => 2],
            ['id' => 3, 'study_program_id' => 2, 'code' => 'B-1', 'name' => 'Mata Kuliah C', 'credits' => 2],
        ]);
        DB::table('semesters')->insert([['id' => 1], ['id' => 2]]);
        DB::table('classes')->insert([
            ['id' => 1, 'study_program_id' => 1, 'semester_id' => 1, 'course_id' => 1, 'name' => 'Gabungan A', 'capacity' => 40, 'is_active' => true],
            ['id' => 2, 'study_program_id' => 1, 'semester_id' => 2, 'course_id' => 1, 'name' => 'Semester Lain', 'capacity' => 40, 'is_active' => true],
            ['id' => 3, 'study_program_id' => 2, 'semester_id' => 1, 'course_id' => 3, 'name' => 'Kelas Prodi B', 'capacity' => 40, 'is_active' => true],
        ]);
        DB::table('class_courses')->insert([
            ['class_id' => 1, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 1, 'course_id' => 3, 'is_primary' => false],
            ['class_id' => 2, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 3, 'course_id' => 3, 'is_primary' => true],
        ]);
        DB::table('krs')->insert([
            ['id' => 1, 'student_id' => 1, 'semester_id' => 1, 'status' => 'DRAFT'],
            ['id' => 2, 'student_id' => 2, 'semester_id' => 1, 'status' => 'DRAFT'],
        ]);
    }

    public function test_krs_list_and_export_filter_student_semester_and_entry_year(): void
    {
        Schema::create('study_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        DB::table('study_programs')->insert([
            ['id' => 1, 'code' => 'P1', 'name' => 'Prodi Satu'],
            ['id' => 2, 'code' => 'P2', 'name' => 'Prodi Dua'],
        ]);
        DB::table('students')->where('id', 1)->update(['entry_year' => 2024, 'current_semester' => 5]);
        DB::table('students')->where('id', 2)->update(['entry_year' => 2023, 'current_semester' => 5]);
        DB::table('krs')->insert(['id' => 3, 'student_id' => 1, 'semester_id' => 2, 'status' => 'DRAFT']);

        $user = new class {
            public bool $admin = true;
            public object $lecturer;
            public function __construct() { $this->lecturer = (object) ['id' => 8]; }
            public function hasRole(string $role): bool { return false; }
            public function hasAnyRole(array $roles): bool { return $this->admin; }
        };
        Auth::shouldReceive('user')->andReturn($user);
        $controller = app(KrsController::class);
        $params = ['semester_id' => 1, 'current_semester' => 5, 'entry_year' => 2024, 'search' => 'MHS'];
        $yearOnly = $controller->index(Request::create('/api/krs', 'GET', ['entry_year' => 2024]))->getData(true);
        $semesterOnly = $controller->index(Request::create('/api/krs', 'GET', ['current_semester' => 5, 'semester_id' => 1]))->getData(true);
        $result = $controller->index(Request::create('/api/krs', 'GET', $params))->getData(true);
        $this->assertSame(2, $yearOnly['total']);
        $this->assertSame(2, $semesterOnly['total']);
        $this->assertSame([1], array_column($result['data'], 'id'));
        $this->assertSame(['MHS-001'], array_column((new KrsExport(1, null, null, 'MHS', 2024, 5))->array(), 0));
        $this->assertSame([], array_column((new KrsExport(1, [2], null, null, 2024, 5))->array(), 0));

        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8, 'position_code' => 'KAPRODI', 'scope_type' => 'study_program',
            'scope_id' => 2, 'is_active' => true,
        ]);
        $user->admin = false;
        $scoped = $controller->index(Request::create('/api/krs', 'GET', $params))->getData(true);
        $this->assertSame(0, $scoped['total']);
    }

    public function test_rejects_a_class_that_does_not_offer_the_selected_course(): void
    {
        $response = $this->addCourse(1, 2, 1);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Mata kuliah tidak ditawarkan pada kelas yang dipilih.', $response->getData(true)['message']);
    }

    public function test_rejects_a_class_from_another_semester(): void
    {
        $response = $this->addCourse(1, 1, 2);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Kelas tidak berada pada semester KRS yang dipilih.', $response->getData(true)['message']);
    }

    public function test_rejects_a_course_from_another_study_program(): void
    {
        $response = $this->addCourse(1, 3, 1);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Mata kuliah tidak berasal dari program studi mahasiswa.', $response->getData(true)['message']);
    }

    public function test_removing_one_course_keeps_membership_when_another_course_uses_the_same_class(): void
    {
        DB::table('class_courses')->insert([
            'class_id' => 1,
            'course_id' => 2,
            'is_primary' => false,
        ]);
        $this->assertSame(200, $this->addCourse(1, 1, 1)->getStatusCode());
        $this->assertSame(200, $this->addCourse(1, 2, 1)->getStatusCode());

        request()->setUserResolver(fn () => new class
        {
            public function hasRole(string $role): bool
            {
                return false;
            }

            public function hasAnyRole(array $roles): bool
            {
                return true;
            }
        });

        $detail = KrsDetail::where('krs_id', 1)->where('course_id', 1)->firstOrFail();
        $response = app(KrsController::class)->removeCourse(Krs::findOrFail(1), $detail);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('class_members', ['class_id' => 1, 'student_id' => 1]);
    }

    public function test_accepts_a_matching_course_and_class(): void
    {
        $response = $this->addCourse(1, 1, 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('krs_details', ['krs_id' => 1, 'course_id' => 1, 'class_id' => 1]);
        $this->assertDatabaseHas('class_members', ['class_id' => 1, 'student_id' => 1]);
    }

    public function test_student_can_take_their_own_program_course_in_a_class_owned_by_another_program(): void
    {
        $response = $this->addCourse(2, 3, 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('krs_details', ['krs_id' => 2, 'course_id' => 3, 'class_id' => 1]);
        $this->assertDatabaseHas('class_members', ['class_id' => 1, 'student_id' => 2]);
    }

    public function test_study_program_secretary_can_approve_krs_after_advisor_approval(): void
    {
        DB::table('krs')->where('id', 1)->update(['status' => 'APPROVED']);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8,
            'position_code' => 'SEKPRODI',
            'scope_type' => 'study_program',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $request = Request::create('/api/krs/1/approve-kaprodi', 'POST');
        $request->setUserResolver(fn () => $this->reviewer(8));
        $response = app(KrsController::class)->approveByKaprodi($request, Krs::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('krs', [
            'id' => 1,
            'status' => 'APPROVED',
            'kaprodi_approved_by' => 99,
        ]);
        $this->assertNotNull(DB::table('krs')->where('id', 1)->value('kaprodi_approved_at'));
    }

    public function test_prodi_can_approve_submitted_krs_without_an_advisor(): void
    {
        DB::table('krs')->where('id', 1)->update([
            'status' => 'SUBMITTED',
            'advisor_id' => null,
        ]);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8,
            'position_code' => 'KAPRODI',
            'scope_type' => 'study_program',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $request = Request::create('/api/krs/1/approve-kaprodi', 'POST');
        $request->setUserResolver(fn () => $this->reviewer(8));
        $response = app(KrsController::class)->approveByKaprodi($request, Krs::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('krs', [
            'id' => 1,
            'status' => 'APPROVED',
            'advisor_id' => null,
            'approved_at' => null,
            'kaprodi_approved_by' => 99,
        ]);
        $this->assertNotNull(DB::table('krs')->where('id', 1)->value('kaprodi_approved_at'));
    }

    public function test_prodi_can_reject_submitted_krs_without_an_advisor(): void
    {
        DB::table('krs')->where('id', 1)->update([
            'status' => 'SUBMITTED',
            'advisor_id' => null,
        ]);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8,
            'position_code' => 'SEKPRODI',
            'scope_type' => 'study_program',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $request = Request::create('/api/krs/1/reject-kaprodi', 'POST', ['note' => 'Lengkapi pilihan mata kuliah.']);
        $request->setUserResolver(fn () => $this->reviewer(8));
        $response = app(KrsController::class)->rejectByKaprodi($request, Krs::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('krs', [
            'id' => 1,
            'status' => 'REJECTED',
            'advisor_id' => null,
            'kaprodi_note' => 'Lengkapi pilihan mata kuliah.',
            'rejected_by_stage' => 'KAPRODI',
        ]);
    }

    public function test_prodi_cannot_skip_advisor_approval_when_an_advisor_is_assigned(): void
    {
        DB::table('krs')->where('id', 1)->update([
            'status' => 'SUBMITTED',
            'advisor_id' => 7,
        ]);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8,
            'position_code' => 'KAPRODI',
            'scope_type' => 'study_program',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $request = Request::create('/api/krs/1/approve-kaprodi', 'POST');
        $request->setUserResolver(fn () => $this->reviewer(8));
        $response = app(KrsController::class)->approveByKaprodi($request, Krs::findOrFail(1));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('krs', [
            'id' => 1,
            'status' => 'SUBMITTED',
            'kaprodi_approved_at' => null,
        ]);
    }

    public function test_study_program_rejection_records_stage_and_note(): void
    {
        DB::table('krs')->where('id', 1)->update(['status' => 'APPROVED']);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8,
            'position_code' => 'KAPRODI',
            'scope_type' => 'study_program',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $request = Request::create('/api/krs/1/reject-kaprodi', 'POST', ['note' => 'Perbaiki pilihan mata kuliah.']);
        $request->setUserResolver(fn () => $this->reviewer(8));
        $response = app(KrsController::class)->rejectByKaprodi($request, Krs::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('krs', [
            'id' => 1,
            'status' => 'REJECTED',
            'kaprodi_note' => 'Perbaiki pilihan mata kuliah.',
            'rejected_by_stage' => 'KAPRODI',
        ]);
    }

    public function test_study_program_reviewer_cannot_review_another_programs_krs(): void
    {
        DB::table('krs')->where('id', 2)->update(['status' => 'APPROVED']);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 8,
            'position_code' => 'SEKPRODI',
            'scope_type' => 'study_program',
            'scope_id' => 1,
            'is_active' => true,
        ]);

        $request = Request::create('/api/krs/2/approve-kaprodi', 'POST');
        $request->setUserResolver(fn () => $this->reviewer(8));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(KrsController::class)->approveByKaprodi($request, Krs::findOrFail(2));
    }

    public function test_krs_cannot_be_signed_before_program_approval(): void
    {
        DB::table('krs')->where('id', 1)->update(['status' => 'APPROVED']);
        $user = $this->reviewer(null, ['ADMIN_AKADEMIK']);
        request()->setUserResolver(fn () => $user);

        $response = app(KrsController::class)->signByKaprodi(Krs::findOrFail(1));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('krs', ['id' => 1, 'signed_by_kaprodi_at' => null]);
    }

    public function test_signed_krs_has_a_display_status_without_changing_its_workflow_status(): void
    {
        DB::table('krs')->where('id', 1)->update([
            'status' => 'APPROVED',
            'kaprodi_approved_at' => now(),
            'signed_by_kaprodi_at' => now(),
        ]);
        DB::table('krs')->where('id', 2)->update([
            'status' => 'APPROVED',
            'kaprodi_approved_at' => now(),
        ]);

        $signed = Krs::findOrFail(1);
        $prodiApproved = Krs::findOrFail(2);

        $this->assertSame('APPROVED', $signed->status);
        $this->assertSame('DITANDATANGANI', $signed->display_status);
        $this->assertSame('Ditandatangani', $signed->display_status_label);
        $this->assertSame('DISETUJUI_PRODI', $prodiApproved->display_status);
        $this->assertSame('Disetujui Prodi', $prodiApproved->display_status_label);
        $this->assertSame([1], Krs::query()->whereDisplayStatus('DITANDATANGANI')->pluck('id')->all());
        $this->assertSame([2], Krs::query()->whereDisplayStatus('DISETUJUI_PRODI')->pluck('id')->all());

        DB::table('krs')->where('id', 2)->update([
            'status' => 'REJECTED',
            'kaprodi_approved_at' => null,
            'rejected_by_stage' => 'KAPRODI',
        ]);
        $prodiRejected = Krs::findOrFail(2);
        $this->assertSame('DITOLAK_PRODI', $prodiRejected->display_status);
        $this->assertSame('Ditolak Prodi', $prodiRejected->display_status_label);
        $this->assertSame([2], Krs::query()->whereDisplayStatus('DITOLAK_PRODI')->pluck('id')->all());
    }

    public function test_admin_can_import_krs_for_multiple_students_with_class_validation(): void
    {
        $csv = implode("\n", [
            'nim,nama_mahasiswa,kode_prodi,id_semester,nama_semester,kode_mata_kuliah,id_kelas,nama_kelas,status_krs,total_sks',
            'MHS-001,Mahasiswa Satu,,1,,A-1,1,Gabungan A,DRAFT,',
        ]);
        $file = UploadedFile::fake()->createWithContent('krs.csv', $csv);
        $request = Request::create('/api/krs/import', 'POST', [], [], ['file' => $file]);
        $request->setUserResolver(fn () => new class
        {
            public function hasAnyRole(array $roles): bool
            {
                return true;
            }
        });

        $response = app(KrsController::class)->import($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['processed']);
        $this->assertDatabaseHas('krs_details', ['krs_id' => 1, 'course_id' => 1, 'class_id' => 1]);
    }

    public function test_submit_rejects_krs_with_overlapping_active_class_schedules(): void
    {
        DB::table('classes')->insert([
            ['id' => 4, 'study_program_id' => 1, 'semester_id' => 1, 'course_id' => 2, 'name' => 'Kelas B', 'capacity' => 40, 'is_active' => true],
        ]);
        DB::table('krs_details')->insert([
            ['krs_id' => 1, 'course_id' => 1, 'class_id' => 1, 'status' => 'AKTIF'],
            ['krs_id' => 1, 'course_id' => 2, 'class_id' => 4, 'status' => 'AKTIF'],
        ]);
        DB::table('schedules')->insert([
            ['class_id' => 1, 'day' => 'Senin', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'is_active' => true],
            ['class_id' => 4, 'day' => 'Senin', 'start_time' => '09:30:00', 'end_time' => '10:30:00', 'is_active' => true],
        ]);
        DB::table('krs')->where('id', 1)->update(['total_credits' => 4]);

        request()->setUserResolver(fn () => new class
        {
            public function hasRole(string $role): bool
            {
                return false;
            }

            public function hasAnyRole(array $roles): bool
            {
                return true;
            }
        });

        $response = app(KrsController::class)->submit(Krs::findOrFail(1));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Jadwal mata kuliah', $response->getData(true)['message']);
        $this->assertCount(1, $response->getData(true)['schedule_conflicts']);
        $this->assertDatabaseHas('krs', ['id' => 1, 'status' => 'DRAFT']);
    }

    public function test_schedule_conflicts_ignore_different_days_and_touching_time_boundaries(): void
    {
        DB::table('classes')->insert([
            ['id' => 4, 'study_program_id' => 1, 'semester_id' => 1, 'course_id' => 2, 'name' => 'Kelas B', 'capacity' => 40, 'is_active' => true],
        ]);
        DB::table('krs_details')->insert([
            ['krs_id' => 1, 'course_id' => 1, 'class_id' => 1, 'status' => 'AKTIF'],
            ['krs_id' => 1, 'course_id' => 2, 'class_id' => 4, 'status' => 'AKTIF'],
        ]);
        DB::table('schedules')->insert([
            ['class_id' => 1, 'day' => 'Senin', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'is_active' => true],
            ['class_id' => 4, 'day' => 'Senin', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'is_active' => true],
            ['class_id' => 4, 'day' => 'Selasa', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'is_active' => true],
            ['class_id' => 4, 'day' => 'Senin', 'start_time' => '09:15:00', 'end_time' => '09:45:00', 'is_active' => false],
        ]);

        $conflicts = app(KrsEnrollmentService::class)->scheduleConflicts(Krs::findOrFail(1));

        $this->assertSame([], $conflicts);
    }

    private function addCourse(int $krsId, int $courseId, int $classId)
    {
        $request = Request::create('/api/krs/'.$krsId.'/courses', 'POST', [
            'course_id' => $courseId,
            'class_id' => $classId,
        ]);
        $request->setUserResolver(fn () => new class
        {
            public function hasRole(string $role): bool
            {
                return false;
            }

            public function hasAnyRole(array $roles): bool
            {
                return true;
            }
        });

        return app(KrsController::class)->addCourse($request, Krs::findOrFail($krsId));
    }

    private function reviewer(?int $lecturerId, array $roles = []): object
    {
        return new class($lecturerId, $roles)
        {
            public ?object $lecturer;
            public int $id = 99;

            public function __construct(?int $lecturerId, private array $roles)
            {
                $this->lecturer = $lecturerId ? (object) ['id' => $lecturerId] : null;
            }

            public function hasAnyRole(array $roles): bool
            {
                return count(array_intersect($this->roles, $roles)) > 0;
            }
        };
    }
}
