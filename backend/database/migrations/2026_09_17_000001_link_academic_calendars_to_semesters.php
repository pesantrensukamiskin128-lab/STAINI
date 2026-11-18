<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_calendars', function (Blueprint $table): void {
            $table->foreignId('semester_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('semesters')
                ->nullOnDelete();
            $table->string('event_type', 30)->nullable()->after('category');
            $table->unique(['semester_id', 'event_type'], 'academic_calendar_semester_event_unique');
        });

        $periods = [
            'KRS' => ['start' => 'krs_start', 'end' => 'krs_end', 'category' => 'Akademik', 'title' => 'Pengisian KRS', 'color' => '#2563eb'],
            'UTS' => ['start' => 'exam_mid_start', 'end' => 'exam_mid_end', 'category' => 'UTS', 'title' => 'Ujian Tengah Semester', 'color' => '#f59e0b'],
            'UAS' => ['start' => 'exam_final_start', 'end' => 'exam_final_end', 'category' => 'UAS', 'title' => 'Ujian Akhir Semester', 'color' => '#dc2626'],
        ];
        foreach (DB::table('semesters')->orderBy('id')->get() as $semester) {
            foreach ($periods as $eventType => $config) {
                $startDate = $semester->{$config['start']};
                if (! $startDate) {
                    continue;
                }
                DB::table('academic_calendars')->updateOrInsert(
                    ['semester_id' => $semester->id, 'event_type' => $eventType],
                    [
                        'academic_year_id' => $semester->academic_year_id,
                        'title' => $config['title'].' '.$semester->name,
                        'description' => "Periode {$config['title']} untuk {$semester->name}.",
                        'start_date' => $startDate,
                        'end_date' => $semester->{$config['end']} ?: $startDate,
                        'category' => $config['category'],
                        'color' => $config['color'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('academic_calendars')
            ->whereNotNull('semester_id')
            ->whereIn('event_type', ['KRS', 'UTS', 'UAS'])
            ->delete();

        Schema::table('academic_calendars', function (Blueprint $table): void {
            $table->dropUnique('academic_calendar_semester_event_unique');
            $table->dropConstrainedForeignId('semester_id');
            $table->dropColumn('event_type');
        });
    }
};
