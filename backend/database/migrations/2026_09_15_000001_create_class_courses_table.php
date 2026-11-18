<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curriculums')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'course_id']);
            $table->index(['course_id', 'class_id']);
        });

        $curriculumByProgramAndCourse = [];
        DB::table('curriculum_courses')
            ->join('curriculums', 'curriculums.id', '=', 'curriculum_courses.curriculum_id')
            ->select([
                'curriculums.id as curriculum_id',
                'curriculums.study_program_id',
                'curriculums.status',
                'curriculums.year',
                'curriculum_courses.course_id',
            ])
            ->orderByRaw("CASE WHEN curriculums.status = 'Aktif' THEN 0 ELSE 1 END")
            ->orderByDesc('curriculums.year')
            ->orderByDesc('curriculums.id')
            ->get()
            ->each(function (object $row) use (&$curriculumByProgramAndCourse): void {
                $key = $row->study_program_id.':'.$row->course_id;
                $curriculumByProgramAndCourse[$key] ??= $row->curriculum_id;
            });

        $now = now();
        DB::table('classes')
            ->select(['id', 'study_program_id', 'course_id'])
            ->orderBy('id')
            ->chunkById(500, function ($classes) use ($curriculumByProgramAndCourse, $now): void {
                $rows = $classes->map(function (object $class) use ($curriculumByProgramAndCourse, $now): array {
                    return [
                        'class_id' => $class->id,
                        'course_id' => $class->course_id,
                        'curriculum_id' => $curriculumByProgramAndCourse[$class->study_program_id.':'.$class->course_id] ?? null,
                        'is_primary' => true,
                        'quota' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                DB::table('class_courses')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_courses');
    }
};
