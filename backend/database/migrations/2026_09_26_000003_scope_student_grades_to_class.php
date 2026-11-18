<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'student_grades_student_id_course_id_semester_id_unique';
    private const NEW_INDEX = 'student_grades_student_id_course_id_semester_id_class_id_unique';
    private const STUDENT_INDEX = 'student_grades_student_id_index';

    public function up(): void
    {
        Schema::table('student_grades', function (Blueprint $table): void {
            $table->index('student_id', self::STUDENT_INDEX);
        });

        Schema::table('student_grades', function (Blueprint $table): void {
            $table->dropUnique(self::OLD_INDEX);
            $table->unique(['student_id', 'course_id', 'semester_id', 'class_id'], self::NEW_INDEX);
        });
    }

    public function down(): void
    {
        $hasParallelGrades = DB::table('student_grades')
            ->select('student_id', 'course_id', 'semester_id')
            ->groupBy('student_id', 'course_id', 'semester_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasParallelGrades) {
            throw new RuntimeException('Cannot restore the previous student grade uniqueness while parallel-class grades exist.');
        }

        Schema::table('student_grades', function (Blueprint $table): void {
            $table->dropUnique(self::NEW_INDEX);
            $table->unique(['student_id', 'course_id', 'semester_id'], self::OLD_INDEX);
        });

        Schema::table('student_grades', function (Blueprint $table): void {
            $table->dropIndex(self::STUDENT_INDEX);
        });
    }
};
