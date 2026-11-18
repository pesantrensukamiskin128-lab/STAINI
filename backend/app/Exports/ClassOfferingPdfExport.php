<?php

namespace App\Exports;

use App\Models\ClassModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClassOfferingPdfExport
{
    public function __construct(
        private readonly ?int $semesterId = null,
        private readonly ?array $studyProgramIds = null,
        private readonly ?string $search = null,
    ) {}

    public function rows(): Collection
    {
        $classes = ClassModel::query()
            ->with([
                'course', 'offeredCourses', 'lecturer', 'room',
                'schedules.lecturer', 'schedules.room',
            ])
            ->when($this->semesterId, fn (Builder $query) => $query->where('semester_id', $this->semesterId))
            ->when($this->studyProgramIds !== null, fn (Builder $query) => $query->where(function (Builder $programQuery): void {
                $programQuery
                    ->whereIn('classes.study_program_id', $this->studyProgramIds)
                    ->orWhereHas('offeredCourses', fn (Builder $courseQuery) => $courseQuery->whereIn('courses.study_program_id', $this->studyProgramIds));
            }))
            ->when($this->search, fn (Builder $query) => $query->where(function (Builder $searchQuery): void {
                $searchQuery
                    ->where('classes.name', 'like', "%{$this->search}%")
                    ->orWhereHas('offeredCourses', fn (Builder $courseQuery) => $courseQuery
                        ->where('courses.name', 'like', "%{$this->search}%")
                        ->orWhere('courses.code', 'like', "%{$this->search}%"));
            }))
            ->orderBy('semester_id')
            ->orderBy('study_program_id')
            ->orderBy('course_id')
            ->orderBy('name')
            ->get();

        $participantCounts = $this->participantCounts($classes->modelKeys());

        return $classes->values()->map(function (ClassModel $class, int $index) use ($participantCounts): array {
            $courses = $class->offeredCourses->isNotEmpty()
                ? $class->offeredCourses
                : collect([$class->course])->filter();
            $schedules = $class->schedules->where('is_active', true)->values();
            $lecturers = collect([
                $class->lecturer?->display_name ?? $class->lecturer?->full_name,
                ...$schedules->map(fn ($schedule) => $schedule->lecturer?->display_name ?? $schedule->lecturer?->full_name)->all(),
            ])->filter()->unique()->values();
            $scheduleRooms = $schedules
                ->map(fn ($schedule) => $schedule->room?->code ?? $schedule->room?->name ?? $class->room?->code ?? $class->room?->name)
                ->filter()
                ->values();

            return [
                'number' => $index + 1,
                'course_codes' => $courses->pluck('code')->filter()->unique()->implode("\n") ?: '-',
                'course_names' => $courses->pluck('name')->filter()->unique()->implode("\n") ?: '-',
                'lecturer' => $lecturers->implode("\n") ?: '-',
                'days' => $schedules->pluck('day')->filter()->implode("\n") ?: '-',
                'times' => $schedules->map(fn ($schedule) => $this->formatTime($schedule->start_time).' - '.$this->formatTime($schedule->end_time))->implode("\n") ?: '-',
                'rooms' => $scheduleRooms->implode("\n") ?: ($class->room?->code ?? $class->room?->name ?? '-'),
                'offline_count' => $participantCounts[$class->id]['REGULER'] ?? 0,
                'online_count' => $participantCounts[$class->id]['HYBRID'] ?? 0,
            ];
        });
    }

    private function participantCounts(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $classMembers = DB::table('class_members')
            ->whereIn('class_id', $classIds)
            ->select('class_id', 'student_id');
        $approvedKrsStudents = DB::table('krs_details')
            ->join('krs', 'krs.id', '=', 'krs_details.krs_id')
            ->whereIn('krs_details.class_id', $classIds)
            ->where('krs_details.status', 'AKTIF')
            ->where('krs.status', 'APPROVED')
            ->select('krs_details.class_id', 'krs.student_id');

        return DB::query()
            ->fromSub($classMembers->union($approvedKrsStudents), 'participants')
            ->join('students', 'students.id', '=', 'participants.student_id')
            ->whereIn('students.class_type', ['REGULER', 'HYBRID'])
            ->select('participants.class_id', 'students.class_type')
            ->selectRaw('COUNT(DISTINCT participants.student_id) as total')
            ->groupBy('participants.class_id', 'students.class_type')
            ->get()
            ->groupBy('class_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [$row->class_type => (int) $row->total])->all())
            ->all();
    }

    private function formatTime(mixed $time): string
    {
        return $time ? substr((string) $time, 0, 5) : '-';
    }
}
