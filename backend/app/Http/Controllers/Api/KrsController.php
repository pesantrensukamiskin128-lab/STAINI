<?php

namespace App\Http\Controllers\Api;

use App\Exports\KrsExport;
use App\Helpers\QrCodeHelper;
use App\Http\Controllers\Controller;
use App\Imports\KrsImport;
use App\Models\AppNotification;
use App\Models\ClassMember;
use App\Models\Course;
use App\Models\Institution;
use App\Models\Krs;
use App\Models\KrsDetail;
use App\Models\Lecturer;
use App\Models\LecturerPosition;
use App\Models\Semester;
use App\Models\Student;
use App\Services\KrsEligibilityService;
use App\Services\KrsEnrollmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class KrsController extends Controller
{
    public function __construct(
        private readonly KrsEnrollmentService $enrollmentService,
        private readonly KrsEligibilityService $eligibilityService,
    ) {}

    public function eligibility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => 'required|exists:semesters,id',
            'student_id' => 'nullable|exists:students,id',
        ]);
        $user = $request->user();
        $student = $user->hasRole('MAHASISWA')
            ? $user->student
            : Student::find($validated['student_id'] ?? null);

        if (! $student) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan.'], 404);
        }

        if (! $user->hasRole('MAHASISWA')) {
            $programIds = $this->administrativeProgramIds($request);
            abort_unless($programIds === null || in_array((int) $student->study_program_id, $programIds, true), 403);
        }

        return response()->json($this->eligibilityService->evaluate(
            $student,
            Semester::findOrFail($validated['semester_id'])
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Krs::with(['student.studyProgram', 'semester.academicYear', 'advisor'])
            ->when($request->semester_id, fn ($q) => $q->where('semester_id', $request->semester_id))
            ->when($request->status, fn ($q) => $q->whereDisplayStatus($request->status))
            ->when($request->search, fn ($q) => $q->whereHas('student', fn ($q2) => $q2->where('name', 'like', "%{$request->search}%")
                ->orWhere('nim', 'like', "%{$request->search}%")));

        // Mahasiswa hanya lihat KRS sendiri; pimpinan prodi hanya lingkup prodinya.
        if ($user->hasRole('MAHASISWA') && $user->student) {
            $query->where('student_id', $user->student->id);
        } elseif (! $user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            if ($user->hasRole('DOSEN')) {
                $query->whereHas('semester.academicYear', fn ($academicYearQuery) => $academicYearQuery->where('is_active', true));
            }
            $programIds = $this->positionProgramIds($user);
            if ($programIds !== []) {
                $query->whereHas('student', fn ($studentQuery) => $studentQuery->whereIn('study_program_id', $programIds));
            } else {
                $query->where('advisor_id', $user->lecturer?->id ?? 0);
            }
        }

        if ($request->study_program_id) {
            $query->whereHas('student', fn ($studentQuery) => $studentQuery->where('study_program_id', $request->study_program_id));
        }

        if ($request->entry_year) {
            $query->whereHas('student', fn ($studentQuery) => $studentQuery->where('entry_year', $request->entry_year));
        }

        if ($request->current_semester) {
            $query->whereHas('student', fn ($studentQuery) => $studentQuery->where('current_semester', $request->current_semester));
        }

        return response()->json($query->orderByDesc('created_at')->paginate($request->per_page ?? 20));
    }

    public function prodiReviewIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $isAdmin = $user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']);
        $programIds = $isAdmin ? [] : $this->positionProgramIds($user);
        abort_unless($isAdmin || $programIds !== [], 403, 'Fitur review KRS hanya tersedia untuk admin dan pimpinan prodi.');

        $validated = $request->validate([
            'queue' => 'nullable|in:PENDING,REJECTED',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $queue = $validated['queue'] ?? 'PENDING';
        $query = Krs::with(['student.studyProgram', 'semester.academicYear', 'advisor'])
            ->whereHas('student', fn ($studentQuery) => $isAdmin
                ? $studentQuery
                : $studentQuery->whereIn('study_program_id', $programIds));

        if ($queue === 'PENDING') {
            $query->where(function ($pending) {
                $pending->where('status', 'APPROVED')
                    ->orWhere(function ($unassigned) {
                        $unassigned->where('status', 'SUBMITTED')
                            ->whereNull('advisor_id');
                    });
            })->whereNull('kaprodi_approved_at')
                ->whereNull('signed_by_kaprodi_at');
        } else {
            $query->where('status', 'REJECTED')
                ->where('rejected_by_stage', 'KAPRODI');
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('student', fn ($studentQuery) => $studentQuery->where(function ($studentSearch) use ($search) {
                $studentSearch->where('name', 'like', "%{$search}%")
                    ->orWhere('nim', 'like', "%{$search}%");
            }));
        }

        return response()->json(
            $query->orderByDesc('submitted_at')->orderByDesc('id')
                ->paginate($validated['per_page'] ?? 25)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'nullable|exists:students,id',
            'semester_id' => 'required|exists:semesters,id',
        ]);

        $user = auth()->user();
        if ($user->hasRole('MAHASISWA')) {
            $studentId = $user->student?->id;
        } else {
            $allowedProgramIds = $this->administrativeProgramIds($request);
            $studentId = $validated['student_id'] ?? null;
            if ($studentId) {
                $student = Student::find($studentId);
                abort_unless($student && ($allowedProgramIds === null || in_array((int) $student->study_program_id, $allowedProgramIds, true)), 403);
            }
        }

        if (! $studentId) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan.'], 404);
        }

        $existing = Krs::where('student_id', $studentId)
            ->where('semester_id', $validated['semester_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'KRS untuk semester ini sudah ada.', 'data' => $existing], 200);
        }

        $student = Student::find($studentId);
        if (! $student || $student->status !== 'Aktif') {
            return response()->json(['message' => 'KRS hanya dapat dibuat untuk mahasiswa berstatus Aktif.'], 422);
        }

        if ($user->hasRole('MAHASISWA')) {
            try {
                $semester = Semester::findOrFail($validated['semester_id']);
                $this->eligibilityService->assertPeriodOpen($student, $semester);
                $this->eligibilityService->assertFinanciallyClear($student, $semester);
            } catch (DomainException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        $krs = Krs::create([
            'student_id' => $studentId,
            'semester_id' => $validated['semester_id'],
            'advisor_id' => $student->advisor_id,
            'status' => 'DRAFT',
        ]);

        return response()->json(['message' => 'KRS berhasil dibuat.', 'data' => $krs->load(['student', 'semester'])], 201);
    }

    public function show(Krs $krs): JsonResponse
    {
        $krs->load([
            'student.studyProgram',
            'semester.academicYear',
            'advisor',
            'kaprodiApprover',
            'details.course',
            'details.class_.lecturer',
            'details.class_.room',
            'details.class_.schedules.room',
        ]);
        $krs->setAttribute('schedule_conflicts', $this->enrollmentService->scheduleConflicts($krs));

        return response()->json($krs);
    }

    public function addCourse(Request $request, Krs $krs): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'class_id' => 'nullable|exists:classes,id',
        ]);

        $this->authorizeKrsMutation($request, $krs);
        try {
            $this->assertStudentKrsPeriod($request, $krs);
            $this->enrollmentService->addCourse(
                $krs,
                (int) $request->course_id,
                $request->filled('class_id') ? (int) $request->class_id : null,
                false,
                $this->isKrsAdmin($request),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Mata kuliah berhasil ditambahkan ke KRS.']);
    }

    public function removeCourse(Krs $krs, KrsDetail $detail): JsonResponse
    {
        $this->authorizeKrsMutation(request(), $krs);
        try {
            $this->assertStudentKrsPeriod(request(), $krs);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if (! $this->isKrsAdmin(request()) && ! in_array($krs->status, ['DRAFT', 'REJECTED'])) {
            return response()->json(['message' => 'KRS sudah disubmit, tidak dapat diubah.'], 422);
        }
        abort_unless((int) $detail->krs_id === (int) $krs->id, 404);

        DB::transaction(function () use ($krs, $detail) {
            // Hapus dari class_members jika ada class_id
            $otherActiveCourseInClass = $detail->class_id && KrsDetail::query()
                ->where('krs_id', $krs->id)
                ->where('class_id', $detail->class_id)
                ->where('status', 'AKTIF')
                ->whereKeyNot($detail->id)
                ->exists();
            if ($detail->class_id && ! $otherActiveCourseInClass) {
                ClassMember::where('class_id', $detail->class_id)
                    ->where('student_id', $krs->student_id)
                    ->delete();
            }
            $detail->delete();
            $krs->recalculateCredits();
        });

        return response()->json(['message' => 'Mata kuliah berhasil dihapus dari KRS.']);
    }

    public function studentOptions(Request $request): JsonResponse
    {
        $programIds = $this->administrativeProgramIds($request);
        $students = Student::query()
            ->with('studyProgram:id,code,name')
            ->where('status', 'Aktif')
            ->when($programIds !== null, fn ($query) => $query->whereIn('study_program_id', $programIds))
            ->when($request->search, fn ($query) => $query->where(function ($searchQuery) use ($request) {
                $searchQuery->where('nim', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%");
            }))
            ->orderBy('nim')
            ->limit(100)
            ->get(['id', 'study_program_id', 'nim', 'name']);

        return response()->json($students);
    }

    public function export(Request $request)
    {
        $programIds = $this->administrativeProgramIds($request);
        $requestedProgramId = $request->integer('study_program_id') ?: null;
        if ($requestedProgramId && $programIds !== null && ! in_array($requestedProgramId, $programIds, true)) {
            abort(403, 'Anda tidak berwenang mengekspor KRS program studi tersebut.');
        }

        return Excel::download(new KrsExport(
            $request->integer('semester_id') ?: null,
            $requestedProgramId ? [$requestedProgramId] : $programIds,
            $request->string('status')->trim()->value() ?: null,
            $request->string('search')->trim()->value() ?: null,
            $request->integer('entry_year') ?: null,
            $request->integer('current_semester') ?: null,
        ), 'krs-mahasiswa-'.now()->format('Ymd-His').'.xlsx');
    }

    public function import(Request $request): JsonResponse
    {
        $programIds = $this->administrativeProgramIds($request);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);
        $isAdmin = $this->isKrsAdmin($request);

        $import = new KrsImport;
        Excel::import($import, $request->file('file'));
        $groups = $import->rows()->groupBy(function ($row) {
            $nim = trim((string) ($row['nim'] ?? ''));
            $semester = trim((string) (($row['id_semester'] ?? null) ?: ($row['nama_semester'] ?? '')));

            return $nim.'|'.$semester;
        });

        $errors = [];
        $processedKrs = 0;
        $addedCourses = 0;
        foreach ($groups as $key => $rows) {
            try {
                $groupAdded = DB::transaction(function () use ($rows, $programIds, $isAdmin): int {
                    $groupAdded = 0;
                    $first = $rows->first();
                    $nim = trim((string) ($first['nim'] ?? ''));
                    if ($nim === '') {
                        throw new RuntimeException('NIM wajib diisi.');
                    }
                    $student = Student::where('nim', $nim)->first();
                    if (! $student) {
                        throw new RuntimeException("Mahasiswa {$nim} tidak ditemukan.");
                    }
                    if ($student->status !== 'Aktif') {
                        throw new RuntimeException("Mahasiswa {$nim} tidak berstatus Aktif.");
                    }
                    if ($programIds !== null && ! in_array((int) $student->study_program_id, $programIds, true)) {
                        throw new RuntimeException("Anda tidak berwenang membuat KRS mahasiswa {$nim}.");
                    }

                    $semester = $this->resolveImportSemester($first->toArray());
                    $krs = Krs::firstOrCreate(
                        ['student_id' => $student->id, 'semester_id' => $semester->id],
                        ['advisor_id' => $student->advisor_id, 'status' => 'DRAFT']
                    );
                    if (! $isAdmin && ! in_array($krs->status, ['DRAFT', 'REJECTED'], true)) {
                        throw new RuntimeException("KRS {$nim} sudah berstatus {$krs->display_status_label} dan tidak dapat diubah.");
                    }

                    foreach ($rows as $row) {
                        $courseCode = strtoupper(trim((string) ($row['kode_mata_kuliah'] ?? '')));
                        if ($courseCode === '') {
                            continue;
                        }
                        $course = Course::where('code', $courseCode)
                            ->where('study_program_id', $student->study_program_id)
                            ->first();
                        if (! $course) {
                            throw new RuntimeException("Mata kuliah {$courseCode} tidak ditemukan pada prodi mahasiswa.");
                        }
                        if (empty($row['id_kelas'])) {
                            throw new RuntimeException("id_kelas wajib diisi untuk mata kuliah {$courseCode}.");
                        }
                        if ($this->enrollmentService->addCourse($krs, $course->id, (int) $row['id_kelas'], true, $isAdmin)) {
                            $groupAdded++;
                        }
                    }

                    return $groupAdded;
                });
                $processedKrs++;
                $addedCourses += $groupAdded;
            } catch (\Throwable $exception) {
                $errors[] = "{$key}: {$exception->getMessage()}";
            }
        }

        return response()->json([
            'message' => "Import selesai. {$processedKrs} KRS diproses dan {$addedCourses} mata kuliah ditambahkan."
                .($errors !== [] ? ' Beberapa mahasiswa dilewati.' : ''),
            'processed' => $processedKrs,
            'courses_added' => $addedCourses,
            'errors' => $errors,
        ]);
    }

    public function submit(Krs $krs): JsonResponse
    {
        $this->authorizeKrsMutation(request(), $krs);
        if (! in_array($krs->status, ['DRAFT', 'REJECTED'])) {
            return response()->json(['message' => 'KRS sudah disubmit.'], 422);
        }
        if ($krs->total_credits === 0) {
            return response()->json(['message' => 'KRS kosong, belum ada mata kuliah.'], 422);
        }

        $scheduleConflicts = $this->enrollmentService->scheduleConflicts($krs);
        if ($scheduleConflicts !== []) {
            return response()->json([
                'message' => 'Jadwal mata kuliah pada KRS bentrok. Ubah kelas atau hapus salah satu mata kuliah sebelum mengajukan KRS.',
                'schedule_conflicts' => $scheduleConflicts,
            ], 422);
        }

        $krs->loadMissing(['student', 'semester']);
        try {
            $this->assertStudentKrsPeriod(request(), $krs);
            $this->eligibilityService->assertFinanciallyClear($krs->student, $krs->semester);
        } catch (DomainException $exception) {
            return response()->json(['message' => 'Tidak dapat mengajukan KRS. '.$exception->getMessage()], 422);
        }

        $krs->update([
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
            'kaprodi_approved_at' => null,
            'kaprodi_approved_by' => null,
            'kaprodi_note' => null,
            'rejected_by_stage' => null,
        ]);

        // Notifikasi ke dosen wali
        $krs->loadMissing(['student', 'advisor']);
        if ($krs->advisor?->user_id) {
            AppNotification::send(
                $krs->advisor->user_id,
                'Pengajuan KRS Baru',
                "Mahasiswa {$krs->student?->name} ({$krs->student?->nim}) mengajukan KRS ({$krs->total_credits} SKS). Mohon direview.",
                'warning',
                '/akademik/krs/'.$krs->id
            );
        }

        return response()->json([
            'message' => $krs->advisor_id
                ? 'KRS berhasil disubmit ke dosen wali.'
                : 'KRS berhasil disubmit dan menunggu review program studi karena belum memiliki dosen wali.',
        ]);
    }

    public function approve(Request $request, Krs $krs): JsonResponse
    {
        $this->authorizeKrsReview($request, $krs);
        if ($krs->status !== 'SUBMITTED') {
            return response()->json(['message' => 'Hanya KRS berstatus SUBMITTED yang bisa disetujui.'], 422);
        }

        $krs->loadMissing(['student', 'semester']);
        try {
            $this->eligibilityService->assertFinanciallyClear($krs->student, $krs->semester);
        } catch (DomainException $exception) {
            return response()->json(['message' => 'KRS belum dapat disetujui. '.$exception->getMessage()], 422);
        }

        $krs->update([
            'status' => 'APPROVED',
            'advisor_note' => $request->note ?? null,
            'approved_at' => now(),
            'rejected_by_stage' => null,
        ]);

        // Pastikan semua krs_details yang punya class_id sudah masuk class_members
        $krs->details()->whereNotNull('class_id')->where('status', 'AKTIF')->each(function ($detail) use ($krs) {
            ClassMember::firstOrCreate([
                'class_id' => $detail->class_id,
                'student_id' => $krs->student_id,
            ]);
        });

        // Notifikasi ke mahasiswa
        $krs->loadMissing('student');
        if ($krs->student?->user_id) {
            AppNotification::send(
                $krs->student->user_id,
                'KRS Disetujui ✓',
                "KRS Anda ({$krs->total_credits} SKS) telah disetujui oleh dosen wali.",
                'success',
                '/akademik/krs-saya'
            );
        }

        return response()->json(['message' => 'KRS berhasil disetujui.']);
    }

    public function reject(Request $request, Krs $krs): JsonResponse
    {
        $this->authorizeKrsReview($request, $krs);
        $request->validate(['note' => 'required|string']);

        if ($krs->status !== 'SUBMITTED') {
            return response()->json(['message' => 'Hanya KRS berstatus SUBMITTED yang bisa ditolak.'], 422);
        }

        $krs->update([
            'status' => 'REJECTED',
            'advisor_note' => $request->note,
            'rejected_by_stage' => 'ADVISOR',
        ]);

        // Notifikasi ke mahasiswa
        $krs->loadMissing('student');
        if ($krs->student?->user_id) {
            AppNotification::send(
                $krs->student->user_id,
                'KRS Ditolak',
                "KRS Anda ditolak oleh dosen wali. Catatan: \"{$request->note}\". Silakan perbaiki dan submit ulang.",
                'error',
                '/akademik/krs-saya'
            );
        }

        return response()->json(['message' => 'KRS ditolak.']);
    }

    public function approveByKaprodi(Request $request, Krs $krs): JsonResponse
    {
        $this->authorizeKaprodiReview($request, $krs);
        $request->validate(['note' => 'nullable|string|max:2000']);

        $directProdiReview = $krs->status === 'SUBMITTED' && ! $krs->advisor_id;
        if ($krs->status !== 'APPROVED' && ! $directProdiReview) {
            return response()->json(['message' => 'KRS harus disetujui dosen wali sebelum direview prodi.'], 422);
        }
        if ($krs->signed_by_kaprodi_at) {
            return response()->json(['message' => 'KRS sudah ditandatangani Kaprodi.'], 422);
        }
        if ($krs->kaprodi_approved_at) {
            return response()->json(['message' => 'KRS sudah disetujui pada tahap prodi.'], 422);
        }

        $krs->update([
            'status' => 'APPROVED',
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $request->user()->id,
            'kaprodi_note' => $request->note,
        ]);

        $krs->loadMissing('student');
        if ($krs->student?->user_id) {
            AppNotification::send(
                $krs->student->user_id,
                'KRS Disetujui Prodi',
                $directProdiReview
                    ? 'KRS Anda disetujui langsung oleh program studi karena belum memiliki dosen wali; KRS menunggu tanda tangan Kaprodi.'
                    : 'KRS Anda telah disetujui pada tahap program studi dan menunggu tanda tangan Kaprodi.',
                'success',
                '/akademik/krs-saya'
            );
        }

        return response()->json(['message' => 'KRS berhasil disetujui pada tahap prodi.']);
    }

    public function rejectByKaprodi(Request $request, Krs $krs): JsonResponse
    {
        $this->authorizeKaprodiReview($request, $krs);
        $validated = $request->validate(['note' => 'required|string|max:2000']);

        $directProdiReview = $krs->status === 'SUBMITTED' && ! $krs->advisor_id;
        if ($krs->status !== 'APPROVED' && ! $directProdiReview) {
            return response()->json(['message' => 'Hanya KRS yang telah disetujui dosen wali yang dapat ditolak prodi.'], 422);
        }
        if ($krs->signed_by_kaprodi_at) {
            return response()->json(['message' => 'KRS sudah ditandatangani Kaprodi.'], 422);
        }
        if ($krs->kaprodi_approved_at) {
            return response()->json(['message' => 'KRS yang sudah disetujui prodi tidak dapat ditolak.'], 422);
        }

        $krs->update([
            'status' => 'REJECTED',
            'kaprodi_note' => $validated['note'],
            'rejected_by_stage' => 'KAPRODI',
        ]);

        $krs->loadMissing('student');
        if ($krs->student?->user_id) {
            AppNotification::send(
                $krs->student->user_id,
                'KRS Ditolak Prodi',
                "KRS Anda ditolak pada tahap program studi. Catatan: \"{$validated['note']}\". Silakan perbaiki dan submit ulang.",
                'error',
                '/akademik/krs-saya'
            );
        }

        return response()->json(['message' => 'KRS ditolak pada tahap prodi.']);
    }

    /** Kaprodi tandatangan KRS */
    public function signByKaprodi(Krs $krs): JsonResponse
    {
        $user = request()->user();
        $krs->loadMissing('student');
        if (! $user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            $lecturerId = $user?->lecturer?->id;
            $canSign = $lecturerId && LecturerPosition::where('lecturer_id', $lecturerId)
                ->where('position_code', 'KAPRODI')
                ->where('scope_type', 'study_program')
                ->where('scope_id', $krs->student?->study_program_id)
                ->where('is_active', true)
                ->exists();
            abort_unless($canSign, 403, 'Hanya Ketua Prodi mahasiswa yang dapat menandatangani KRS.');
        }
        if ($krs->status !== 'APPROVED') {
            return response()->json(['message' => 'KRS harus sudah disetujui dosen wali sebelum ditandatangani Kaprodi.'], 422);
        }

        if (! $krs->kaprodi_approved_at) {
            return response()->json(['message' => 'KRS harus disetujui Kaprodi atau Sekretaris Prodi sebelum ditandatangani.'], 422);
        }

        if ($krs->signed_by_kaprodi_at) {
            return response()->json(['message' => 'KRS sudah ditandatangani Kaprodi.'], 422);
        }

        $krs->update([
            'signed_by_kaprodi_at' => now(),
            'signed_by_kaprodi_id' => auth()->id(),
        ]);

        // Notifikasi ke mahasiswa
        $krs->loadMissing('student');
        if ($krs->student?->user_id) {
            AppNotification::send(
                $krs->student->user_id,
                'KRS Ditandatangani Kaprodi ✓',
                'KRS Anda telah ditandatangani oleh Ketua Program Studi. Dokumen KRS sudah final.',
                'success',
                '/akademik/krs-saya'
            );
        }

        return response()->json(['message' => 'KRS berhasil ditandatangani.']);
    }

    /** Download PDF KRS */
    public function downloadPdf(Krs $krs)
    {
        $krs->load([
            'student.studyProgram.faculty',
            'semester.academicYear',
            'advisor',
            'details.course',
            'details.class_.lecturer',
            'details.class_.room',
            'details.class_.schedules.room',
        ]);

        $institution = Institution::first();
        $logoPath = $institution?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($institution->logo_path) : null;

        // Kaprodi
        $prodiId = $krs->student?->study_program_id;
        $kaprodi = null;
        if ($prodiId) {
            $pos = LecturerPosition::where('position_code', 'KAPRODI')
                ->where('scope_type', 'study_program')->where('scope_id', $prodiId)
                ->where('is_active', true)->first();
            if ($pos) {
                $kaprodi = Lecturer::find($pos->lecturer_id);
            }
        }

        // QR codes via helper (biru + logo untuk footer)
        $verifyUrl = rtrim(config('app.frontend_url'), '/')."/verify/krs/{$krs->id}";
        $qrKaprodi = QrCodeHelper::generate($verifyUrl.'?signer=kaprodi', 120);
        $qrAdvisor = QrCodeHelper::generate($verifyUrl.'?signer=dosen_wali', 120);
        $qrStudent = QrCodeHelper::generate($verifyUrl.'?signer=mahasiswa', 120);
        $qrFooter = QrCodeHelper::generateWithLogo($verifyUrl, 160);

        $pdf = Pdf::loadView('pdf.krs', [
            'krs' => $krs,
            'institution' => $institution,
            'logoPath' => $logoPath,
            'kaprodi' => $kaprodi,
            'qrKaprodi' => $qrKaprodi,
            'qrAdvisor' => $qrAdvisor,
            'qrStudent' => $qrStudent,
            'qrFooter' => $qrFooter,
            'verifyUrl' => $verifyUrl,
        ])->setPaper('a4', 'portrait')->setOption('isRemoteEnabled', true);

        return $pdf->download("KRS-{$krs->student?->nim}-".str_replace(['/', '\\'], '-', $krs->semester?->name ?? '').'.pdf');
    }

    public function destroy(Krs $krs): JsonResponse
    {
        $user = auth()->user();
        $this->authorizeKrsMutation(request(), $krs);

        // SUPER_ADMIN bisa hapus semua KRS
        if (! $user->hasRole('SUPER_ADMIN') && $krs->status === 'APPROVED') {
            return response()->json(['message' => 'KRS yang sudah disetujui hanya bisa dihapus oleh Super Admin.'], 422);
        }

        DB::transaction(function () use ($krs): void {
            $classIds = $krs->details()->whereNotNull('class_id')->pluck('class_id')->unique();
            $krs->details()->delete();
            $krs->delete();

            foreach ($classIds as $classId) {
                $stillEnrolled = KrsDetail::query()
                    ->where('class_id', $classId)
                    ->where('status', 'AKTIF')
                    ->whereHas('krs', fn ($query) => $query->where('student_id', $krs->student_id))
                    ->exists();
                if (! $stillEnrolled) {
                    ClassMember::where('class_id', $classId)
                        ->where('student_id', $krs->student_id)
                        ->delete();
                }
            }
        });

        return response()->json(['message' => 'KRS berhasil dihapus.']);
    }

    private function resolveImportSemester(array $row): Semester
    {
        $semester = ! empty($row['id_semester']) ? Semester::find((int) $row['id_semester']) : null;
        if (! $semester && ! empty($row['nama_semester'])) {
            $semester = Semester::where('name', trim((string) $row['nama_semester']))->first();
        }

        return $semester ?? throw new RuntimeException('Semester tidak ditemukan. Isi id_semester atau nama_semester yang valid.');
    }

    private function isKrsAdmin(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']);
    }

    private function authorizeKrsMutation(Request $request, Krs $krs): void
    {
        $user = $request->user();
        $krs->loadMissing('student');
        if ($user?->hasRole('MAHASISWA')) {
            abort_unless((int) $user->student?->id === (int) $krs->student_id, 403);

            return;
        }

        $programIds = $this->administrativeProgramIds($request);
        abort_unless($programIds === null || in_array((int) $krs->student?->study_program_id, $programIds, true), 403);
    }

    private function authorizeKrsReview(Request $request, Krs $krs): void
    {
        $user = $request->user();
        if ($user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            return;
        }

        abort_unless(
            $user?->lecturer && (int) $krs->advisor_id === (int) $user->lecturer->id,
            403,
            'KRS hanya dapat direview oleh dosen wali mahasiswa.'
        );
    }

    private function authorizeKaprodiReview(Request $request, Krs $krs): void
    {
        $user = $request->user();
        if ($user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            return;
        }

        $krs->loadMissing('student');
        $lecturerId = $user?->lecturer?->id;
        abort_unless($lecturerId && LecturerPosition::where('lecturer_id', $lecturerId)
            ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
            ->where('scope_type', 'study_program')
            ->where('scope_id', $krs->student?->study_program_id)
            ->where('is_active', true)
            ->exists(), 403, 'Hanya Kaprodi atau Sekretaris Prodi mahasiswa yang dapat mereview KRS.');
    }

    private function assertStudentKrsPeriod(Request $request, Krs $krs): void
    {
        if (! $request->user()?->hasRole('MAHASISWA')) {
            return;
        }

        $krs->loadMissing(['student', 'semester']);
        $this->eligibilityService->assertPeriodOpen($krs->student, $krs->semester);
        $this->eligibilityService->assertFinanciallyClear($krs->student, $krs->semester);
    }

    /** null berarti administrator boleh mengelola semua prodi. */
    private function administrativeProgramIds(Request $request): ?array
    {
        $user = $request->user();
        if ($user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            return null;
        }

        $programIds = $this->positionProgramIds($user);
        abort_if($programIds === [], 403, 'Fitur pembuatan dan impor/ekspor KRS hanya tersedia untuk admin, ketua prodi, dan sekretaris prodi.');

        return $programIds;
    }

    private function positionProgramIds(mixed $user): array
    {
        $lecturerId = $user?->lecturer?->id;
        if (! $lecturerId) {
            return [];
        }

        return LecturerPosition::where('lecturer_id', $lecturerId)
            ->where('is_active', true)
            ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
            ->where('scope_type', 'study_program')
            ->whereNotNull('scope_id')
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
