<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\CourseController;
use App\Models\ClassModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassOfferingActiveCurriculumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('study_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->string('code');
            $table->string('name');
            $table->string('course_group')->nullable();
            $table->unsignedInteger('credits');
            $table->unsignedInteger('theory_credits')->default(0);
            $table->unsignedInteger('practical_credits')->default(0);
            $table->unsignedInteger('semester');
            $table->string('type');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('curriculums', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedInteger('year')->default(2026);
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('curriculum_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('semester')->default(1);
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('capacity')->default(40);
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
        Schema::create('krs_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->string('status')->default('AKTIF');
        });

        DB::table('study_programs')->insert([
            ['id' => 1, 'name' => 'Program A'],
            ['id' => 2, 'name' => 'Program B'],
        ]);
        DB::table('courses')->insert([
            ['id' => 1, 'study_program_id' => 1, 'code' => 'AKTIF-1', 'name' => 'Mata Kuliah Aktif', 'credits' => 2, 'semester' => 1, 'type' => 'Wajib', 'status' => true],
            ['id' => 2, 'study_program_id' => 1, 'code' => 'DRAFT-1', 'name' => 'Mata Kuliah Draft', 'credits' => 2, 'semester' => 1, 'type' => 'Wajib', 'status' => true],
            ['id' => 3, 'study_program_id' => 1, 'code' => 'NONAKTIF-1', 'name' => 'Mata Kuliah Nonaktif', 'credits' => 2, 'semester' => 1, 'type' => 'Wajib', 'status' => false],
            ['id' => 4, 'study_program_id' => 2, 'code' => 'AKTIF-2', 'name' => 'Mata Kuliah Prodi Lain', 'credits' => 2, 'semester' => 1, 'type' => 'Wajib', 'status' => true],
            ['id' => 5, 'study_program_id' => 2, 'code' => 'BEDA-SKS', 'name' => 'Mata Kuliah Beda SKS', 'credits' => 3, 'semester' => 1, 'type' => 'Wajib', 'status' => true],
        ]);
        DB::table('curriculums')->insert([
            ['id' => 1, 'study_program_id' => 1, 'status' => 'Aktif'],
            ['id' => 2, 'study_program_id' => 1, 'status' => 'Draft'],
            ['id' => 3, 'study_program_id' => 2, 'status' => 'Aktif'],
        ]);
        DB::table('curriculum_courses')->insert([
            ['curriculum_id' => 1, 'course_id' => 1],
            ['curriculum_id' => 1, 'course_id' => 3],
            ['curriculum_id' => 2, 'course_id' => 2],
            ['curriculum_id' => 3, 'course_id' => 4],
            ['curriculum_id' => 3, 'course_id' => 5],
        ]);
        DB::table('semesters')->insert(['id' => 1]);
        DB::table('lecturers')->insert(['id' => 1]);
        DB::table('classes')->insert([
            'id' => 1,
            'study_program_id' => 1,
            'semester_id' => 1,
            'course_id' => 1,
            'capacity' => 40,
        ]);
        DB::table('class_courses')->insert([
            'class_id' => 1,
            'course_id' => 1,
            'curriculum_id' => 1,
            'is_primary' => true,
        ]);
    }

    public function test_course_store_calculates_total_credits_from_theory_and_practice(): void
    {
        $request = Request::create('/api/courses', 'POST', [
            'study_program_id' => 1,
            'code' => 'MK-OBE',
            'name' => 'Mata Kuliah OBE',
            'course_group' => 'Mata Kuliah Keahlian',
            'credits' => 3,
            'theory_credits' => 2,
            'practical_credits' => 1,
            'semester' => 1,
            'type' => 'Wajib',
            'status' => true,
        ]);

        $response = app(CourseController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertDatabaseHas('courses', [
            'code' => 'MK-OBE',
            'course_group' => 'Mata Kuliah Keahlian',
            'credits' => 3,
            'theory_credits' => 2,
            'practical_credits' => 1,
        ]);
    }

    public function test_course_options_only_include_active_courses_from_the_active_curriculum(): void
    {
        $request = Request::create('/api/courses/all', 'GET', [
            'study_program_id' => 1,
            'active_curriculum_only' => true,
        ]);

        $data = app(CourseController::class)->all($request)->getData(true);

        $this->assertSame([1], array_column($data, 'id'));
    }

    public function test_class_cannot_be_created_with_a_course_outside_the_active_curriculum(): void
    {
        $request = Request::create('/api/classes', 'POST', [
            'study_program_id' => 1,
            'semester_id' => 1,
            'course_id' => 2,
            'lecturer_id' => 1,
            'name' => 'Kelas A',
        ]);

        $response = app(ClassController::class)->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'Mata kuliah DRAFT-1 tidak tercantum dalam kurikulum aktif prodinya.',
            $response->getData(true)['message']
        );
    }

    public function test_an_active_course_from_another_program_can_join_a_shared_class(): void
    {
        $request = Request::create('/api/classes/1/courses', 'PUT', [
            'offered_courses' => [
                ['course_id' => 1, 'quota' => 20],
                ['course_id' => 4, 'quota' => 20],
            ],
        ]);

        $response = app(ClassController::class)->syncCourses($request, ClassModel::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('class_courses', [
            'class_id' => 1,
            'course_id' => 4,
            'curriculum_id' => 3,
            'is_primary' => false,
            'quota' => 20,
        ]);
    }

    public function test_different_credits_require_explicit_confirmation(): void
    {
        $request = Request::create('/api/classes/1/courses', 'PUT', [
            'offered_courses' => [
                ['course_id' => 1],
                ['course_id' => 5],
            ],
        ]);

        $response = app(ClassController::class)->syncCourses($request, ClassModel::findOrFail(1));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['requires_credit_confirmation']);
    }

    public function test_a_course_with_participants_cannot_be_removed_from_the_class(): void
    {
        DB::table('class_courses')->insert([
            'class_id' => 1,
            'course_id' => 4,
            'curriculum_id' => 3,
            'is_primary' => false,
        ]);
        DB::table('krs_details')->insert([
            'class_id' => 1,
            'course_id' => 4,
            'status' => 'AKTIF',
        ]);
        $request = Request::create('/api/classes/1/courses', 'PUT', [
            'offered_courses' => [['course_id' => 1]],
        ]);

        $response = app(ClassController::class)->syncCourses($request, ClassModel::findOrFail(1));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'Mata kuliah tidak dapat dilepas karena sudah memiliki peserta pada kelas ini.',
            $response->getData(true)['message']
        );
    }
}
