<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_grade_weights', function (Blueprint $table): void {
            $table->json('components')->nullable();
        });

        DB::table('class_grade_weights')->orderBy('id')->chunkById(100, function ($weights): void {
            foreach ($weights as $weight) {
                $examWeight = (int) $weight->exam_weight;
                $utsWeight = intdiv($examWeight, 2);
                DB::table('class_grade_weights')
                    ->where('id', $weight->id)
                    ->update([
                        'components' => json_encode([
                            ['key' => 'attendance', 'name' => 'Presensi', 'weight' => (int) $weight->attendance_weight, 'source' => 'attendance'],
                            ['key' => 'tasks', 'name' => 'Tugas', 'weight' => (int) $weight->assignment_weight, 'source' => 'tasks'],
                            ['key' => 'uts', 'name' => 'UTS', 'weight' => $utsWeight, 'source' => 'uts'],
                            ['key' => 'uas', 'name' => 'UAS', 'weight' => $examWeight - $utsWeight, 'source' => 'uas'],
                        ], JSON_THROW_ON_ERROR),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_grade_weights', function (Blueprint $table): void {
            $table->dropColumn('components');
        });
    }
};
