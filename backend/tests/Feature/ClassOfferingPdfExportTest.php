<?php

namespace Tests\Feature;

use App\Exports\ClassOfferingPdfExport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassOfferingPdfExportTest extends TestCase
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
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->unsignedBigInteger('study_program_id')->nullable();
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
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('curriculum_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name')->nullable();
            $table->string('full_name')->nullable();
        });
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
        });
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->string('day');
            $table->string('start_time');
            $table->string('end_time');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('class_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');
        });
        Schema::create('krs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('status');
        });
        Schema::create('krs_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('krs_id');
            $table->unsignedBigInteger('class_id');
            $table->string('status');
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('class_type');
        });

        DB::table('courses')->insert([
            ['id' => 1, 'code' => 'MK101', 'name' => 'Pengantar'],
            ['id' => 2, 'code' => 'MK102', 'name' => 'Lanjutan'],
        ]);
        DB::table('classes')->insert([
            'id' => 1,
            'course_id' => 1,
            'name' => 'A',
            'is_active' => true,
        ]);
        DB::table('class_courses')->insert([
            ['class_id' => 1, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 1, 'course_id' => 2, 'is_primary' => false],
        ]);
        DB::table('lecturers')->insert([
            'id' => 1,
            'display_name' => 'Dosen A',
            'full_name' => 'Dosen A',
        ]);
        DB::table('rooms')->insert([
            'id' => 1,
            'code' => 'R-01',
            'name' => 'Ruang 01',
        ]);
        DB::table('schedules')->insert([
            [
                'id' => 1,
                'class_id' => 1,
                'day' => 'Senin',
                'start_time' => '08:00:00',
                'end_time' => '09:30:00',
                'room_id' => 1,
                'lecturer_id' => 1,
                'is_active' => true,
            ],
            [
                'id' => 2,
                'class_id' => 1,
                'day' => 'Selasa',
                'start_time' => '10:00:00',
                'end_time' => '11:30:00',
                'room_id' => null,
                'lecturer_id' => null,
                'is_active' => false,
            ],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'class_type' => 'REGULER'],
            ['id' => 2, 'class_type' => 'HYBRID'],
            ['id' => 3, 'class_type' => 'KHUSUS'],
        ]);
        DB::table('class_members')->insert([
            ['class_id' => 1, 'student_id' => 1],
            ['class_id' => 1, 'student_id' => 3],
        ]);
        DB::table('krs')->insert([
            ['id' => 1, 'student_id' => 1, 'status' => 'APPROVED'],
            ['id' => 2, 'student_id' => 2, 'status' => 'APPROVED'],
        ]);
        DB::table('krs_details')->insert([
            ['krs_id' => 1, 'class_id' => 1, 'status' => 'AKTIF'],
            ['krs_id' => 2, 'class_id' => 1, 'status' => 'AKTIF'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['krs_details', 'krs', 'class_members', 'students', 'schedules', 'class_courses', 'classes', 'courses', 'lecturers', 'rooms'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_export_lists_all_offered_courses_and_counts_regular_and_hybrid_students_once(): void
    {
        $rows = (new ClassOfferingPdfExport)->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('MK101'."\n".'MK102', $rows[0]['course_codes']);
        $this->assertSame('Pengantar'."\n".'Lanjutan', $rows[0]['course_names']);
        $this->assertSame('Senin', $rows[0]['days']);
        $this->assertSame('08:00 - 09:30', $rows[0]['times']);
        $this->assertSame('R-01', $rows[0]['rooms']);
        $this->assertSame(1, $rows[0]['offline_count']);
        $this->assertSame(1, $rows[0]['online_count']);
    }
}
