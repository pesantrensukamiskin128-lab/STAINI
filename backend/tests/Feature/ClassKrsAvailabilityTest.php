<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClassController;
use App\Models\ClassModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassKrsAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('study_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('credits')->default(2);
            $table->string('delivery_mode')->nullable();
            $table->boolean('status')->default(true);
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
        });
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('semester_id');
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
        Schema::create('class_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');
        });
        Schema::create('class_lecturers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('lecturer_id');
            $table->boolean('can_input_grades')->default(true);
            $table->timestamps();
        });
        Schema::create('krs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
        });
        Schema::create('krs_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('krs_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('status')->default('AKTIF');
        });
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->string('day')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('study_programs')->insert([
            ['id' => 1, 'code' => 'P1', 'name' => 'Prodi 1'], ['id' => 2, 'code' => 'P2', 'name' => 'Prodi 2'],
        ]);
        DB::table('academic_years')->insert(['id' => 1, 'name' => '2026/2027']);
        DB::table('semesters')->insert(['id' => 1, 'academic_year_id' => 1, 'name' => 'Ganjil']);
        DB::table('courses')->insert([
            ['id' => 1, 'study_program_id' => 1, 'code' => 'P1-01', 'name' => 'MK Prodi 1', 'status' => true],
            ['id' => 2, 'study_program_id' => 2, 'code' => 'P2-01', 'name' => 'MK Prodi 2', 'status' => true],
        ]);
        DB::table('classes')->insert([
            ['id' => 1, 'study_program_id' => 1, 'semester_id' => 1, 'course_id' => 1, 'lecturer_id' => 10, 'name' => 'Kelas P1', 'is_active' => true],
            ['id' => 2, 'study_program_id' => 2, 'semester_id' => 1, 'course_id' => 2, 'lecturer_id' => 20, 'name' => 'Kelas P2', 'is_active' => true],
        ]);
        DB::table('lecturers')->insert([
            ['id' => 10, 'full_name' => 'Dosen 1'],
            ['id' => 20, 'full_name' => 'Dosen 2'],
        ]);
        DB::table('class_courses')->insert([
            ['class_id' => 1, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 2, 'course_id' => 2, 'is_primary' => true],
        ]);
        DB::table('class_lecturers')->insert([
            'class_id' => 2,
            'lecturer_id' => 10,
            'can_input_grades' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_student_can_list_unenrolled_classes_for_krs_in_their_program(): void
    {
        $request = Request::create('/api/classes', 'GET', [
            'semester_id' => 1, 'available_for_krs' => 1, 'per_page' => 100,
        ]);
        $request->setUserResolver(fn () => new class
        {
            public object $student;

            public function __construct()
            {
                $this->student = (object) ['id' => 100, 'study_program_id' => 1];
            }

            public function hasRole(string $role): bool
            {
                return $role === 'MAHASISWA';
            }
        });

        $response = app(ClassController::class)->index($request)->getData(true);

        $this->assertSame(1, $response['total']);
        $this->assertSame('Kelas P1', $response['data'][0]['name']);
    }

    public function test_grade_input_class_list_only_includes_classes_assigned_to_the_lecturer(): void
    {
        $this->assertTrue(ClassModel::findOrFail(2)->lecturers()->whereKey(10)->exists());

        $request = Request::create('/api/classes/all', 'GET', [
            'semester_id' => 1,
            'for_grade_input' => true,
        ]);
        $request->setUserResolver(fn () => new class
        {
            public object $lecturer;

            public function __construct()
            {
                $this->lecturer = (object) ['id' => 10];
            }

            public function hasRole(string $role): bool
            {
                return $role === 'DOSEN';
            }
        });

        $classes = app(ClassController::class)->all($request)->getData(true);

        $this->assertEqualsCanonicalizing([1, 2], array_column($classes, 'id'));
    }

    public function test_student_can_see_cross_program_class_offerings_with_class_details(): void
    {
        DB::table('rooms')->insert(['id' => 9, 'name' => 'Ruang Gabungan']);
        DB::table('classes')->insert([
            'id' => 3,
            'study_program_id' => 1,
            'semester_id' => 1,
            'course_id' => 1,
            'lecturer_id' => 10,
            'room_id' => 9,
            'name' => 'Kelas Gabungan',
            'is_active' => true,
        ]);
        DB::table('class_courses')->insert([
            ['class_id' => 3, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 3, 'course_id' => 2, 'is_primary' => false],
        ]);
        DB::table('schedules')->insert([
            'class_id' => 3,
            'day' => 'Senin',
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
            'room_id' => 9,
            'lecturer_id' => 10,
            'is_active' => true,
        ]);

        $request = Request::create('/api/classes', 'GET', [
            'semester_id' => 1, 'available_for_krs' => 1, 'per_page' => 100,
        ]);
        $request->setUserResolver(fn () => new class
        {
            public object $student;

            public function __construct()
            {
                $this->student = (object) ['id' => 200, 'study_program_id' => 2];
            }

            public function hasRole(string $role): bool
            {
                return $role === 'MAHASISWA';
            }
        });

        $response = app(ClassController::class)->index($request)->getData(true);
        $combinedClass = collect($response['data'])->firstWhere('id', 3);

        $this->assertNotNull($combinedClass);
        $this->assertSame('Dosen 1', $combinedClass['lecturer']['full_name']);
        $this->assertSame('Senin', $combinedClass['schedules'][0]['day']);
        $this->assertSame('Ruang Gabungan', $combinedClass['schedules'][0]['room']['name']);
        $this->assertSame(2, count($combinedClass['offered_courses']));
    }
}
