<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LectureController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\AssignmentTemplateController;
use App\Http\Controllers\Api\HonorController;
use App\Models\ClassModel;
use App\Models\ClassGradeWeight;
use App\Models\Discussion;
use App\Models\LectureJournal;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LectureJournalAttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });
        Schema::create('assignment_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('type', 20);
            $table->unsignedInteger('max_score')->default(100);
            $table->boolean('allow_late')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
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
        Schema::create('class_lecturers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('lecturer_id');
            $table->unsignedInteger('teaching_order')->default(1);
            $table->unsignedInteger('planned_meetings')->nullable();
            $table->unsignedInteger('actual_meetings')->nullable();
            $table->boolean('can_input_grades')->default(true);
            $table->decimal('teaching_credits', 5, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('nim');
            $table->string('name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('study_program_id')->nullable();
        });
        Schema::create('study_programs', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('class_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');
            $table->timestamps();
        });
        Schema::create('class_grade_weights', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id')->unique();
            $table->unsignedTinyInteger('assignment_weight')->default(40);
            $table->unsignedTinyInteger('exam_weight')->default(40);
            $table->unsignedTinyInteger('attendance_weight')->default(20);
            $table->json('components')->nullable();
            $table->timestamps();
        });
        Schema::create('krs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('status')->default('APPROVED');
        });
        Schema::create('krs_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('krs_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('status');
        });
        Schema::create('lecture_journals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedInteger('meeting_number');
            $table->date('meeting_date');
            $table->string('topic');
            $table->text('description')->nullable();
            $table->text('learning_activity')->nullable();
            $table->string('status')->default('PLANNED');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->string('delivery_mode', 10)->nullable();
            $table->string('attendance_token_hash')->nullable();
            $table->timestamp('attendance_opens_at')->nullable();
            $table->timestamp('attendance_closes_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('review_status')->default('NOT_SUBMITTED');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('student_id');
            $table->string('status');
            $table->string('method')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['journal_id', 'student_id']);
        });
        Schema::create('rpkps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->string('code');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('rpkps_weekly_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->unsignedInteger('week_number');
            $table->text('sub_cpmk')->nullable();
            $table->text('learning_material')->nullable();
            $table->text('methods')->nullable();
        });
        Schema::create('rpkps_cpmks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->string('code');
            $table->text('description');
            $table->unsignedInteger('order')->default(0);
        });
        Schema::create('rpkps_sub_cpmks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_cpmk_id');
            $table->string('code');
            $table->text('description');
            $table->unsignedInteger('order')->default(0);
        });
        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->string('title');
            $table->unsignedInteger('max_score')->default(100);
            $table->dateTime('due_date')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('student_id');
            $table->decimal('score', 8, 2)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
        });
        Schema::create('assignment_sub_cpmk', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('rpkps_sub_cpmk_id');
            $table->unique(['assignment_id', 'rpkps_sub_cpmk_id']);
        });
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->string('title');
            $table->string('type')->default('UTS');
            $table->text('description')->nullable();
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->unsignedInteger('duration_minutes')->default(90);
            $table->string('token')->nullable();
            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_options')->default(true);
            $table->boolean('show_score')->default(false);
            $table->boolean('is_online')->default(true);
            $table->boolean('is_published')->default(false);
            $table->string('status')->default('DRAFT');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->timestamps();
        });
        Schema::create('exam_sub_cpmk', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('rpkps_sub_cpmk_id');
            $table->unique(['exam_id', 'rpkps_sub_cpmk_id']);
        });
        Schema::create('exam_questions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exam_id');
            $table->decimal('score', 5, 2)->default(0);
        });
        Schema::create('exam_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('student_id');
            $table->decimal('total_score', 5, 2)->nullable();
            $table->string('status')->default('IN_PROGRESS');
            $table->timestamp('finished_at')->nullable();
        });
        Schema::create('discussions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
        Schema::create('discussion_replies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('discussion_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('content');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('type')->default('info');
            $table->string('link')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        DB::table('courses')->insert([
            ['id' => 1, 'code' => 'MK-A', 'name' => 'Mata Kuliah A'],
            ['id' => 2, 'code' => 'MK-B', 'name' => 'Mata Kuliah B'],
        ]);
        DB::table('classes')->insert([
            'id' => 1,
            'study_program_id' => 1,
            'semester_id' => 10,
            'course_id' => 1,
            'lecturer_id' => 5,
        ]);
        DB::table('class_courses')->insert([
            ['class_id' => 1, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 1, 'course_id' => 2, 'is_primary' => false],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'nim' => 'M001', 'name' => 'Mahasiswa Kelas', 'user_id' => 11],
            ['id' => 2, 'nim' => 'M002', 'name' => 'Mahasiswa Luar', 'user_id' => 12],
        ]);
        DB::table('users')->insert([
            ['id' => 11, 'name' => 'Mahasiswa Kelas', 'email' => 'student1@example.test'],
            ['id' => 12, 'name' => 'Mahasiswa Luar', 'email' => 'student2@example.test'],
            ['id' => 15, 'name' => 'Dosen Pengampu', 'email' => 'lecturer@example.test'],
            ['id' => 99, 'name' => 'Admin', 'email' => 'admin@example.test'],
        ]);
        DB::table('lecturers')->insert(['id' => 5, 'user_id' => 15]);
        DB::table('class_members')->insert([
            'class_id' => 1,
            'student_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lecture_journals')->insert([
            'id' => 1,
            'class_id' => 1,
            'meeting_number' => 1,
            'meeting_date' => '2026-09-16',
            'topic' => 'Topik Awal',
            'status' => 'COMPLETED',
            'lecturer_id' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = Mockery::mock(User::class)->makePartial();
        $admin->id = 99;
        $admin->setRelation('student', null);
        $admin->setRelation('lecturer', null);
        $admin->shouldReceive('hasRole')->with('SUPER_ADMIN')->andReturn(true);
        Auth::setUser($admin);
    }

    public function test_attendance_rejects_student_outside_the_class(): void
    {
        $request = Request::create('/api/lectures/journals/1/attendances', 'POST', [
            'attendances' => [['student_id' => 2, 'status' => 'HADIR']],
        ]);

        $this->expectException(ValidationException::class);
        app(LectureController::class)->saveAttendance($request, LectureJournal::findOrFail(1));
    }

    public function test_attendance_summary_counts_only_present_students(): void
    {
        DB::table('attendances')->insert([
            ['journal_id' => 1, 'student_id' => 1, 'status' => 'IZIN'],
            ['journal_id' => 1, 'student_id' => 2, 'status' => 'HADIR'],
        ]);

        $journals = app(LectureController::class)->journalList(ClassModel::findOrFail(1))->getData(true);

        $this->assertSame(2, $journals[0]['attendances_count']);
        $this->assertSame(1, $journals[0]['present_count']);
    }

    public function test_journal_records_instructor_delivery_mode_independently(): void
    {
        $request = Request::create('/api/lectures/1/journals', 'POST', [
            'meeting_number' => 2,
            'meeting_date' => '2026-09-23',
            'topic' => 'Kelas hybrid',
            'status' => 'COMPLETED',
            'delivery_mode' => 'ONLINE',
        ]);
        $request->setUserResolver(fn () => Auth::user());

        $response = app(LectureController::class)->storeJournal($request, ClassModel::findOrFail(1));

        $this->assertSame('ONLINE', $response->getData(true)['data']['delivery_mode']);
        $this->assertDatabaseHas('lecture_journals', [
            'class_id' => 1,
            'meeting_number' => 2,
            'delivery_mode' => 'ONLINE',
            'review_status' => 'PENDING',
        ]);
    }

    public function test_journal_rejects_unsupported_instructor_delivery_mode(): void
    {
        $request = Request::create('/api/lectures/1/journals', 'POST', [
            'meeting_number' => 2,
            'meeting_date' => '2026-09-23',
            'topic' => 'Kelas hybrid',
            'delivery_mode' => 'HYBRID',
        ]);

        $this->expectException(ValidationException::class);
        app(LectureController::class)->storeJournal($request, ClassModel::findOrFail(1));
    }

    public function test_rps_topics_are_returned_for_every_course_in_a_shared_class(): void
    {
        DB::table('rpkps')->insert([
            ['id' => 1, 'course_id' => 1, 'lecturer_id' => 5, 'semester_id' => 10, 'code' => 'RPS-A', 'status' => 'DISETUJUI', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'course_id' => 2, 'lecturer_id' => 6, 'semester_id' => 10, 'code' => 'RPS-B', 'status' => 'DIKUNCI', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('rpkps_weekly_plans')->insert([
            ['rpkps_id' => 1, 'week_number' => 1, 'sub_cpmk' => 'Topik RPS A', 'learning_material' => 'Materi A', 'methods' => json_encode(['Diskusi'])],
            ['rpkps_id' => 2, 'week_number' => 1, 'sub_cpmk' => 'Topik RPS B', 'learning_material' => 'Materi B', 'methods' => json_encode(['Studi kasus'])],
        ]);

        $data = app(LectureController::class)->getFromRps(ClassModel::findOrFail(1))->getData(true);

        $this->assertSame('RPS-A', $data['rpkps_code']);
        $this->assertCount(2, $data['sources']);
        $this->assertSame('Topik RPS A', $data['plans'][0]['topic']);
        $this->assertSame('Topik RPS B', $data['sources'][1]['plans'][0]['topic']);
    }

    public function test_gradebook_includes_members_and_students_from_approved_krs(): void
    {
        $krsId = DB::table('krs')->insertGetId([
            'student_id' => 2,
            'status' => 'APPROVED',
        ]);
        DB::table('krs_details')->insert([
            'krs_id' => $krsId,
            'course_id' => 1,
            'class_id' => 1,
            'status' => 'AKTIF',
        ]);

        $gradebook = app(LectureController::class)->gradebook(ClassModel::findOrFail(1))->getData(true);

        $this->assertSame(['M001', 'M002'], array_column($gradebook['students'], 'nim'));
        $this->assertSame([], $gradebook['assignments']);
    }

    public function test_class_activities_map_to_rpkps_sub_cpmks_and_report_attainment(): void
    {
        DB::table('rpkps')->insert([
            'id' => 1,
            'course_id' => 1,
            'lecturer_id' => 5,
            'semester_id' => 10,
            'code' => 'RPS-A',
            'status' => 'DISETUJUI',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rpkps_cpmks')->insert([
            ['id' => 1, 'rpkps_id' => 1, 'code' => 'CPMK-1', 'description' => 'Memahami konsep', 'order' => 1],
        ]);
        DB::table('rpkps_sub_cpmks')->insert([
            ['id' => 1, 'rpkps_cpmk_id' => 1, 'code' => 'Sub-CPMK-1.1', 'description' => 'Menjelaskan konsep', 'order' => 1],
            ['id' => 2, 'rpkps_cpmk_id' => 1, 'code' => 'Sub-CPMK-1.2', 'description' => 'Menerapkan konsep', 'order' => 2],
        ]);
        DB::table('assignments')->insert([
            'id' => 1,
            'class_id' => 1,
            'title' => 'Tugas konsep',
            'max_score' => 100,
            'due_date' => now()->subDay(),
            'is_published' => true,
        ]);
        DB::table('assignment_sub_cpmk')->insert([
            'assignment_id' => 1,
            'rpkps_sub_cpmk_id' => 1,
        ]);
        DB::table('assignment_submissions')->insert([
            'assignment_id' => 1,
            'student_id' => 1,
            'score' => 80,
            'submitted_at' => now()->subHours(2),
            'graded_at' => now()->subHour(),
        ]);
        DB::table('exams')->insert([
            'id' => 1,
            'class_id' => 1,
            'title' => 'UTS Penerapan',
            'type' => 'UTS',
            'is_published' => true,
            'start_time' => now()->subDays(2),
            'end_time' => now()->subDay(),
        ]);
        DB::table('exam_sub_cpmk')->insert([
            'exam_id' => 1,
            'rpkps_sub_cpmk_id' => 2,
        ]);
        DB::table('exam_questions')->insert(['exam_id' => 1, 'score' => 100]);
        DB::table('exam_sessions')->insert([
            'exam_id' => 1,
            'student_id' => 1,
            'total_score' => 70,
            'status' => 'SUBMITTED',
            'finished_at' => now()->subDay(),
        ]);

        $gradebook = app(LectureController::class)->gradebook(ClassModel::findOrFail(1))->getData(true);
        $student = collect($gradebook['students'])->firstWhere('id', 1);

        $this->assertSame(['Sub-CPMK-1.1', 'Sub-CPMK-1.2'], array_column($gradebook['sub_cpmks'], 'code'));
        $this->assertEquals(80, $student['outcome_scores'][1]['score']);
        $this->assertEquals(70, $student['outcome_scores'][2]['score']);
        $this->assertEquals(75, $student['cpmk_scores'][1]['score']);
        $this->assertSame([1], $gradebook['assignments'][0]['sub_cpmk_ids']);
        $this->assertSame([2], $gradebook['exams'][0]['sub_cpmk_ids']);
    }

    public function test_class_activity_mapping_only_accepts_sub_cpmks_from_the_active_course_rpkps(): void
    {
        DB::table('rpkps')->insert([
            ['id' => 1, 'course_id' => 1, 'lecturer_id' => 5, 'semester_id' => 10, 'code' => 'RPS-A', 'status' => 'DISETUJUI', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'course_id' => 1, 'lecturer_id' => 5, 'semester_id' => 11, 'code' => 'RPS-A-NEXT', 'status' => 'DISETUJUI', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('rpkps_cpmks')->insert([
            ['id' => 1, 'rpkps_id' => 1, 'code' => 'CPMK-1', 'description' => 'Semester berjalan', 'order' => 1],
            ['id' => 2, 'rpkps_id' => 2, 'code' => 'CPMK-2', 'description' => 'Semester lain', 'order' => 1],
        ]);
        DB::table('rpkps_sub_cpmks')->insert([
            ['id' => 1, 'rpkps_cpmk_id' => 1, 'code' => 'Sub-CPMK-1.1', 'description' => 'Valid', 'order' => 1],
            ['id' => 2, 'rpkps_cpmk_id' => 2, 'code' => 'Sub-CPMK-2.1', 'description' => 'Di luar semester', 'order' => 1],
        ]);

        $assignmentResponse = app(LectureController::class)->storeAssignment(
            Request::create('/api/lectures/1/assignments', 'POST', [
                'title' => 'Tugas CPMK',
                'sub_cpmk_ids' => [1],
            ]),
            ClassModel::findOrFail(1)
        );
        $assignmentId = $assignmentResponse->getData(true)['data']['id'];
        $this->assertDatabaseHas('assignment_sub_cpmk', [
            'assignment_id' => $assignmentId,
            'rpkps_sub_cpmk_id' => 1,
        ]);

        $examResponse = app(ExamController::class)->store(Request::create('/api/exams', 'POST', [
            'class_id' => 1,
            'title' => 'Ujian CPMK',
            'type' => 'UTS',
            'sub_cpmk_ids' => [1],
        ]));
        $examId = $examResponse->getData(true)['data']['id'];
        $this->assertDatabaseHas('exam_sub_cpmk', [
            'exam_id' => $examId,
            'rpkps_sub_cpmk_id' => 1,
        ]);

        try {
            app(LectureController::class)->storeAssignment(
                Request::create('/api/lectures/1/assignments', 'POST', [
                    'title' => 'Tugas lintas semester',
                    'sub_cpmk_ids' => [2],
                ]),
                ClassModel::findOrFail(1)
            );
            $this->fail('Mapping ke Sub-CPMK semester lain harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sub_cpmk_ids', $exception->errors());
        }

    }

    public function test_gradebook_calculates_weighted_score_and_counts_overdue_missing_work_as_zero(): void
    {
        $krsId = DB::table('krs')->insertGetId([
            'student_id' => 2,
            'status' => 'APPROVED',
        ]);
        DB::table('krs_details')->insert([
            'krs_id' => $krsId,
            'course_id' => 1,
            'class_id' => 1,
            'status' => 'AKTIF',
        ]);
        $dueDate = now()->subDay()->toDateTimeString();
        DB::table('assignments')->insert([
            ['id' => 1, 'class_id' => 1, 'title' => 'Tugas 1', 'max_score' => 100, 'due_date' => $dueDate, 'is_published' => true],
            ['id' => 2, 'class_id' => 1, 'title' => 'Tugas 2', 'max_score' => 100, 'due_date' => $dueDate, 'is_published' => true],
            ['id' => 3, 'class_id' => 1, 'title' => 'Tugas Mendatang', 'max_score' => 100, 'due_date' => now()->addDay()->toDateTimeString(), 'is_published' => true],
        ]);
        DB::table('assignment_submissions')->insert([
            ['assignment_id' => 1, 'student_id' => 1, 'score' => 80, 'submitted_at' => now(), 'graded_at' => now()],
        ]);
        DB::table('exams')->insert([
            [
                'id' => 1,
                'class_id' => 1,
                'title' => 'Ujian Tengah Semester',
                'type' => 'UTS',
                'is_published' => true,
                'start_time' => now()->subDays(2),
                'end_time' => now()->subDay(),
            ],
            [
                'id' => 2,
                'class_id' => 1,
                'title' => 'Ujian Mendatang',
                'type' => 'UAS',
                'is_published' => true,
                'start_time' => now()->addDay(),
                'end_time' => now()->addDays(2),
            ],
        ]);
        DB::table('exams')->insert([
            'id' => 3,
            'class_id' => 1,
            'title' => 'Kuis Mingguan',
            'type' => 'QUIZ',
            'is_published' => true,
            'start_time' => now()->subDays(3),
            'end_time' => now()->subDay(),
        ]);
        DB::table('exam_questions')->insert([
            ['exam_id' => 1, 'score' => 100],
            ['exam_id' => 2, 'score' => 100],
            ['exam_id' => 3, 'score' => 100],
        ]);
        DB::table('exam_sessions')->insert([
            [
                'exam_id' => 1,
                'student_id' => 1,
                'total_score' => 80,
                'status' => 'SUBMITTED',
                'finished_at' => now()->subDay(),
            ],
            [
                'exam_id' => 3,
                'student_id' => 1,
                'total_score' => 100,
                'status' => 'SUBMITTED',
                'finished_at' => now()->subDay(),
            ],
        ]);
        DB::table('attendances')->insert([
            'journal_id' => 1,
            'student_id' => 1,
            'status' => 'HADIR',
        ]);

        $gradebook = app(LectureController::class)->gradebook(ClassModel::findOrFail(1))->getData(true);
        $student = collect($gradebook['students'])->firstWhere('id', 1);
        $studentWithoutWork = collect($gradebook['students'])->firstWhere('id', 2);

        $this->assertSame(ClassGradeWeight::DEFAULT_COMPONENTS, $gradebook['components']);
        $this->assertEquals(60, $student['component_scores']['tasks']);
        $this->assertEquals(80, $student['component_scores']['uts']);
        $this->assertEquals(100, $student['component_scores']['attendance']);
        $this->assertArrayNotHasKey('final_score', $student);
        $this->assertArrayNotHasKey('final_score', $studentWithoutWork);
        $this->assertEquals(0, $studentWithoutWork['component_scores']['tasks']);
        $this->assertTrue(collect($student['grades'])->firstWhere('assignment_id', 2)['is_missing']);
        $this->assertNull(collect($student['grades'])->firstWhere('assignment_id', 3)['score']);
        $this->assertSame(2, $student['pending_activities']);
        $this->assertTrue($student['is_provisional']);
    }

    public function test_gradebook_weights_must_total_one_hundred_and_can_be_saved(): void
    {
        $response = app(LectureController::class)->updateGradebookWeights(
            Request::create('/api/lectures/1/gradebook/weights', 'PUT', [
                'components' => [
                    ['key' => 'attendance', 'name' => 'Presensi', 'weight' => 10, 'source' => 'attendance'],
                    ['key' => 'tasks', 'name' => 'Tugas & Kuis', 'weight' => 20, 'source' => 'tasks'],
                    ['key' => 'uts', 'name' => 'UTS', 'weight' => 30, 'source' => 'uts'],
                    ['key' => 'uas', 'name' => 'UAS', 'weight' => 40, 'source' => 'uas'],
                ],
            ]),
            ClassModel::findOrFail(1)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(20, ClassGradeWeight::where('class_id', 1)->value('assignment_weight'));

        $this->expectException(ValidationException::class);
        app(LectureController::class)->updateGradebookWeights(
            Request::create('/api/lectures/1/gradebook/weights', 'PUT', [
                'components' => [
                    ['key' => 'attendance', 'name' => 'Presensi', 'weight' => 10, 'source' => 'attendance'],
                    ['key' => 'tasks', 'name' => 'Tugas', 'weight' => 20, 'source' => 'tasks'],
                    ['key' => 'uts', 'name' => 'UTS', 'weight' => 30, 'source' => 'uts'],
                    ['key' => 'uas', 'name' => 'UAS', 'weight' => 50, 'source' => 'uas'],
                ],
            ]),
            ClassModel::findOrFail(1)
        );
    }

    public function test_enrolled_student_can_create_a_discussion_and_reply(): void
    {
        DB::table('class_members')->insert([
            'class_id' => 1,
            'student_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $student = Mockery::mock(User::class)->makePartial();
        $student->id = 11;
        $student->setRelation('student', Student::findOrFail(1));
        $student->setRelation('lecturer', null);
        $student->shouldReceive('hasRole')->with('SUPER_ADMIN')->andReturn(false)->zeroOrMoreTimes();
        $student->shouldReceive('hasRole')->with('ADMIN_AKADEMIK')->andReturn(false)->zeroOrMoreTimes();
        Auth::setUser($student);

        $controller = app(LectureController::class);
        $class = ClassModel::findOrFail(1);
        $createdResponse = $controller->storeDiscussion(Request::create('/api/lectures/1/discussions', 'POST', [
            'title' => 'Pertanyaan mahasiswa',
            'content' => 'Bagaimana konsep ini diterapkan?',
        ]), $class);

        $this->assertSame(201, $createdResponse->getStatusCode());
        $created = $createdResponse->getData(true);
        $discussion = Discussion::findOrFail($created['data']['id']);
        $this->assertSame(11, $discussion->user_id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => 12,
            'title' => 'Topik Diskusi Baru',
            'link' => '/perkuliahan/1?tab=diskusi',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => 15,
            'title' => 'Topik Diskusi Baru',
            'link' => '/perkuliahan/1?tab=diskusi',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => 11,
            'title' => 'Topik Diskusi Baru',
        ]);

        $replyingStudent = Mockery::mock(User::class)->makePartial();
        $replyingStudent->id = 12;
        $replyingStudent->setRelation('student', Student::findOrFail(2));
        $replyingStudent->setRelation('lecturer', null);
        $replyingStudent->shouldReceive('hasRole')->with('SUPER_ADMIN')->andReturn(false)->zeroOrMoreTimes();
        $replyingStudent->shouldReceive('hasRole')->with('ADMIN_AKADEMIK')->andReturn(false)->zeroOrMoreTimes();
        Auth::setUser($replyingStudent);

        $reply = $controller->storeDiscussionReply(Request::create('/api/lectures/1/discussions/1/replies', 'POST', [
            'content' => 'Saya ingin menambahkan contoh.',
        ]), $class, $discussion)->getData(true);

        $this->assertSame('Saya ingin menambahkan contoh.', $reply['data']['content']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => 11,
            'title' => 'Balasan Diskusi Baru',
            'link' => '/perkuliahan/1?tab=diskusi&discussion_id='.$discussion->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => 12,
            'title' => 'Balasan Diskusi Baru',
        ]);
        $this->assertSame(1, $discussion->replies()->count());
        $list = $controller->discussionList($class)->getData(true);
        $this->assertSame('Mahasiswa Kelas', $list[0]['user']['name']);
        $this->assertSame(1, $list[0]['replies_count']);
        $detail = $controller->discussionShow($class, $discussion)->getData(true);
        $this->assertSame('Saya ingin menambahkan contoh.', $detail['replies'][0]['content']);
    }

    public function test_opening_attendance_session_notifies_class_participants(): void
    {
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->id = 99;
        $admin->setRelation('student', null);
        $admin->setRelation('lecturer', null);
        $admin->shouldReceive('hasRole')->with('SUPER_ADMIN')->andReturn(true)->zeroOrMoreTimes();
        Auth::setUser($admin);

        $response = app(LectureController::class)->openAttendanceSession(
            Request::create('/api/lectures/1/attendance/session', 'POST', [
                'closes_at' => now()->addHour()->toDateTimeString(),
            ]),
            LectureJournal::findOrFail(1)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('notifications', [
            'user_id' => 11,
            'title' => 'Sesi Presensi Dibuka',
            'link' => '/perkuliahan/1?tab=jurnal',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => 12,
            'title' => 'Sesi Presensi Dibuka',
        ]);
    }

    public function test_student_outside_the_class_cannot_create_a_discussion(): void
    {
        $student = Mockery::mock(User::class)->makePartial();
        $student->id = 12;
        $student->setRelation('student', Student::findOrFail(2));
        $student->setRelation('lecturer', null);
        $student->shouldReceive('hasRole')->with('SUPER_ADMIN')->andReturn(false)->zeroOrMoreTimes();
        $student->shouldReceive('hasRole')->with('ADMIN_AKADEMIK')->andReturn(false)->zeroOrMoreTimes();
        Auth::setUser($student);

        $this->expectException(HttpException::class);
        app(LectureController::class)->storeDiscussion(Request::create('/api/lectures/1/discussions', 'POST', [
            'title' => 'Topik dari luar kelas',
            'content' => 'Konten',
        ]), ClassModel::findOrFail(1));
    }

    public function test_locked_discussion_rejects_replies(): void
    {
        $discussion = Discussion::create([
            'class_id' => 1,
            'user_id' => 99,
            'title' => 'Topik terkunci',
            'content' => 'Diskusi telah selesai.',
            'is_locked' => true,
        ]);

        $this->expectException(HttpException::class);
        app(LectureController::class)->storeDiscussionReply(Request::create('/api/lectures/1/discussions/1/replies', 'POST', [
            'content' => 'Balasan baru',
        ]), ClassModel::findOrFail(1), $discussion);
    }

    public function test_class_manager_can_pin_and_lock_a_discussion(): void
    {
        $discussion = Discussion::create([
            'class_id' => 1,
            'user_id' => 99,
            'title' => 'Topik penting',
            'content' => 'Informasi kelas.',
        ]);

        $response = app(LectureController::class)->moderateDiscussion(
            Request::create('/api/lectures/1/discussions/1/moderation', 'PATCH', [
                'is_pinned' => true,
                'is_locked' => true,
            ]),
            ClassModel::findOrFail(1),
            $discussion
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($discussion->fresh()->is_pinned);
        $this->assertTrue($discussion->fresh()->is_locked);
    }

    public function test_assignment_templates_are_saved_and_listed_only_for_the_current_user(): void
    {
        $controller = app(AssignmentTemplateController::class);
        $response = $controller->store(Request::create('/api/assignment-templates', 'POST', [
            'name' => 'Esai konsep',
            'title' => 'Analisis konsep',
            'description' => 'Gunakan konsep perkuliahan.',
            'instructions' => 'Jawab dengan referensi.',
            'type' => 'INDIVIDU',
            'max_score' => 80,
            'allow_late' => true,
            'due_date' => now()->addWeek()->toDateTimeString(),
            'sub_cpmk_ids' => [1],
        ]));
        $templateId = $response->getData(true)['data']['id'];

        DB::table('assignment_templates')->insert([
            'user_id' => 88,
            'name' => 'Template dosen lain',
            'title' => 'Tugas lain',
            'type' => 'KELOMPOK',
            'max_score' => 100,
            'allow_late' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $templates = $controller->index()->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $templates);
        $this->assertSame($templateId, $templates[0]['id']);
        $this->assertSame(99, $templates[0]['user_id']);
        $this->assertSame('Analisis konsep', $templates[0]['title']);
        $this->assertArrayNotHasKey('due_date', $templates[0]);
        $this->assertArrayNotHasKey('sub_cpmk_ids', $templates[0]);
    }

    public function test_assignment_template_cannot_be_deleted_by_another_user(): void
    {
        $templateId = DB::table('assignment_templates')->insertGetId([
            'user_id' => 88,
            'name' => 'Template privat',
            'title' => 'Tugas privat',
            'type' => 'INDIVIDU',
            'max_score' => 100,
            'allow_late' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(HttpException::class);
        app(AssignmentTemplateController::class)->destroy(
            \App\Models\AssignmentTemplate::findOrFail($templateId)
        );
    }

    public function test_assignment_template_name_must_be_unique_for_the_current_user(): void
    {
        $controller = app(AssignmentTemplateController::class);
        $payload = [
            'name' => 'Template unik',
            'title' => 'Tugas pertama',
            'type' => 'INDIVIDU',
            'max_score' => 100,
            'allow_late' => false,
        ];

        $controller->store(Request::create('/api/assignment-templates', 'POST', $payload));
        $this->expectException(ValidationException::class);
        $controller->store(Request::create('/api/assignment-templates', 'POST', [
            ...$payload,
            'title' => 'Tugas duplikat',
        ]));
    }
}
