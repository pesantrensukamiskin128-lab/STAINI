<?php

$backendPath = getenv('ASC_BACKEND_PATH') ?: __DIR__.'/../backend';

require $backendPath.'/vendor/autoload.php';

$app = require $backendPath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$course = App\Models\Course::where('code', '6251202013')->first();
$classIds = Illuminate\Support\Facades\DB::table('classes')
    ->where('course_id', $course->id)
    ->pluck('id')
    ->merge(Illuminate\Support\Facades\DB::table('class_courses')->where('course_id', $course->id)->pluck('class_id'))
    ->unique()
    ->values();

$classes = App\Models\ClassModel::with(['course', 'semester', 'lecturer', 'room', 'schedules.room', 'schedules.lecturer'])
    ->whereIn('id', $classIds)
    ->get();
$classes = $classes->map(fn ($class) => [
    'class_id' => $class->id,
    'name' => $class->name,
    'course_code' => $class->course?->code,
    'semester_id' => $class->semester_id,
    'semester' => $class->semester?->name,
    'delivery_mode' => $class->course?->delivery_mode,
    'room_id' => $class->room_id,
    'room' => $class->room?->name,
    'lecturer_id' => $class->lecturer_id,
    'schedules' => $class->schedules->map(fn ($schedule) => [
        'id' => $schedule->id,
        'day' => $schedule->day,
        'start' => $schedule->start_time,
        'end' => $schedule->end_time,
        'room_id' => $schedule->room_id,
        'lecturer_id' => $schedule->lecturer_id,
        'active' => $schedule->is_active,
    ])->values(),
]);

$semesterId = $classes->first()['semester_id'] ?? 0;
$scheduleConflicts = App\Models\Schedule::with(['class_.course', 'class_.semester', 'room', 'lecturer'])
    ->where('day', 'Minggu')
    ->where('is_active', true)
    ->where('start_time', '<', '18:00')
    ->where('end_time', '>', '16:20')
    ->whereHas('class_', fn ($query) => $query
        ->where('semester_id', $semesterId)
        ->whereHas('course', fn ($courseQuery) => $courseQuery
            ->where(fn ($deliveryQuery) => $deliveryQuery
                ->whereNull('delivery_mode')
                ->orWhere('delivery_mode', '!=', App\Models\Course::DELIVERY_NON_FACE_TO_FACE))))
    ->get()
    ->map(fn ($schedule) => [
        'schedule_id' => $schedule->id,
        'class_id' => $schedule->class_id,
        'course_code' => $schedule->class_?->course?->code,
        'course' => $schedule->class_?->course?->name,
        'delivery_mode' => $schedule->class_?->course?->delivery_mode,
        'room_id' => $schedule->room_id,
        'room' => $schedule->room?->name,
        'lecturer_id' => $schedule->lecturer_id,
        'lecturer' => $schedule->lecturer?->display_name ?? $schedule->lecturer?->full_name,
        'start' => $schedule->start_time,
        'end' => $schedule->end_time,
    ])->values();

echo json_encode([
    'course' => [
        'id' => $course->id,
        'code' => $course->code,
        'name' => $course->name,
        'delivery_mode' => $course->delivery_mode,
        'semester' => $course->semester,
    ],
    'classes' => $classes,
    'sunday_overlaps' => $scheduleConflicts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
