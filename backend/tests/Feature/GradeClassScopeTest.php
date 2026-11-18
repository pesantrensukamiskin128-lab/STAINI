<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GradeController;
use App\Imports\GradeClassImport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GradeClassScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('nim')->nullable();
            $table->string('name')->nullable();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('grade_schemas', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_default')->default(false);
        });
        Schema::create('student_grades', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('semester_id');
            $table->json('components')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->string('letter_grade')->nullable();
            $table->decimal('grade_point', 3, 2)->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'course_id', 'semester_id', 'class_id']);
        });

        DB::table('students')->insert(['id' => 1, 'nim' => 'S001', 'name' => 'Student']);
        DB::table('courses')->insert(['id' => 1]);
        DB::table('classes')->insert([['id' => 10], ['id' => 11]]);
        DB::table('semesters')->insert(['id' => 1]);
    }

    public function test_store_keeps_grades_separate_between_parallel_classes(): void
    {
        $this->saveSingleGrade(10, 75);
        $this->saveSingleGrade(11, 85);
        $this->saveSingleGrade(10, 90);

        $this->assertDatabaseCount('student_grades', 2);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
            'class_id' => 10, 'final_score' => 90,
        ]);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
            'class_id' => 11, 'final_score' => 85,
        ]);
    }

    public function test_batch_store_preserves_legacy_unscoped_grade(): void
    {
        DB::table('student_grades')->insert([
            'student_id' => 1,
            'course_id' => 1,
            'class_id' => null,
            'semester_id' => 1,
            'final_score' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/grades/batch', 'POST', [
            'grades' => [[
                'student_id' => 1,
                'course_id' => 1,
                'class_id' => 10,
                'semester_id' => 1,
                'components' => [['name' => 'Tugas', 'weight' => 100, 'score' => 80]],
            ]],
        ]);

        app(GradeController::class)->batchStore($request);

        $this->assertDatabaseCount('student_grades', 2);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
            'class_id' => null, 'final_score' => 60,
        ]);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
            'class_id' => 10, 'final_score' => 80,
        ]);
    }

    public function test_class_excel_import_updates_only_the_selected_class_grade(): void
    {
        DB::table('student_grades')->insert([
            'student_id' => 1,
            'course_id' => 1,
            'class_id' => 11,
            'semester_id' => 1,
            'final_score' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $import = new GradeClassImport(10, 1, 1, [
            ['name' => 'Tugas', 'weight' => 100],
        ]);
        $import->collection(collect([['nim' => 'S001', 'tugas' => 90]]));

        $this->assertSame(1, $import->imported);
        $this->assertDatabaseCount('student_grades', 2);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
            'class_id' => 10, 'final_score' => 90,
        ]);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => 1, 'course_id' => 1, 'semester_id' => 1,
            'class_id' => 11, 'final_score' => 70,
        ]);
    }

    private function saveSingleGrade(int $classId, int $score): void
    {
        $request = Request::create('/api/grades', 'POST', [
            'student_id' => 1,
            'course_id' => 1,
            'class_id' => $classId,
            'semester_id' => 1,
            'components' => [['name' => 'Tugas', 'weight' => 100, 'score' => $score]],
        ]);

        app(GradeController::class)->store($request);
    }
}
