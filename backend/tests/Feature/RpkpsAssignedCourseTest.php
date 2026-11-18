<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RpkpsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RpkpsAssignedCourseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('credits');
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->boolean('is_active')->default(true);
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
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
        });

        DB::table('lecturers')->insert([['id' => 1, 'user_id' => 10], ['id' => 2, 'user_id' => 20]]);
        DB::table('courses')->insert([
            ['id' => 1, 'study_program_id' => 1, 'code' => 'MK-A', 'name' => 'Mata Kuliah A', 'credits' => 2],
            ['id' => 2, 'study_program_id' => 2, 'code' => 'MK-B', 'name' => 'Mata Kuliah B', 'credits' => 2],
        ]);
        DB::table('classes')->insert(['id' => 1, 'study_program_id' => 1, 'semester_id' => 1, 'course_id' => 1, 'lecturer_id' => 1]);
        DB::table('class_courses')->insert([
            ['class_id' => 1, 'course_id' => 1, 'is_primary' => true],
            ['class_id' => 1, 'course_id' => 2, 'is_primary' => false],
        ]);
        DB::table('class_lecturers')->insert(['class_id' => 1, 'lecturer_id' => 2]);
    }

    public function test_team_lecturer_sees_all_courses_offered_by_the_assigned_shared_class(): void
    {
        $request = Request::create('/api/rpkps/my-courses');
        $request->setUserResolver(fn () => (object) ['id' => 20]);

        $data = app(RpkpsController::class)->myAssignedCourses($request)->getData(true);

        $this->assertSame(2, $data['lecturer_id']);
        $this->assertSame(['MK-A', 'MK-B'], array_column($data['courses'], 'code'));
    }
}
