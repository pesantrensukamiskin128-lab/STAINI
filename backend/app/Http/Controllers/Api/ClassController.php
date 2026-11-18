<?php

namespace App\Http\Controllers\Api;

use App\Exports\ClassOfferingExport;
use App\Exports\ClassOfferingPdfExport;
use App\Http\Controllers\Controller;
use App\Imports\ClassOfferingImport;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\KrsDetail;
use App\Models\Lecturer;
use App\Models\LecturerPosition;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\StudyProgram;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class ClassController extends Controller
{
    public function export(Request $request)
    {
        $allowedProgramIds = $this->spreadsheetProgramIds($request);
        $requestedProgramId = $request->integer('study_program_id') ?: null;
        if ($requestedProgramId && $allowedProgramIds !== null && ! in_array($requestedProgramId, $allowedProgramIds, true)) {
            abort(403, 'Anda tidak berwenang mengekspor penawaran kelas program studi tersebut.');
        }

        return Excel::download(
            new ClassOfferingExport(
                $request->integer('semester_id') ?: null,
                $requestedProgramId ? [$requestedProgramId] : $allowedProgramIds,
                $request->string('search')->trim()->value() ?: null,
            ),
            'penawaran-kelas-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        $allowedProgramIds = $this->spreadsheetProgramIds($request);
        $requestedProgramId = $request->integer('study_program_id') ?: null;
        if ($requestedProgramId && $allowedProgramIds !== null && ! in_array($requestedProgramId, $allowedProgramIds, true)) {
            abort(403, 'Anda tidak berwenang mengekspor penawaran kelas program studi tersebut.');
        }

        $rows = (new ClassOfferingPdfExport(
            $request->integer('semester_id') ?: null,
            $requestedProgramId ? [$requestedProgramId] : $allowedProgramIds,
            $request->string('search')->trim()->value() ?: null,
        ))->rows();
        $semesterName = $request->integer('semester_id')
            ? Semester::find($request->integer('semester_id'))?->name ?? 'Semester terpilih'
            : 'Semua Semester';

        return Pdf::loadView('pdf.class-offerings', [
            'rows' => $rows,
            'semesterName' => $semesterName,
        ])
            ->setPaper('a4', 'landscape')
            ->download('pembagian-kelas-jadwal-'.now()->format('Ymd-His').'.pdf');
    }

    public function import(Request $request): JsonResponse
    {
        $allowedProgramIds = $this->spreadsheetProgramIds($request);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        $import = new ClassOfferingImport;
        Excel::import($import, $request->file('file'));

        $courseRows = $import->courses->rows()->groupBy(fn ($row) => trim((string) ($row['referensi_kelas'] ?? '')));
        $scheduleRows = $import->schedules->rows()->groupBy(fn ($row) => trim((string) ($row['referensi_kelas'] ?? '')));
        $errors = [];
        $processed = 0;
        $seenReferences = [];

        foreach ($import->classes->rows() as $index => $row) {
            $reference = trim((string) ($row['referensi_kelas'] ?? ''));
            $label = $reference !== '' ? $reference : 'baris '.($index + 2);

            try {
                if ($reference === '') {
                    throw new RuntimeException('referensi_kelas wajib diisi.');
                }
                if (isset($seenReferences[$reference])) {
                    throw new RuntimeException('referensi_kelas duplikat pada sheet Kelas.');
                }
                $seenReferences[$reference] = true;

                DB::transaction(function () use ($row, $reference, $courseRows, $scheduleRows, $allowedProgramIds): void {
                    $this->importClassOffering(
                        $row->toArray(),
                        $courseRows->get($reference, collect())->map->toArray()->all(),
                        $scheduleRows->get($reference, collect())->map->toArray()->all(),
                        $scheduleRows->has($reference),
                        $allowedProgramIds,
                    );
                });
                $processed++;
            } catch (\Throwable $exception) {
                $errors[] = "{$label}: {$exception->getMessage()}";
            }
        }

        return response()->json([
            'message' => "Import selesai. {$processed} kelas berhasil diproses."
                .($errors !== [] ? ' Beberapa kelas dilewati.' : ''),
            'processed' => $processed,
            'errors' => $errors,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = ClassModel::with(['course.studyProgram', 'offeredCourses.studyProgram', 'lecturer', 'room', 'semester.academicYear', 'schedules.room', 'schedules.lecturer'])
            ->withCount('members')
            ->when($request->semester_id, fn ($q) => $q->where('semester_id', $request->semester_id))
            ->when($request->study_program_id, fn ($q) => $q->where(function ($programQuery) use ($request) {
                $programQuery
                    ->where('classes.study_program_id', $request->study_program_id)
                    ->orWhereHas('offeredCourses', fn ($courseQuery) => $courseQuery->where('courses.study_program_id', $request->study_program_id));
            }))
            ->when($request->course_id, fn ($q) => $q->whereHas('offeredCourses', fn ($courseQuery) => $courseQuery->where('courses.id', $request->course_id)))
            ->when($request->search, fn ($q) => $q->where(function ($searchQuery) use ($request) {
                $searchQuery
                    ->where('classes.name', 'like', "%{$request->search}%")
                    ->orWhereHas('offeredCourses', fn ($courseQuery) => $courseQuery
                        ->where('courses.name', 'like', "%{$request->search}%")
                        ->orWhere('courses.code', 'like', "%{$request->search}%"));
            }))
            // Dosen hanya melihat kelas yang diampu atau kelas dalam lingkup
            // prodi yang dipimpinnya.
            ->when($user->hasRole('DOSEN') && ! $user->hasRole('SUPER_ADMIN') && ! $user->hasRole('ADMIN_AKADEMIK'), function ($q) use ($user) {
                $lecturer = Lecturer::where('user_id', $user->id)->first();
                if ($lecturer) {
                    $programIds = LecturerPosition::where('lecturer_id', $lecturer->id)
                        ->where('is_active', true)
                        ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                        ->where('scope_type', 'study_program')
                        ->pluck('scope_id');
                    $q->where(function ($access) use ($lecturer, $programIds) {
                        $access->where('lecturer_id', $lecturer->id)
                            ->orWhereHas('lecturers', fn ($team) => $team->whereKey($lecturer->id));
                        if ($programIds->isNotEmpty()) {
                            $access->orWhereIn('study_program_id', $programIds)
                                ->orWhereHas('offeredCourses', fn ($course) => $course->whereIn('courses.study_program_id', $programIds));
                        }
                    });
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->when($user->hasRole('MAHASISWA'), function ($q) use ($user, $request) {
                $studentId = $user->student?->id;
                if (! $studentId) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                // Halaman pembuatan KRS membutuhkan kelas yang belum diambil.
                // Tanpa mode ini mahasiswa tetap hanya melihat kelas yang sudah diikutinya.
                if ($request->boolean('available_for_krs')) {
                    $studyProgramId = $user->student?->study_program_id;
                    $q->where('classes.is_active', true)
                        ->whereHas('offeredCourses', fn ($course) => $course
                            ->where('courses.study_program_id', $studyProgramId)
                            ->where('courses.status', true));

                    return;
                }

                $q->where(function ($access) use ($studentId) {
                    $access->whereHas('members', fn ($member) => $member->where('student_id', $studentId))
                        ->orWhereHas('krsDetails', fn ($detail) => $detail
                            ->where('status', 'AKTIF')
                            ->whereHas('krs', fn ($krs) => $krs->where('student_id', $studentId)));
                });
            })
            ->paginate($request->per_page ?? 20);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $courseId = $request->integer('course_id');
        $course = $courseId ? Course::find($courseId) : null;
        $requiresLogistics = $course?->requiresClassLogistics() ?? true;
        $validated = $request->validate([
            'study_program_id' => 'required|exists:study_programs,id',
            'semester_id' => 'required|exists:semesters,id',
            'course_id' => 'required|exists:courses,id',
            'lecturer_id' => [Rule::excludeIf(! $requiresLogistics), 'nullable', 'exists:lecturers,id'],
            'room_id' => 'nullable|exists:rooms,id',
            'name' => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'academic_level' => 'nullable|integer|min:1|max:8',
            'is_active' => 'boolean',
            // Jadwal (opsional, buat schedule sekaligus)
            'day' => [Rule::excludeIf(! $requiresLogistics), 'nullable', 'in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu'],
            'start_time' => [Rule::excludeIf(! $requiresLogistics), 'nullable', 'date_format:H:i'],
            'end_time' => [Rule::excludeIf(! $requiresLogistics), 'nullable', 'date_format:H:i', 'after:start_time'],
            'offered_courses' => 'nullable|array|min:1|max:20',
            'offered_courses.*.course_id' => 'required|integer|distinct|exists:courses,id',
            'offered_courses.*.quota' => 'nullable|integer|min:1',
            'allow_credit_mismatch' => 'boolean',
        ]);

        $courseIsEligible = Course::query()
            ->whereKey($validated['course_id'])
            ->where('study_program_id', $validated['study_program_id'])
            ->where('status', true)
            ->exists();

        if (! $courseIsEligible) {
            return response()->json([
                'message' => 'Mata kuliah harus berasal dari kurikulum aktif pada program studi yang dipilih.',
            ], 422);
        }

        $primaryCourse = Course::findOrFail($validated['course_id']);
        if (! $primaryCourse->requiresClassLogistics()) {
            $validated['lecturer_id'] = null;
            $validated['room_id'] = null;
            $validated['day'] = null;
            $validated['start_time'] = null;
            $validated['end_time'] = null;
        } elseif (! ($validated['lecturer_id'] ?? null)) {
            return response()->json(['message' => 'Dosen pengampu wajib diisi untuk mata kuliah tatap muka.'], 422);
        }

        $offeredCourses = $validated['offered_courses'] ?? [
            ['course_id' => $validated['course_id'], 'quota' => null],
        ];
        $mappingResult = $this->resolveCourseMappings(
            $offeredCourses,
            (int) $validated['course_id'],
            (int) ($validated['capacity'] ?? 40),
            $request->boolean('allow_credit_mismatch')
        );
        if (isset($mappingResult['error'])) {
            return response()->json($mappingResult['error'], 422);
        }

        // Deteksi konflik jadwal
        if (! empty($validated['day']) && ! empty($validated['start_time']) && ! empty($validated['end_time'])) {
            $conflicts = Schedule::hasConflict(
                $validated['semester_id'],
                $validated['day'],
                $validated['start_time'],
                $validated['end_time'],
                $validated['lecturer_id'],
                $validated['room_id'] ?? null
            );
            if (! empty($conflicts)) {
                return response()->json(['message' => 'Jadwal bentrok!', 'conflicts' => $conflicts], 422);
            }
        }

        $class = DB::transaction(function () use ($validated, $mappingResult) {
            $class = ClassModel::create(collect($validated)->only([
                'study_program_id', 'semester_id', 'course_id', 'lecturer_id', 'room_id',
                'name', 'capacity', 'academic_level', 'is_active',
            ])->toArray());

            $class->offeredCourses()->sync($mappingResult['mappings']);

            // Buat jadwal jika disertakan
            if (! empty($validated['day']) && ! empty($validated['start_time'])) {
                Schedule::create([
                    'class_id' => $class->id,
                    'day' => $validated['day'],
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'room_id' => $validated['room_id'] ?? null,
                    'lecturer_id' => $validated['lecturer_id'],
                ]);
            }

            return $class;
        });

        // Kirim notifikasi ke dosen yang ditugaskan
        if ($class->lecturer_id) {
            $this->notifyLecturerAssigned($class);
        }

        return response()->json([
            'message' => 'Kelas berhasil ditambahkan.',
            'data' => $class->load(['course', 'offeredCourses.studyProgram', 'lecturer', 'room', 'schedules']),
        ], 201);
    }

    // Trigger notifikasi ke dosen
    private function notifyLecturerAssigned(ClassModel $class): void
    {
        $class->loadMissing(['course', 'semester']);
        NotificationService::classAssigned(
            $class->lecturer_id,
            $class->course?->name ?? '-',
            $class->name,
            $class->semester?->name ?? '-'
        );
    }

    public function show(ClassModel $class): JsonResponse
    {
        $user = auth()->user();
        if ($user->hasRole('DOSEN') && ! $user->hasRole('SUPER_ADMIN') && ! $user->hasRole('ADMIN_AKADEMIK')) {
            $lecturer = Lecturer::where('user_id', $user->id)->first();
            $hasProgramScope = $lecturer ? LecturerPosition::where('lecturer_id', $lecturer->id)
                ->where('is_active', true)
                ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                ->where('scope_type', 'study_program')
                ->where('scope_id', $class->study_program_id)
                ->exists() : false;
            $isAssigned = $lecturer && (
                (int) $class->lecturer_id === (int) $lecturer->id
                || $class->lecturers()->whereKey($lecturer->id)->exists()
            );
            if (! $hasProgramScope && ! $isAssigned) {
                return response()->json(['message' => 'Anda bukan pengampu kelas ini.'], 403);
            }
        }

        if ($user->hasRole('MAHASISWA')) {
            $studentId = $user->student?->id;
            $isMember = $studentId && (
                $class->members()->where('student_id', $studentId)->exists()
                || KrsDetail::where('class_id', $class->id)
                    ->where('status', 'AKTIF')
                    ->whereHas('krs', fn ($krs) => $krs->where('student_id', $studentId))
                    ->exists()
            );
            if (! $isMember) {
                return response()->json(['message' => 'Anda tidak terdaftar pada kelas ini.'], 403);
            }
        }

        $relations = ['course.studyProgram', 'offeredCourses.studyProgram', 'lecturer', 'room', 'semester.academicYear', 'schedules.room', 'schedules.lecturer'];
        if (! $user->hasRole('MAHASISWA')) {
            $relations[] = 'members.student';
        }

        return response()->json($class->load($relations));
    }

    public function update(Request $request, ClassModel $class): JsonResponse
    {
        $validated = $request->validate([
            'lecturer_id' => 'sometimes|nullable|exists:lecturers,id',
            'room_id' => 'nullable|exists:rooms,id',
            'name' => 'sometimes|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'academic_level' => 'nullable|integer|min:1|max:8',
            'is_active' => 'boolean',
            'offered_courses' => 'sometimes|array|min:1|max:20',
            'offered_courses.*.course_id' => 'required|integer|distinct|exists:courses,id',
            'offered_courses.*.quota' => 'nullable|integer|min:1',
            'allow_credit_mismatch' => 'boolean',
        ]);

        $mappingResult = null;
        if (array_key_exists('offered_courses', $validated)) {
            $mappingResult = $this->resolveCourseMappings(
                $validated['offered_courses'],
                (int) $class->course_id,
                (int) ($validated['capacity'] ?? $class->capacity),
                $request->boolean('allow_credit_mismatch')
            );
            if (isset($mappingResult['error'])) {
                return response()->json($mappingResult['error'], 422);
            }

            $removalError = $this->courseRemovalError($class, array_keys($mappingResult['mappings']));
            if ($removalError) {
                return $removalError;
            }
        }

        $primaryCourse = $class->course;
        if (! $primaryCourse->requiresClassLogistics()) {
            $validated['lecturer_id'] = null;
            $validated['room_id'] = null;
        }

        DB::transaction(function () use ($class, $validated, $mappingResult): void {
            $class->update(collect($validated)->except(['offered_courses', 'allow_credit_mismatch'])->toArray());
            if (! $class->course->requiresClassLogistics()) {
                $class->schedules()->delete();
            }
            if ($mappingResult) {
                $class->offeredCourses()->sync($mappingResult['mappings']);
            }
        });

        return response()->json([
            'message' => 'Kelas berhasil diupdate.',
            'data' => $class->fresh(['course', 'offeredCourses.studyProgram', 'lecturer', 'room', 'schedules']),
        ]);
    }

    public function syncCourses(Request $request, ClassModel $class): JsonResponse
    {
        $validated = $request->validate([
            'offered_courses' => 'required|array|min:1|max:20',
            'offered_courses.*.course_id' => 'required|integer|distinct|exists:courses,id',
            'offered_courses.*.quota' => 'nullable|integer|min:1',
            'allow_credit_mismatch' => 'boolean',
        ]);

        $mappingResult = $this->resolveCourseMappings(
            $validated['offered_courses'],
            (int) $class->course_id,
            (int) $class->capacity,
            $request->boolean('allow_credit_mismatch')
        );
        if (isset($mappingResult['error'])) {
            return response()->json($mappingResult['error'], 422);
        }

        $removalError = $this->courseRemovalError($class, array_keys($mappingResult['mappings']));
        if ($removalError) {
            return $removalError;
        }

        $class->offeredCourses()->sync($mappingResult['mappings']);

        return response()->json([
            'message' => 'Mata kuliah kelas berhasil diperbarui.',
            'data' => $class->fresh(['course.studyProgram', 'offeredCourses.studyProgram']),
        ]);
    }

    public function destroy(ClassModel $class): JsonResponse
    {
        if ($class->members()->count() > 0) {
            return response()->json(['message' => 'Tidak dapat menghapus kelas yang sudah ada mahasiswa.'], 422);
        }
        $class->delete();

        return response()->json(['message' => 'Kelas berhasil dihapus.']);
    }

    // === JADWAL per KELAS ===

    public function addSchedule(Request $request, ClassModel $class): JsonResponse
    {
        if (! $class->course->requiresClassLogistics()) {
            return response()->json([
                'message' => 'Mata kuliah non-tatap muka tidak memiliki jadwal kelas.',
            ], 422);
        }

        $validated = $request->validate([
            'day' => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'room_id' => 'nullable|exists:rooms,id',
            'lecturer_id' => 'nullable|exists:lecturers,id',
            'note' => 'nullable|string|max:100',
        ]);

        $conflicts = Schedule::hasConflict(
            $class->semester_id,
            $validated['day'],
            $validated['start_time'],
            $validated['end_time'],
            $validated['lecturer_id'] ?? $class->lecturer_id,
            $validated['room_id'] ?? $class->room_id
        );

        if (! empty($conflicts)) {
            return response()->json(['message' => 'Jadwal bentrok!', 'conflicts' => $conflicts], 422);
        }

        $schedule = $class->schedules()->create(array_merge($validated, [
            'lecturer_id' => $validated['lecturer_id'] ?? $class->lecturer_id,
        ]));

        return response()->json(['message' => 'Jadwal berhasil ditambahkan.', 'data' => $schedule], 201);
    }

    public function updateSchedule(Request $request, ClassModel $class, Schedule $schedule): JsonResponse
    {
        abort_unless((int) $schedule->class_id === (int) $class->id, 404);

        if (! $class->course->requiresClassLogistics()) {
            return response()->json([
                'message' => 'Mata kuliah non-tatap muka tidak memiliki jadwal kelas.',
            ], 422);
        }

        $validated = $request->validate([
            'day' => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'room_id' => 'nullable|exists:rooms,id',
            'lecturer_id' => 'nullable|exists:lecturers,id',
            'note' => 'nullable|string|max:100',
        ]);

        $conflicts = Schedule::hasConflict(
            $class->semester_id,
            $validated['day'],
            $validated['start_time'],
            $validated['end_time'],
            $validated['lecturer_id'] ?? null,
            $validated['room_id'] ?? null,
            $schedule->id
        );

        if ($conflicts !== []) {
            return response()->json(['message' => 'Jadwal bentrok!', 'conflicts' => $conflicts], 422);
        }

        $schedule->update($validated);

        return response()->json([
            'message' => 'Jadwal berhasil diperbarui.',
            'data' => $schedule->fresh(['room', 'lecturer']),
        ]);
    }

    public function removeSchedule(ClassModel $class, Schedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json(['message' => 'Jadwal berhasil dihapus.']);
    }

    /** List kelas untuk dropdown */
    public function all(Request $request): JsonResponse
    {
        $classes = ClassModel::with([
            'course:id,study_program_id,code,name,credits,delivery_mode',
            'schedules.room',
            'schedules.lecturer',
            'lecturer',
            'room',
        ])
            ->withCount('members')
            ->where('is_active', true)
            ->when($request->semester_id, fn ($q) => $q->where('semester_id', $request->semester_id))
            ->when($request->study_program_id, fn ($q) => $q->whereHas('offeredCourses', fn ($courseQuery) => $courseQuery->where('courses.study_program_id', $request->study_program_id)))
            ->with('offeredCourses.studyProgram');

        $user = $request->user();
        if ($request->boolean('for_grade_input')
            && $user?->hasRole('DOSEN')
            && ! $user->hasRole('SUPER_ADMIN')
            && ! $user->hasRole('ADMIN_AKADEMIK')) {
            $lecturerId = $user->lecturer?->id;
            $classes->where(function ($query) use ($lecturerId): void {
                if ($lecturerId) {
                    $query->where('lecturer_id', $lecturerId)
                        ->orWhereHas('lecturers', fn ($lecturers) => $lecturers->whereKey($lecturerId));
                } else {
                    $query->whereRaw('1 = 0');
                }
            });
        }

        return response()->json($classes->get());
    }

    /**
     * @param  array<int, array{course_id: int|string, quota?: int|string|null}>  $items
     * @return array{mappings?: array<int, array{curriculum_id: int, is_primary: bool, quota: int|null}>, error?: array<string, mixed>}
     */
    private function resolveCourseMappings(
        array $items,
        int $primaryCourseId,
        int $capacity,
        bool $allowCreditMismatch
    ): array {
        $courseIds = collect($items)->pluck('course_id')->map(fn ($id) => (int) $id)->unique()->values();
        if (! $courseIds->contains($primaryCourseId)) {
            return ['error' => ['message' => 'Mata kuliah utama harus tetap tercantum pada kelas.']];
        }

        $courses = Course::query()
            ->whereIn('id', $courseIds)
            ->where('status', true)
            ->get()
            ->keyBy('id');
        if ($courses->count() !== $courseIds->count()) {
            return ['error' => ['message' => 'Semua mata kuliah kelas harus berstatus aktif.']];
        }

        $primaryCredits = (int) $courses->get($primaryCourseId)->credits;
        $mismatchedCourses = $courses
            ->filter(fn (Course $course) => (int) $course->credits !== $primaryCredits)
            ->values();
        if ($mismatchedCourses->isNotEmpty() && ! $allowCreditMismatch) {
            return ['error' => [
                'message' => 'Terdapat perbedaan SKS antarmata kuliah. Konfirmasi diperlukan untuk melanjutkan.',
                'requires_credit_confirmation' => true,
                'courses' => $mismatchedCourses->map(fn (Course $course) => [
                    'id' => $course->id,
                    'code' => $course->code,
                    'name' => $course->name,
                    'credits' => $course->credits,
                ])->all(),
            ]];
        }

        $itemsByCourse = collect($items)->keyBy(fn (array $item) => (int) $item['course_id']);
        $mappings = [];
        foreach ($courses as $course) {
            $curriculum = Curriculum::query()
                ->where('study_program_id', $course->study_program_id)
                ->where('status', 'Aktif')
                ->whereHas('courses', fn ($query) => $query->whereKey($course->id))
                ->latest('year')
                ->first();
            if (! $curriculum) {
                return ['error' => [
                    'message' => "Mata kuliah {$course->code} tidak tercantum dalam kurikulum aktif prodinya.",
                ]];
            }

            $quota = $itemsByCourse->get($course->id)['quota'] ?? null;
            if ($quota !== null && (int) $quota > $capacity) {
                return ['error' => [
                    'message' => "Kuota {$course->code} tidak boleh melebihi kapasitas kelas.",
                ]];
            }

            $mappings[$course->id] = [
                'curriculum_id' => $curriculum->id,
                'is_primary' => (int) $course->id === $primaryCourseId,
                'quota' => $quota !== null ? (int) $quota : null,
            ];
        }

        $assignedQuota = collect($mappings)->sum(fn (array $mapping) => $mapping['quota'] ?? 0);
        if ($assignedQuota > $capacity) {
            return ['error' => ['message' => 'Jumlah seluruh kuota mata kuliah melebihi kapasitas kelas.']];
        }

        return ['mappings' => $mappings];
    }

    /** @param array<int, int|string> $requestedCourseIds */
    private function courseRemovalError(ClassModel $class, array $requestedCourseIds): ?JsonResponse
    {
        $removedCourseIds = $class->offeredCourses()
            ->pluck('courses.id')
            ->map(fn ($id) => (int) $id)
            ->diff(collect($requestedCourseIds)->map(fn ($id) => (int) $id));

        if ($removedCourseIds->isNotEmpty() && KrsDetail::where('class_id', $class->id)
            ->whereIn('course_id', $removedCourseIds)
            ->where('status', 'AKTIF')
            ->exists()) {
            return response()->json([
                'message' => 'Mata kuliah tidak dapat dilepas karena sudah memiliki peserta pada kelas ini.',
            ], 422);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $courseRows
     * @param  array<int, array<string, mixed>>  $scheduleRows
     */
    private function importClassOffering(
        array $row,
        array $courseRows,
        array $scheduleRows,
        bool $replaceSchedules,
        ?array $allowedProgramIds,
    ): void {
        $semester = null;
        if (! empty($row['id_semester'])) {
            $semester = Semester::find((int) $row['id_semester']);
        }
        if (! $semester && ! empty($row['nama_semester'])) {
            $semester = Semester::where('name', trim((string) $row['nama_semester']))->first();
        }
        if (! $semester) {
            throw new RuntimeException('Semester tidak ditemukan. Isi id_semester atau nama_semester yang valid.');
        }

        $programCode = strtoupper(trim((string) ($row['kode_prodi_penyelenggara'] ?? '')));
        $program = StudyProgram::where('code', $programCode)->first();
        if (! $program) {
            throw new RuntimeException("Program studi {$programCode} tidak ditemukan.");
        }
        if ($allowedProgramIds !== null && ! in_array((int) $program->id, $allowedProgramIds, true)) {
            throw new RuntimeException("Anda tidak berwenang mengimpor kelas untuk prodi {$programCode}.");
        }

        $primaryCourseCode = strtoupper(trim((string) ($row['kode_mk_utama'] ?? '')));
        $primaryCourse = Course::where('code', $primaryCourseCode)
            ->where('study_program_id', $program->id)
            ->first();
        if (! $primaryCourse) {
            throw new RuntimeException("Mata kuliah utama {$primaryCourseCode} tidak ditemukan pada prodi {$programCode}.");
        }

        $name = trim((string) ($row['nama_kelas'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('nama_kelas wajib diisi.');
        }

        $lecturer = $this->resolveLecturerByNidn($row['nidn_dosen_utama'] ?? null, false);
        $room = $this->resolveRoomByCode($row['kode_ruangan_utama'] ?? null, false);
        $nonFaceToFace = ! $primaryCourse->requiresClassLogistics();
        if ($nonFaceToFace) {
            $lecturer = null;
            $room = null;
            $scheduleRows = [];
        }

        $class = ! empty($row['id_kelas']) ? ClassModel::find((int) $row['id_kelas']) : null;
        if (! $class) {
            $class = ClassModel::firstOrNew([
                'semester_id' => $semester->id,
                'course_id' => $primaryCourse->id,
                'name' => $name,
            ]);
        }

        if ($class->exists && ($class->semester_id !== $semester->id || $class->course_id !== $primaryCourse->id)
            && ($class->members()->exists() || KrsDetail::where('class_id', $class->id)->exists())) {
            throw new RuntimeException('Semester atau mata kuliah utama tidak boleh diubah karena kelas sudah memiliki peserta.');
        }

        $capacity = max(1, (int) (($row['kapasitas'] ?? null) ?: 40));
        $mappingInput = [];
        $allowCreditMismatch = false;
        foreach ($courseRows as $courseRow) {
            $code = strtoupper(trim((string) ($courseRow['kode_mata_kuliah'] ?? '')));
            $courseProgramCode = strtoupper(trim((string) ($courseRow['kode_prodi'] ?? '')));
            if ($code === '') {
                continue;
            }

            $courseQuery = Course::where('code', $code);
            if ($courseProgramCode !== '') {
                $courseQuery->whereHas('studyProgram', fn ($query) => $query->where('code', $courseProgramCode));
            }
            $course = $courseQuery->first();
            if (! $course) {
                throw new RuntimeException("Mata kuliah {$code} tidak ditemukan.");
            }

            $mappingInput[$course->id] = [
                'course_id' => $course->id,
                'quota' => $courseRow['kuota'] !== null && $courseRow['kuota'] !== ''
                    ? (int) $courseRow['kuota']
                    : null,
            ];
            $allowCreditMismatch = $allowCreditMismatch
                || $this->spreadsheetBoolean($courseRow['konfirmasi_beda_sks'] ?? false);
        }
        $mappingInput[$primaryCourse->id] ??= ['course_id' => $primaryCourse->id, 'quota' => null];

        $mappingResult = $this->resolveCourseMappings(
            array_values($mappingInput),
            (int) $primaryCourse->id,
            $capacity,
            $allowCreditMismatch,
        );
        if (isset($mappingResult['error'])) {
            throw new RuntimeException((string) $mappingResult['error']['message']);
        }

        if ($class->exists) {
            $removalError = $this->courseRemovalError($class, array_keys($mappingResult['mappings']));
            if ($removalError) {
                throw new RuntimeException((string) ($removalError->getData(true)['message'] ?? 'Mata kuliah kelas tidak dapat dilepas.'));
            }
        }

        $class->fill([
            'study_program_id' => $program->id,
            'semester_id' => $semester->id,
            'course_id' => $primaryCourse->id,
            'lecturer_id' => $lecturer?->id,
            'room_id' => $room?->id,
            'name' => $name,
            'capacity' => $capacity,
            'academic_level' => max(1, min(8, (int) (($row['tingkat'] ?? null) ?: 1))),
            'is_active' => $this->spreadsheetBoolean($row['status'] ?? true),
        ])->save();
        $class->offeredCourses()->sync($mappingResult['mappings']);

        if ($replaceSchedules || $nonFaceToFace) {
            $class->schedules()->delete();
            foreach ($scheduleRows as $scheduleRow) {
                $day = trim((string) ($scheduleRow['hari'] ?? ''));
                if ($day === '') {
                    continue;
                }
                if (! in_array($day, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'], true)) {
                    throw new RuntimeException("Hari {$day} tidak valid.");
                }

                $startTime = $this->spreadsheetTime($scheduleRow['jam_mulai'] ?? null);
                $endTime = $this->spreadsheetTime($scheduleRow['jam_selesai'] ?? null);
                if (! $startTime || ! $endTime || $endTime <= $startTime) {
                    throw new RuntimeException("Jam jadwal {$day} tidak valid.");
                }

                $scheduleLecturer = $this->resolveLecturerByNidn($scheduleRow['nidn_dosen'] ?? null, false) ?? $lecturer;
                $scheduleRoom = $this->resolveRoomByCode($scheduleRow['kode_ruangan'] ?? null, false) ?? $room;
                $conflicts = Schedule::hasConflict(
                    $semester->id,
                    $day,
                    $startTime,
                    $endTime,
                    $scheduleLecturer?->id,
                    $scheduleRoom?->id,
                );
                if ($conflicts !== []) {
                    throw new RuntimeException(implode(' ', $conflicts));
                }

                $class->schedules()->create([
                    'day' => $day,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'lecturer_id' => $scheduleLecturer?->id,
                    'room_id' => $scheduleRoom?->id,
                    'note' => trim((string) ($scheduleRow['catatan'] ?? '')) ?: null,
                    'is_active' => $this->spreadsheetBoolean($scheduleRow['status'] ?? true),
                ]);
            }
        }
    }

    private function resolveLecturerByNidn(mixed $nidn, bool $required): ?Lecturer
    {
        $nidn = trim((string) $nidn);
        if ($nidn === '') {
            if ($required) {
                throw new RuntimeException('NIDN dosen wajib diisi.');
            }

            return null;
        }

        return Lecturer::where('nidn', $nidn)->first()
            ?? throw new RuntimeException("Dosen dengan NIDN {$nidn} tidak ditemukan.");
    }

    private function resolveRoomByCode(mixed $code, bool $required): ?Room
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            if ($required) {
                throw new RuntimeException('Kode ruangan wajib diisi.');
            }

            return null;
        }

        return Room::where('code', $code)->first()
            ?? throw new RuntimeException("Ruangan {$code} tidak ditemukan.");
    }

    private function spreadsheetBoolean(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'ya', 'yes', 'aktif', 'true'], true);
    }

    private function spreadsheetTime(mixed $value): ?string
    {
        if (is_numeric($value)) {
            $seconds = (int) round(((float) $value) * 86400);

            return sprintf('%02d:%02d', intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60));
        }

        $value = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return null;
    }

    /** null berarti administrator boleh mengelola seluruh program studi. */
    private function spreadsheetProgramIds(Request $request): ?array
    {
        $user = $request->user();
        if ($user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            return null;
        }

        $lecturerId = $user?->lecturer?->id;
        $programIds = $lecturerId
            ? LecturerPosition::where('lecturer_id', $lecturerId)
                ->where('is_active', true)
                ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                ->where('scope_type', 'study_program')
                ->whereNotNull('scope_id')
                ->pluck('scope_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        abort_if($programIds === [], 403, 'Fitur impor/ekspor hanya tersedia untuk admin, ketua prodi, dan sekretaris prodi.');

        return $programIds;
    }
}
