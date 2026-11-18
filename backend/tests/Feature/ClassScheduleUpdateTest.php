<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClassController;
use App\Models\ClassModel;
use App\Models\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ClassScheduleUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('delivery_mode')->nullable();
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->timestamps();
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
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
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('courses')->insert([
            ['id' => 1, 'name' => 'Matematika', 'delivery_mode' => 'TATAP_MUKA'],
            ['id' => 2, 'name' => 'Bahasa', 'delivery_mode' => 'TATAP_MUKA'],
            ['id' => 3, 'name' => 'Pemagangan', 'delivery_mode' => 'NON_TATAP_MUKA'],
        ]);
        DB::table('classes')->insert([
            ['id' => 1, 'semester_id' => 1, 'course_id' => 1, 'lecturer_id' => 10, 'room_id' => 20],
            ['id' => 2, 'semester_id' => 1, 'course_id' => 2, 'lecturer_id' => 11, 'room_id' => 21],
            ['id' => 3, 'semester_id' => 1, 'course_id' => 3, 'lecturer_id' => 11, 'room_id' => 21],
        ]);
        DB::table('lecturers')->insert([
            ['id' => 10, 'name' => 'Dosen A'],
            ['id' => 11, 'name' => 'Dosen B'],
        ]);
        DB::table('rooms')->insert([
            ['id' => 20, 'name' => 'Ruang A'],
            ['id' => 21, 'name' => 'Ruang B'],
        ]);
        DB::table('schedules')->insert([
            ['id' => 1, 'class_id' => 1, 'day' => 'Senin', 'start_time' => '08:00', 'end_time' => '09:00', 'room_id' => 20, 'lecturer_id' => 10, 'is_active' => true],
            ['id' => 2, 'class_id' => 2, 'day' => 'Selasa', 'start_time' => '10:00', 'end_time' => '11:00', 'room_id' => 21, 'lecturer_id' => 11, 'is_active' => true],
            ['id' => 3, 'class_id' => 3, 'day' => 'Sabtu', 'start_time' => '07:00', 'end_time' => '09:00', 'room_id' => 21, 'lecturer_id' => 11, 'is_active' => true],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('lecturers');
        Schema::dropIfExists('rooms');

        parent::tearDown();
    }

    public function test_schedule_can_be_updated_without_conflicting_with_itself(): void
    {
        $response = app(ClassController::class)->updateSchedule(
            Request::create('/api/classes/1/schedules/1', 'PUT', [
                'day' => 'Rabu',
                'start_time' => '08:30',
                'end_time' => '09:30',
                'lecturer_id' => 10,
                'room_id' => 20,
            ]),
            ClassModel::findOrFail(1),
            Schedule::findOrFail(1),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Rabu', DB::table('schedules')->where('id', 1)->value('day'));
        $this->assertSame('08:30', DB::table('schedules')->where('id', 1)->value('start_time'));
    }

    public function test_schedule_update_is_rejected_when_lecturer_has_an_overlap(): void
    {
        $response = app(ClassController::class)->updateSchedule(
            Request::create('/api/classes/1/schedules/1', 'PUT', [
                'day' => 'Selasa',
                'start_time' => '10:30',
                'end_time' => '11:30',
                'lecturer_id' => 11,
                'room_id' => 20,
            ]),
            ClassModel::findOrFail(1),
            Schedule::findOrFail(1),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Senin', DB::table('schedules')->where('id', 1)->value('day'));
    }

    public function test_non_face_to_face_course_schedule_does_not_block_room_or_lecturer(): void
    {
        $response = app(ClassController::class)->updateSchedule(
            Request::create('/api/classes/1/schedules/1', 'PUT', [
                'day' => 'Sabtu',
                'start_time' => '07:00',
                'end_time' => '08:40',
                'lecturer_id' => 11,
                'room_id' => 21,
            ]),
            ClassModel::findOrFail(1),
            Schedule::findOrFail(1),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Sabtu', DB::table('schedules')->where('id', 1)->value('day'));
        $this->assertSame(21, DB::table('schedules')->where('id', 1)->value('room_id'));
    }

    public function test_face_to_face_course_schedule_still_blocks_room(): void
    {
        DB::table('schedules')->insert([
            'id' => 4,
            'class_id' => 2,
            'day' => 'Sabtu',
            'start_time' => '07:30',
            'end_time' => '09:30',
            'room_id' => 21,
            'lecturer_id' => 11,
            'is_active' => true,
        ]);

        $response = app(ClassController::class)->updateSchedule(
            Request::create('/api/classes/1/schedules/1', 'PUT', [
                'day' => 'Sabtu',
                'start_time' => '07:00',
                'end_time' => '08:40',
                'lecturer_id' => 10,
                'room_id' => 21,
            ]),
            ClassModel::findOrFail(1),
            Schedule::findOrFail(1),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Senin', DB::table('schedules')->where('id', 1)->value('day'));
    }

    public function test_schedule_must_belong_to_the_class_in_the_route(): void
    {
        $this->expectException(NotFoundHttpException::class);

        app(ClassController::class)->updateSchedule(
            Request::create('/api/classes/2/schedules/1', 'PUT', []),
            ClassModel::findOrFail(2),
            Schedule::findOrFail(1),
        );
    }
}
