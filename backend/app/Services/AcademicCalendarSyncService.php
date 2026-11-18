<?php

namespace App\Services;

use App\Models\AcademicCalendar;
use App\Models\Semester;

class AcademicCalendarSyncService
{
    private const PERIODS = [
        'KRS' => ['start' => 'krs_start', 'end' => 'krs_end', 'category' => 'Akademik', 'title' => 'Pengisian KRS'],
        'UTS' => ['start' => 'exam_mid_start', 'end' => 'exam_mid_end', 'category' => 'UTS', 'title' => 'Ujian Tengah Semester'],
        'UAS' => ['start' => 'exam_final_start', 'end' => 'exam_final_end', 'category' => 'UAS', 'title' => 'Ujian Akhir Semester'],
    ];

    public function syncSemester(Semester $semester): void
    {
        foreach (self::PERIODS as $eventType => $config) {
            $startDate = $semester->{$config['start']};
            $endDate = $semester->{$config['end']};

            if (! $startDate) {
                AcademicCalendar::where('semester_id', $semester->id)
                    ->where('event_type', $eventType)
                    ->delete();

                continue;
            }

            AcademicCalendar::updateOrCreate(
                ['semester_id' => $semester->id, 'event_type' => $eventType],
                [
                    'academic_year_id' => $semester->academic_year_id,
                    'title' => $config['title'].' '.$semester->name,
                    'description' => "Periode {$config['title']} untuk {$semester->name}.",
                    'start_date' => $startDate,
                    'end_date' => $endDate ?: $startDate,
                    'category' => $config['category'],
                    'color' => $eventType === 'KRS' ? '#2563eb' : ($eventType === 'UTS' ? '#f59e0b' : '#dc2626'),
                ]
            );
        }
    }

    public function syncSemesterFromEvent(AcademicCalendar $event): void
    {
        if (! $event->semester_id || ! isset(self::PERIODS[$event->event_type])) {
            return;
        }

        $config = self::PERIODS[$event->event_type];
        Semester::whereKey($event->semester_id)->update([
            $config['start'] => $event->start_date,
            $config['end'] => $event->end_date ?: $event->start_date,
        ]);
    }
}
