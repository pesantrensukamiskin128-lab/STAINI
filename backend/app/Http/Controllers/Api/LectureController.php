<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassAnnouncement;
use App\Models\ClassGradeWeight;
use App\Models\ClassModel;
use App\Models\Discussion;
use App\Models\DiscussionReply;
use App\Models\Exam;
use App\Models\LectureJournal;
use App\Models\LectureMaterial;
use App\Models\AppNotification;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use App\Services\ClassSubCpmkResolver;
use App\Exports\ClassParticipantsExport;
use App\Exports\ClassJournalsExport;
use App\Helpers\QrCodeHelper;
use App\Support\OperationalDocumentVerification;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class LectureController extends Controller
{
    // =============================================
    // JURNAL PERKULIAHAN
    // =============================================

    /**
     * GET /lectures/{class} — detail kelas (dipakai ClassDetailView)
     */
    public function show(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);
        $class->load(['course', 'semester', 'lecturer', 'room', 'schedules.room'])
              ->loadCount('members');
        return response()->json($class);
    }

    public function memberList(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);

        // Merge the membership table with active KRS details to include every contracted student.
        $members = $class->members()->with('student:id,nim,name,class_type')->orderBy('id')->get();

        $krsMembers = \App\Models\KrsDetail::where('class_id', $class->id)
            ->where('status', 'AKTIF')
            ->with(['krs.student:id,nim,name,class_type'])
            ->get()
            ->map(fn ($detail) => (object) [
                'student_id' => $detail->krs?->student_id,
                'student' => $detail->krs?->student,
            ])
            ->filter(fn ($member) => $member->student_id)
            ->values();

        $members = $members->concat($krsMembers)
            ->unique('student_id')
            ->values();

        $studentId = auth()->user()->student?->id;
        if ($studentId) {
            $members = $members->where('student_id', $studentId)->values();
        }

        return response()->json($members);
    }

    public function memberExcel(ClassModel $class)
    {
        $this->authorizeClassExport($class);

        return Excel::download(
            $this->participantExport($class),
            'presensi-kelas-'.$class->id.'.xlsx'
        );
    }

    public function memberPdf(ClassModel $class)
    {
        $this->authorizeClassExport($class);
        $export = $this->participantExport($class);

        return Pdf::loadView('pdf.class-participants', [
            'class' => $class,
            'institution' => Institution::first(),
            'headers' => $export->headers(),
            'attendanceRows' => $export->attendanceRows(),
            'lecturerName' => $export->lecturerName(),
            'headLecturerName' => $export->headLecturerName(),
            'verifyUrl' => $export->verificationUrl(),
            'qrHeadLecturer' => $export->qrHeadLecturer(),
            'qrLecturer' => $export->qrLecturer(),
            'qrVerification' => $export->qrVerification(),
        ])
            ->setPaper('a3', 'landscape')
            ->download('presensi-kelas-'.$class->id.'.pdf');
    }

    private function participantExport(ClassModel $class): ClassParticipantsExport
    {
        $class->load([
            'course',
            'semester.academicYear',
            'lecturer',
            'studyProgram.faculty',
            'studyProgram.headLecturer',
            'schedules.lecturer',
        ]);
        $verifyUrl = $this->classVerificationUrl($class, 'class-participants');

        return new ClassParticipantsExport(
            $class,
            $this->participantStudents($class),
            $this->journalsForParticipantExport($class),
            $verifyUrl,
            Institution::first(),
            QrCodeHelper::generate($verifyUrl.'?signer=kaprodi', 140),
            QrCodeHelper::generate($verifyUrl.'?signer=dosen', 140),
            QrCodeHelper::generateWithLogo($verifyUrl, 140),
        );
    }

    public function journalExcel(ClassModel $class)
    {
        $this->authorizeClassExport($class);
        $class->load(['course', 'semester', 'lecturer']);
        $journals = $this->journalsForExport($class);
        $verifyUrl = $this->classVerificationUrl($class, 'class-journals');

        return Excel::download(
            new ClassJournalsExport($class, $journals, $verifyUrl),
            'jurnal-pembelajaran-'.$class->id.'.xlsx'
        );
    }

    public function journalPdf(ClassModel $class)
    {
        $this->authorizeClassExport($class);
        $class->load(['course', 'semester', 'lecturer']);
        $journals = $this->journalsForExport($class);
        $verifyUrl = $this->classVerificationUrl($class, 'class-journals');

        return Pdf::loadView('pdf.class-journals', [
            'class' => $class,
            'journals' => $journals,
            'verifyUrl' => $verifyUrl,
            'qrVerification' => QrCodeHelper::generateWithLogo($verifyUrl, 160),
        ])
            ->setPaper('a4', 'landscape')
            ->download('jurnal-pembelajaran-'.$class->id.'.pdf');
    }

    private function participantStudents(ClassModel $class)
    {
        $memberIds = $class->members()->pluck('student_id');
        $krsIds = \App\Models\KrsDetail::query()
            ->where('class_id', $class->id)
            ->where('krs_details.status', 'AKTIF')
            ->whereHas('krs', fn ($query) => $query->where('status', 'APPROVED'))
            ->join('krs', 'krs.id', '=', 'krs_details.krs_id')
            ->pluck('krs.student_id');

        return \App\Models\Student::with('studyProgram')
            ->whereIn('id', $memberIds->merge($krsIds)->unique())
            ->orderBy('name')
            ->get();
    }

    private function journalsForExport(ClassModel $class)
    {
        return LectureJournal::where('class_id', $class->id)
            ->withCount(['attendances as present_count' => fn ($query) => $query->where('status', 'HADIR')])
            ->orderBy('meeting_number')
            ->get();
    }

    private function journalsForParticipantExport(ClassModel $class)
    {
        return LectureJournal::where('class_id', $class->id)
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'CANCELLED'))
            ->with(['attendances:id,journal_id,student_id,status'])
            ->orderBy('meeting_number')
            ->orderBy('id')
            ->get();
    }

    private function classVerificationUrl(ClassModel $class, string $type): string
    {
        $token = OperationalDocumentVerification::issue($type, (int) $class->id, 'class-'.$class->id);
        return rtrim((string) config('app.frontend_url'), '/').'/verify/'.$type.'/'.$token;
    }

    public function journalList(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);

        $journals = LectureJournal::where('class_id', $class->id)
            ->withCount([
                'attendances',
                'attendances as present_count' => fn ($query) => $query->where('status', 'HADIR'),
            ])
            ->orderBy('meeting_number')
            ->get()
            ->each(function (LectureJournal $journal): void {
                $journal->setAttribute(
                    'photo_url',
                    $journal->photo_path
                        ? url(Storage::disk('public')->url($journal->photo_path))
                        : null
                );
            });
        return response()->json($journals);
    }

    public function storeJournal(Request $request, ClassModel $class): JsonResponse
    {
        $this->authorizeClassManage($class);

        $validated = $request->validate([
            'meeting_number'    => 'required|integer|min:1|max:16',
            'meeting_date'      => 'required|date',
            'topic'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'learning_activity' => 'nullable|string',
            'delivery_mode'     => 'required|in:ONSITE,ONLINE',
            'status'            => 'nullable|in:PLANNED,COMPLETED,CANCELLED',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
        ]);

        $validated['class_id'] = $class->id;
        $validated['lecturer_id'] = $class->lecturer_id;

        $journal = LectureJournal::query()
            ->where('class_id', $class->id)
            ->where('meeting_number', $validated['meeting_number'])
            ->first();
        abort_if($journal?->review_status === 'APPROVED', 409, 'Jurnal yang sudah disetujui tidak dapat diubah.');

        // Upload foto jika ada
        if ($request->hasFile('photo')) {
            $request->validate(['photo' => 'image|mimes:jpeg,png,webp|max:5120']);
            $validated['photo_path'] = $request->file('photo')->store('journals', 'public');
        }
        $reviewStatus = ($validated['status'] ?? $journal?->status ?? 'PLANNED') === 'COMPLETED'
            ? 'PENDING'
            : 'NOT_SUBMITTED';
        $journal ??= new LectureJournal();
        $journal->fill($validated);
        $journal->class_id = $class->id;
        $journal->lecturer_id = $class->lecturer_id;
        $journal->review_status = $reviewStatus;
        $journal->reviewed_by = null;
        $journal->reviewed_at = null;
        $journal->review_note = null;
        $journal->save();

        return response()->json(['message' => 'Jurnal berhasil disimpan.', 'data' => $journal], 201);
    }

    /** Ambil data pertemuan dari RPS/RPKPS untuk auto-fill jurnal */
    public function getFromRps(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);

        $courseIds = $class->offeredCourses()->pluck('courses.id')
            ->push($class->course_id)
            ->filter()
            ->unique()
            ->values();

        $documents = \App\Models\Rpkps::query()
            ->with(['course:id,code,name', 'weeklyPlans', 'cpmks.subCpmks'])
            ->whereIn('course_id', $courseIds)
            ->whereIn('status', ['DISETUJUI', 'DIKUNCI', 'DIARSIPKAN'])
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('course_id')
            ->map(function ($items) use ($class) {
                return $items->sortByDesc(fn ($item) => sprintf(
                    '%d%d%010d',
                    (int) $item->semester_id === (int) $class->semester_id,
                    (int) $item->lecturer_id === (int) $class->lecturer_id,
                    $item->updated_at?->timestamp ?? 0,
                ))->first();
            })
            ->values();

        if ($documents->isEmpty()) {
            return response()->json(['message' => 'Tidak ada RPS yang disetujui untuk mata kuliah ini.', 'plans' => []], 404);
        }

        $sources = $documents->map(function ($rpkps) {
            $subCpmkByCode = $rpkps->cpmks
                ->flatMap(fn ($cpmk) => $cpmk->subCpmks)
                ->keyBy(fn ($subCpmk) => strtoupper(trim((string) $subCpmk->code)));

            return [
                'id' => $rpkps->id,
                'rpkps_code' => $rpkps->code,
                'course_id' => $rpkps->course_id,
                'course_code' => $rpkps->course?->code,
                'course_name' => $rpkps->course?->name,
                'semester_id' => $rpkps->semester_id,
                'plans' => $rpkps->weeklyPlans
                    ->sortBy('week_number')
                    ->values()
                    ->map(function ($week) use ($subCpmkByCode): array {
                        $subCpmkLabel = collect(preg_split('/[|,;\r\n]+/', (string) $week->sub_cpmk))
                            ->map(function ($code) use ($subCpmkByCode): string {
                                $code = trim((string) $code);
                                $subCpmk = $subCpmkByCode->get(strtoupper($code));
                                return $subCpmk ? $subCpmk->code.' - '.$subCpmk->description : $code;
                            })
                            ->filter()
                            ->implode('; ');

                        return [
                            'id' => $week->id,
                            'meeting_number' => $week->week_number,
                            'topic' => $subCpmkLabel ?: ($week->learning_material ?: ''),
                            'sub_cpmk' => $subCpmkLabel,
                            'description' => $week->learning_material ?? '',
                            'learning_activity' => is_array($week->methods)
                                ? implode(', ', $week->methods)
                                : ($week->methods ?? ''),
                        ];
                    })->all(),
            ];
        })->values();

        $primary = $sources->firstWhere('course_id', $class->course_id) ?? $sources->first();

        return response()->json([
            'rpkps_code' => $primary['rpkps_code'],
            'plans' => $primary['plans'],
            'sources' => $sources,
        ]);
    }

    // =============================================
    // PRESENSI
    // =============================================

    public function attendanceList(LectureJournal $journal): JsonResponse
    {
        $class = $journal->class_;
        $this->authorizeClassView($class);

        $attendances = Attendance::where('journal_id', $journal->id)
            ->when(auth()->user()->student?->id, fn ($query, $studentId) => $query->where('student_id', $studentId))
            ->with('student:id,nim,name')
            ->get();
        return response()->json($attendances);
    }

    public function openAttendanceSession(Request $request, LectureJournal $journal): JsonResponse
    {
        $this->authorizeClassManage($journal->class_);

        $validated = $request->validate([
            'opens_at' => 'nullable|date',
            'closes_at' => 'required|date|after:opens_at',
        ]);

        $opensAt = $validated['opens_at'] ?? now();
        if (now()->parse($opensAt)->isPast() && now()->parse($validated['closes_at'])->isPast()) {
            throw ValidationException::withMessages([
                'closes_at' => 'Waktu penutupan harus berada di masa mendatang.',
            ]);
        }

        $token = Str::upper(Str::random(8));
        $journal->update([
            'attendance_token_hash' => hash('sha256', $token),
            'attendance_opens_at' => $opensAt,
            'attendance_closes_at' => $validated['closes_at'],
        ]);
        $this->notifyClassStudents(
            $journal->class_,
            'Sesi Presensi Dibuka',
            "Presensi pertemuan {$journal->meeting_number} telah dibuka.",
            'jurnal'
        );

        return response()->json([
            'message' => 'Sesi presensi berhasil dibuka.',
            'token' => $token,
            'opens_at' => $journal->attendance_opens_at,
            'closes_at' => $journal->attendance_closes_at,
        ]);
    }

    public function closeAttendanceSession(LectureJournal $journal): JsonResponse
    {
        $this->authorizeClassManage($journal->class_);
        $journal->update(['attendance_closes_at' => now()]);

        return response()->json(['message' => 'Sesi presensi berhasil ditutup.']);
    }

    public function checkInAttendance(Request $request, LectureJournal $journal): JsonResponse
    {
        $validated = $request->validate(['token' => 'required|string|size:8']);
        $this->authorizeClassView($journal->class_);

        $student = auth()->user()->student;
        if (!$student || !$this->classStudentIds($journal->class_)->contains((int) $student->id)) {
            return response()->json(['message' => 'Anda bukan peserta kelas ini.'], 403);
        }

        $now = now();
        if (!$journal->attendance_token_hash
            || !$journal->attendance_opens_at
            || !$journal->attendance_closes_at
            || $now->lt($journal->attendance_opens_at)
            || $now->gt($journal->attendance_closes_at)
            || !hash_equals($journal->attendance_token_hash, hash('sha256', Str::upper($validated['token'])))) {
            return response()->json(['message' => 'Token presensi tidak valid atau sesi sudah tidak aktif.'], 422);
        }

        Attendance::updateOrCreate(
            ['journal_id' => $journal->id, 'student_id' => $student->id],
            ['status' => 'HADIR', 'method' => 'TOKEN', 'checked_in_at' => $now]
        );

        return response()->json(['message' => 'Presensi berhasil dicatat.', 'checked_in_at' => $now]);
    }

    public function saveAttendance(Request $request, LectureJournal $journal): JsonResponse
    {
        $class = $journal->class_;
        $this->authorizeClassManage($class);

        $validated = $request->validate([
            'attendances'             => 'required|array|min:1',
            'attendances.*.student_id'=> 'required|integer|distinct|exists:students,id',
            'attendances.*.status'    => 'required|in:HADIR,IZIN,SAKIT,ALFA',
        ]);

        $allowedStudentIds = $this->classStudentIds($class);
        $submittedStudentIds = collect($validated['attendances'])->pluck('student_id')->map(fn ($id) => (int) $id);
        $invalidStudentIds = $submittedStudentIds->diff($allowedStudentIds);
        if ($invalidStudentIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'attendances' => 'Presensi hanya dapat diisi untuk mahasiswa yang terdaftar pada kelas ini.',
            ]);
        }

        $changedUserIds = DB::transaction(function () use ($journal, $validated) {
            $changedUserIds = [];
            foreach ($validated['attendances'] as $a) {
                $attendance = Attendance::firstOrNew([
                    'journal_id' => $journal->id,
                    'student_id' => $a['student_id'],
                ]);
                $statusChanged = ! $attendance->exists || $attendance->status !== $a['status'];
                $attendance->status = $a['status'];
                $attendance->method = 'MANUAL';
                $attendance->checked_in_at ??= now();
                $attendance->save();
                if ($statusChanged) {
                    $attendance->loadMissing('student');
                    if ($attendance->student?->user_id) {
                        $changedUserIds[] = [
                            'user_id' => (int) $attendance->student->user_id,
                            'status' => $attendance->status,
                        ];
                    }
                }
            }

            return $changedUserIds;
        });
        foreach ($changedUserIds as $changedAttendance) {
            AppNotification::send(
                $changedAttendance['user_id'],
                'Presensi Perkuliahan Diperbarui',
                "Status presensi pertemuan {$journal->meeting_number} diperbarui menjadi {$changedAttendance['status']}.",
                'info',
                '/perkuliahan/'.$class->id.'?tab=jurnal'
            );
        }

        return response()->json(['message' => 'Presensi berhasil disimpan.']);
    }

    // =============================================
    // MATERI
    // =============================================

    public function materialList(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);
        $materials = LectureMaterial::where('class_id', $class->id)
            ->when(auth()->user()->student, fn ($query) => $query
                ->where('is_published', true)
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now())))
            ->orderByDesc('created_at')
            ->get()
            ->each(function (LectureMaterial $material): void {
                if ($material->file_path) {
                    $material->file_url = url(Storage::disk('public')->url($material->file_path));
                }
            });

        return response()->json($materials);
    }

    public function storeMaterial(Request $request, ClassModel $class): JsonResponse
    {
        $this->authorizeClassManage($class);
        $validated = $request->validate([
            'journal_id'   => [
                'nullable',
                'exists:lecture_journals,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($class): void {
                    if ($value && !LectureJournal::where('id', $value)->where('class_id', $class->id)->exists()) {
                        $fail('Pertemuan tidak termasuk dalam kelas ini.');
                    }
                },
            ],
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'file_url'     => 'nullable|string|max:500',
            'material_type'=> 'required|in:FILE,LINK,VIDEO,TEXT',
            'available_at' => 'nullable|date',
            'is_published' => 'sometimes|boolean',
            'file'         => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,mp4,webm,jpg,jpeg,png|max:20480',
        ]);
        if ($validated['material_type'] === 'FILE' && !$request->hasFile('file')) {
            throw ValidationException::withMessages(['file' => 'File wajib diunggah untuk materi bertipe file.']);
        }
        if ($request->hasFile('file')) {
            $validated['file_path'] = $request->file('file')->store('lecture-materials', 'public');
            $validated['file_type'] = $request->file('file')->getClientOriginalExtension();
            unset($validated['file']);
        }
        $validated['class_id'] = $class->id;
        $validated['created_by'] = auth()->id();

        $material = LectureMaterial::create($validated);
        if (!empty($validated['is_published'])) {
            $this->notifyClassStudents($class, 'Materi Baru', "Materi \"{$material->title}\" telah dipublikasikan.", 'materi');
        }
        return response()->json(['message' => 'Materi berhasil ditambahkan.', 'data' => $material], 201);
    }

    public function destroyMaterial(LectureMaterial $material): JsonResponse
    {
        $this->authorizeClassManage($material->class_);
        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }
        $material->delete();
        return response()->json(['message' => 'Materi berhasil dihapus.']);
    }

    public function updateMaterial(Request $request, LectureMaterial $material): JsonResponse
    {
        $this->authorizeClassManage($material->class_);
        $validated = $request->validate([
            'journal_id' => [
                'nullable',
                'exists:lecture_journals,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($material): void {
                    if ($value && !LectureJournal::where('id', $value)->where('class_id', $material->class_id)->exists()) {
                        $fail('Pertemuan tidak termasuk dalam kelas materi ini.');
                    }
                },
            ],
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'file_url' => 'nullable|string|max:500',
            'material_type' => 'sometimes|in:FILE,LINK,VIDEO,TEXT',
            'available_at' => 'nullable|date',
            'is_published' => 'sometimes|boolean',
            'file' => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,mp4,webm,jpg,jpeg,png|max:20480',
        ]);
        if ($request->hasFile('file')) {
            if ($material->file_path) {
                Storage::disk('public')->delete($material->file_path);
            }
            $validated['file_path'] = $request->file('file')->store('lecture-materials', 'public');
            $validated['file_type'] = $request->file('file')->getClientOriginalExtension();
            unset($validated['file']);
        }
        $wasPublished = $material->is_published;
        $material->update($validated);
        if (! $wasPublished && ! empty($validated['is_published'])) {
            $this->notifyClassStudents(
                $material->class_,
                'Materi Baru',
                "Materi \"{$material->title}\" telah dipublikasikan.",
                'materi'
            );
        }
        return response()->json(['message' => 'Materi berhasil diupdate.', 'data' => $material->fresh()]);
    }

    // =============================================
    // TUGAS
    // =============================================

    public function assignmentList(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);
        $student = auth()->user()->student;
        $assignments = Assignment::where('class_id', $class->id)
            ->when($student, fn ($query) => $query->where('is_published', true))
            ->withCount('submissions')
            ->with('subCpmks:id,code,description,rpkps_cpmk_id')
            ->with(['submissions' => fn ($query) => $query
                ->when($student, fn ($query) => $query->where('student_id', $student->id))
                ->select(['id', 'assignment_id', 'student_id', 'content', 'file_url', 'score', 'feedback', 'submitted_at', 'graded_at'])])
            ->orderByDesc('created_at')->get()
            ->each(function (Assignment $assignment): void {
                if ($assignment->attachment_path) {
                    $assignment->attachment_url = url(Storage::disk('public')->url($assignment->attachment_path));
                }
            });
        return response()->json($assignments);
    }

    public function gradebook(ClassModel $class): JsonResponse
    {
        $this->authorizeClassManage($class);

        $configuration = $class->gradeWeights()->first();
        $components = $configuration?->components ?: ClassGradeWeight::DEFAULT_COMPONENTS;
        $subCpmks = app(ClassSubCpmkResolver::class)->forClass($class);
        $cpmks = $subCpmks
            ->groupBy(fn (array $subCpmk): int => $subCpmk['cpmk']['id'])
            ->map(fn (Collection $items): array => [
                ...$items->first()['cpmk'],
                'sub_cpmks' => $items->map(fn (array $item): array => [
                    'id' => $item['id'],
                    'code' => $item['code'],
                    'description' => $item['description'],
                ])->values()->all(),
            ])->values();
        $assignments = Assignment::where('class_id', $class->id)
            ->where('is_published', true)
            ->with('subCpmks:id,code,description,rpkps_cpmk_id')
            ->orderBy('created_at')
            ->get(['id', 'title', 'max_score', 'due_date']);
        $submissions = AssignmentSubmission::whereIn('assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy(fn (AssignmentSubmission $submission): string =>
                $submission->student_id.':'.$submission->assignment_id
            );
        $exams = Exam::query()
            ->where('class_id', $class->id)
            ->where('is_published', true)
            ->with('subCpmks:id,code,description,rpkps_cpmk_id')
            ->orderBy('start_time')
            ->get(['id', 'class_id', 'title', 'type', 'start_time', 'end_time']);
        $examMaxScores = DB::table('exam_questions')
            ->whereIn('exam_id', $exams->pluck('id'))
            ->select('exam_id')
            ->selectRaw('SUM(score) as max_score')
            ->groupBy('exam_id')
            ->pluck('max_score', 'exam_id');
        $examSessions = DB::table('exam_sessions')
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get(['exam_id', 'student_id', 'total_score', 'status', 'finished_at'])
            ->keyBy(fn ($session): string => $session->student_id.':'.$session->exam_id);
        $completedJournalIds = DB::table('lecture_journals')
            ->where('class_id', $class->id)
            ->where('status', 'COMPLETED')
            ->pluck('id');
        $presentCounts = DB::table('attendances')
            ->whereIn('journal_id', $completedJournalIds)
            ->where('status', 'HADIR')
            ->select('student_id')
            ->selectRaw('COUNT(*) as present_count')
            ->groupBy('student_id')
            ->pluck('present_count', 'student_id');

        $students = $this->participantStudents($class)->map(function ($student) use (
            $components,
            $subCpmks,
            $cpmks,
            $assignments,
            $submissions,
            $exams,
            $examMaxScores,
            $examSessions,
            $completedJournalIds,
            $presentCounts
        ): array {
            $grades = [];
            $outcomeActivityScores = $subCpmks->mapWithKeys(
                fn (array $subCpmk): array => [$subCpmk['id'] => ['scores' => [], 'pending' => 0]]
            )->all();
            $activityScores = [
                'tasks' => [],
                'uts' => [],
                'uas' => [],
            ];
            $pendingComponents = ['tasks' => 0, 'uts' => 0, 'uas' => 0];
            foreach ($assignments as $assignment) {
                $submission = $submissions->get($student->id.':'.$assignment->id);
                $due = $assignment->due_date ? Carbon::parse($assignment->due_date) : null;
                $isOverdue = $due && $due->lte(now());
                $score = $submission?->score;
                $componentKey = 'tasks';
                $outcomeScore = null;
                if ($score !== null && $assignment->max_score > 0) {
                    $activityScores[$componentKey][] = ((float) $score / $assignment->max_score) * 100;
                    $outcomeScore = ((float) $score / $assignment->max_score) * 100;
                } elseif (!$submission && $isOverdue && $assignment->max_score > 0) {
                    $activityScores[$componentKey][] = 0;
                    $outcomeScore = 0;
                } else {
                    $pendingComponents[$componentKey]++;
                }
                foreach ($assignment->subCpmks as $subCpmk) {
                    if ($outcomeScore !== null) {
                        $outcomeActivityScores[$subCpmk->id]['scores'][] = $outcomeScore;
                    } else {
                        $outcomeActivityScores[$subCpmk->id]['pending']++;
                    }
                }
                $grades[] = [
                    'assignment_id' => $assignment->id,
                    'score' => $score ?? ($isOverdue && !$submission ? 0 : null),
                    'submission_id' => $submission?->id,
                    'submitted_at' => $submission?->submitted_at,
                    'graded_at' => $submission?->graded_at,
                    'is_missing' => !$submission && $isOverdue,
                ];
            }

            $examScores = [];
            foreach ($exams as $exam) {
                $session = $examSessions->get($student->id.':'.$exam->id);
                $maxScore = (float) ($examMaxScores[$exam->id] ?? 0);
                $isFinished = $exam->end_time && Carbon::parse($exam->end_time)->lte(now());
                $componentKey = match ($exam->type) {
                    'UTS' => 'uts',
                    'UAS' => 'uas',
                    'QUIZ', 'TUGAS_BESAR' => 'tasks',
                    default => null,
                };
                $score = null;
                $percentage = null;
                if ($componentKey !== null && (!$isFinished || $maxScore <= 0)) {
                    $pendingComponents[$componentKey]++;
                } elseif ($componentKey !== null && $session && $session->status !== 'IN_PROGRESS' && $session->total_score === null) {
                    $pendingComponents[$componentKey]++;
                } elseif ($componentKey !== null) {
                    $score = !$session || $session->status === 'IN_PROGRESS' ? 0 : (float) $session->total_score;
                    $percentage = ($score / $maxScore) * 100;
                    $activityScores[$componentKey][] = $percentage;
                }
                foreach ($exam->subCpmks as $subCpmk) {
                    if ($percentage !== null) {
                        $outcomeActivityScores[$subCpmk->id]['scores'][] = $percentage;
                    } else {
                        $outcomeActivityScores[$subCpmk->id]['pending']++;
                    }
                }
                $examScores[] = [
                    'exam_id' => $exam->id,
                    'type' => $exam->type,
                    'score' => $score,
                    'max_score' => $maxScore,
                    'percentage' => $percentage,
                    'finished_at' => $session?->finished_at,
                ];
            }

            $meetingCount = $completedJournalIds->count();
            $presentCount = (int) ($presentCounts[$student->id] ?? 0);
            $attendancePercentage = $meetingCount > 0 ? ($presentCount / $meetingCount) * 100 : null;
            $componentScores = [
                'attendance' => $attendancePercentage === null ? null : round($attendancePercentage, 2),
                'tasks' => count($activityScores['tasks']) ? round(array_sum($activityScores['tasks']) / count($activityScores['tasks']), 2) : null,
                'uts' => count($activityScores['uts']) ? round(array_sum($activityScores['uts']) / count($activityScores['uts']), 2) : null,
                'uas' => count($activityScores['uas']) ? round(array_sum($activityScores['uas']) / count($activityScores['uas']), 2) : null,
            ];
            $componentResults = [];
            $pendingActivities = 0;
            foreach ($components as $component) {
                $key = $component['key'];
                $score = $componentScores[$key] ?? null;
                $componentResults[$key] = $score;
                $pendingActivities += $pendingComponents[$key] ?? 0;
            }
            $hasMissingComponent = collect($componentResults)->contains(fn ($score): bool => $score === null);
            $outcomeResults = [];
            foreach ($outcomeActivityScores as $subCpmkId => $result) {
                $outcomeResults[$subCpmkId] = [
                    'score' => count($result['scores']) > 0
                        ? round(array_sum($result['scores']) / count($result['scores']), 2)
                        : null,
                    'evidence_count' => count($result['scores']),
                    'pending_activities' => $result['pending'],
                ];
            }
            $cpmkResults = [];
            foreach ($cpmks as $cpmk) {
                $subResults = collect($cpmk['sub_cpmks'])
                    ->map(fn (array $subCpmk): array => $outcomeResults[$subCpmk['id']])
                    ->filter(fn (array $result): bool => $result['score'] !== null);
                $cpmkResults[$cpmk['id']] = [
                    'score' => $subResults->isNotEmpty()
                        ? round((float) $subResults->avg('score'), 2)
                        : null,
                    'evidence_count' => $subResults->sum('evidence_count'),
                    'pending_activities' => collect($cpmk['sub_cpmks'])
                        ->sum(fn (array $subCpmk): int => $outcomeResults[$subCpmk['id']]['pending_activities']),
                ];
            }

            return [
                'id' => $student->id,
                'nim' => $student->nim,
                'name' => $student->name,
                'grades' => $grades,
                'exam_scores' => $examScores,
                'component_scores' => $componentResults,
                'outcome_scores' => $outcomeResults,
                'cpmk_scores' => $cpmkResults,
                'attendance' => [
                    'present' => $presentCount,
                    'meetings' => $meetingCount,
                    'percentage' => $attendancePercentage === null ? null : round($attendancePercentage, 2),
                ],
                'is_provisional' => $hasMissingComponent || $pendingActivities > 0,
                'pending_activities' => $pendingActivities,
            ];
        })->values();

        return response()->json([
            'components' => $components,
            'sub_cpmks' => $subCpmks,
            'cpmks' => $cpmks,
            'assignments' => $assignments->map(fn (Assignment $assignment): array => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'max_score' => $assignment->max_score,
                'due_date' => $assignment->due_date,
                'sub_cpmk_ids' => $assignment->subCpmks->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            ]),
            'exams' => $exams->map(fn (Exam $exam): array => [
                'id' => $exam->id,
                'title' => $exam->title,
                'type' => $exam->type,
                'start_time' => $exam->start_time,
                'end_time' => $exam->end_time,
                'sub_cpmk_ids' => $exam->subCpmks->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            ]),
            'students' => $students,
        ]);
    }

    public function classSubCpmks(ClassModel $class): JsonResponse
    {
        $this->authorizeClassManage($class);

        return response()->json(app(ClassSubCpmkResolver::class)->forClass($class));
    }

    public function updateGradebookWeights(Request $request, ClassModel $class): JsonResponse
    {
        $this->authorizeClassManage($class);
        $validated = $request->validate([
            'components' => 'required|array|size:4',
            'components.*.key' => 'required|in:attendance,tasks,uts,uas',
            'components.*.name' => 'required|string|max:60',
            'components.*.source' => 'required|in:attendance,tasks,uts,uas',
            'components.*.weight' => 'required|integer|min:0|max:100',
        ]);
        $components = collect($validated['components']);
        if ($components->pluck('key')->unique()->count() !== 4
            || $components->pluck('source')->unique()->count() !== 4
            || $components->contains(fn (array $component): bool => $component['key'] !== $component['source'])
            || $components->sum('weight') !== 100) {
            throw ValidationException::withMessages([
                'components' => ['Gunakan satu kali setiap komponen Presensi, Tugas, UTS, dan UAS; jumlah bobot harus tepat 100%.'],
            ]);
        }

        $componentValues = $components->map(fn (array $component): array => [
            'key' => $component['key'],
            'name' => trim($component['name']),
            'weight' => (int) $component['weight'],
            'source' => $component['source'],
        ])->values()->all();
        $weightsByKey = collect($componentValues)->keyBy('key');
        $weights = ClassGradeWeight::updateOrCreate(
            ['class_id' => $class->id],
            [
                'assignment_weight' => $weightsByKey['tasks']['weight'],
                'exam_weight' => $weightsByKey['uts']['weight'] + $weightsByKey['uas']['weight'],
                'attendance_weight' => $weightsByKey['attendance']['weight'],
                'components' => $componentValues,
            ]
        );

        return response()->json([
            'message' => 'Komponen dan bobot nilai kelas berhasil disimpan.',
            'components' => $weights->components,
        ]);
    }

    public function assignmentSubmissions(ClassModel $class, Assignment $assignment): JsonResponse
    {
        $this->authorizeClassManage($class);
        abort_unless((int) $assignment->class_id === (int) $class->id, 404);

        return response()->json(
            $assignment->submissions()
                ->with('student:id,nim,name')
                ->orderByDesc('submitted_at')
                ->get()
        );
    }

    public function storeAssignment(Request $request, ClassModel $class): JsonResponse
    {
        $this->authorizeClassManage($class);
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'instructions' => 'nullable|string',
            'attachment'  => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip,jpg,jpeg,png,mp4,webm|max:20480',
            'type'         => 'nullable|in:INDIVIDU,KELOMPOK',
            'due_date'     => 'nullable|date',
            'max_score'    => 'nullable|integer|min:1',
            'is_published' => 'boolean',
            'allow_late'   => 'boolean',
            'sub_cpmk_ids' => 'sometimes|array',
            'sub_cpmk_ids.*' => 'integer|distinct',
        ]);
        $subCpmkIds = $validated['sub_cpmk_ids'] ?? [];
        app(ClassSubCpmkResolver::class)->assertBelongsToClass($class, $subCpmkIds);
        unset($validated['sub_cpmk_ids']);
        if ($request->hasFile('attachment')) {
            $validated['attachment_path'] = $request->file('attachment')->store('assignment-attachments', 'public');
            $validated['attachment_type'] = $request->file('attachment')->getClientMimeType();
            unset($validated['attachment']);
        }
        $validated['class_id'] = $class->id;

        $assignment = DB::transaction(function () use ($validated, $subCpmkIds): Assignment {
            $assignment = Assignment::create($validated);
            $assignment->subCpmks()->sync($subCpmkIds);

            return $assignment;
        });
        if (!empty($validated['is_published'])) {
            $this->notifyClassStudents($class, 'Tugas Baru', "Tugas \"{$assignment->title}\" telah dipublikasikan.", 'tugas');
        }
        return response()->json(['message' => 'Tugas berhasil dibuat.', 'data' => $assignment], 201);
    }

    public function submitAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $assignment->loadMissing('class_');
        $this->authorizeClassView($assignment->class_);
        $student = auth()->user()->student;
        if (!$student) return response()->json(['message' => 'Akun bukan mahasiswa.'], 403);
        if (! $this->classStudentIds($assignment->class_)->contains((int) $student->id)) {
            abort(403, 'Anda tidak terdaftar pada kelas ini.');
        }
        if (!$assignment->is_published) {
            return response()->json(['message' => 'Tugas belum dipublikasikan.'], 422);
        }
        $request->validate([
            'content'  => 'nullable|string',
            'file_url' => 'nullable|string|max:500',
            'file' => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip,jpg,jpeg,png|max:20480',
        ]);
        if (!$request->filled('content') && !$request->filled('file_url') && !$request->hasFile('file')) {
            throw ValidationException::withMessages(['content' => 'Isi jawaban atau file wajib disertakan.']);
        }
        if ($assignment->due_date?->isPast() && !$assignment->allow_late) {
            return response()->json(['message' => 'Batas pengumpulan tugas sudah lewat.'], 422);
        }

        $submission = AssignmentSubmission::firstOrNew(
            ['assignment_id' => $assignment->id, 'student_id' => $student->id]
        );
        if ($request->hasFile('file')) {
            if ($submission->file_path) {
                Storage::disk('public')->delete($submission->file_path);
            }
            $submission->file_path = $request->file('file')->store('assignment-submissions', 'public');
            $submission->file_url = url(Storage::disk('public')->url($submission->file_path));
        } elseif ($request->filled('file_url')) {
            $submission->file_url = $request->string('file_url')->toString();
        }
        $submission->content = $request->input('content');
        $submission->submitted_at = now();
        $submission->save();
        $assignment->loadMissing('class_.lecturer');
        if ($assignment->class_?->lecturer?->user_id) {
            AppNotification::send($assignment->class_->lecturer->user_id, 'Pengumpulan Tugas Baru',
                "Mahasiswa {$student->name} mengumpulkan tugas \"{$assignment->title}\".",                 'info', '/perkuliahan/'.$assignment->class_id.'?tab=tugas');
        }

        return response()->json(['message' => 'Tugas berhasil dikumpulkan.', 'data' => $submission]);
    }

    public function gradeSubmission(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $submission->loadMissing('assignment.class_');
        $this->authorizeClassManage($submission->assignment->class_);
        $request->validate(['score' => 'required|numeric|min:0|max:'.$submission->assignment->max_score, 'feedback' => 'nullable|string']);
        $submission->update([
            'score' => $request->score, 'feedback' => $request->feedback,
            'graded_at' => now(), 'graded_by' => auth()->id(),
        ]);
        $submission->loadMissing('student', 'assignment');
        if ($submission->student?->user_id) {
            AppNotification::send($submission->student->user_id, 'Tugas Dinilai',
                "Tugas \"{$submission->assignment?->title}\" telah dinilai.".($request->feedback ? " Feedback: {$request->feedback}" : ''),
                'success', '/perkuliahan/'.$submission->assignment->class_id.'?tab=tugas');
        }
        return response()->json(['message' => 'Nilai berhasil disimpan.']);
    }

    // =============================================
    // PENGUMUMAN
    // =============================================

    public function announcementList(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);
        return response()->json(
            ClassAnnouncement::where('class_id', $class->id)->with('user:id,name')->orderByDesc('created_at')->get()
        );
    }

    public function storeAnnouncement(Request $request, ClassModel $class): JsonResponse
    {
        $validated = $request->validate(['title' => 'required|string', 'content' => 'required|string']);
        $ann = ClassAnnouncement::create(array_merge($validated, ['class_id' => $class->id, 'user_id' => auth()->id()]));
        $this->notifyClassStudents($class, 'Pengumuman Kelas Baru', $ann->title, 'pengumuman');
        return response()->json(['message' => 'Pengumuman berhasil dibuat.', 'data' => $ann], 201);
    }

    public function discussionList(ClassModel $class): JsonResponse
    {
        $this->authorizeClassView($class);

        return response()->json(
            Discussion::where('class_id', $class->id)
                ->with('user:id,name')
                ->withCount('replies')
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function discussionShow(ClassModel $class, Discussion $discussion): JsonResponse
    {
        $this->authorizeClassView($class);
        abort_unless((int) $discussion->class_id === (int) $class->id, 404);

        return response()->json(
            $discussion->load([
                'user:id,name',
                'replies' => fn ($query) => $query->with('user:id,name')->orderBy('created_at'),
            ])
        );
    }

    public function storeDiscussion(Request $request, ClassModel $class): JsonResponse
    {
        $this->authorizeDiscussionParticipation($class);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:10000',
        ]);

        $discussion = Discussion::create([
            ...$validated,
            'class_id' => $class->id,
            'user_id' => auth()->id(),
        ]);
        $authorName = auth()->user()->name;
        $this->notifyClassStudents(
            $class,
            'Topik Diskusi Baru',
            "{$authorName} membuka topik: {$discussion->title}",
            'diskusi',
            (int) auth()->id()
        );
        $this->notifyClassLecturers(
            $class,
            'Topik Diskusi Baru',
            "{$authorName} membuka topik: {$discussion->title}",
            'diskusi',
            (int) auth()->id()
        );

        return response()->json([
            'message' => 'Topik diskusi berhasil dibuat.',
            'data' => $discussion->load('user:id,name')->loadCount('replies'),
        ], 201);
    }

    public function storeDiscussionReply(Request $request, ClassModel $class, Discussion $discussion): JsonResponse
    {
        $this->authorizeDiscussionParticipation($class);
        abort_unless((int) $discussion->class_id === (int) $class->id, 404);
        abort_if($discussion->is_locked, 403, 'Diskusi ini telah dikunci.');

        $validated = $request->validate(['content' => 'required|string|max:5000']);
        $reply = $discussion->replies()->create([
            ...$validated,
            'user_id' => auth()->id(),
        ]);
        $discussion->loadMissing('user:id,name');
        if ($discussion->user?->id && (int) $discussion->user->id !== (int) auth()->id()) {
            AppNotification::send(
                (int) $discussion->user->id,
                'Balasan Diskusi Baru',
                auth()->user()->name." membalas topik \"{$discussion->title}\".",
                'info',
                '/perkuliahan/'.$class->id.'?tab=diskusi&discussion_id='.$discussion->id
            );
        }

        return response()->json([
            'message' => 'Balasan berhasil dikirim.',
            'data' => $reply->load('user:id,name'),
        ], 201);
    }

    public function destroyDiscussion(ClassModel $class, Discussion $discussion): JsonResponse
    {
        $this->authorizeClassView($class);
        abort_unless((int) $discussion->class_id === (int) $class->id, 404);
        abort_unless(
            (int) $discussion->user_id === (int) auth()->id() || $this->canModerateDiscussions($class),
            403,
            'Anda tidak dapat menghapus topik ini.'
        );

        $discussion->delete();

        return response()->json(['message' => 'Topik diskusi berhasil dihapus.']);
    }

    public function destroyDiscussionReply(ClassModel $class, Discussion $discussion, DiscussionReply $reply): JsonResponse
    {
        $this->authorizeClassView($class);
        abort_unless((int) $discussion->class_id === (int) $class->id, 404);
        abort_unless((int) $reply->discussion_id === (int) $discussion->id, 404);
        abort_unless(
            (int) $reply->user_id === (int) auth()->id() || $this->canModerateDiscussions($class),
            403,
            'Anda tidak dapat menghapus balasan ini.'
        );

        $reply->delete();

        return response()->json(['message' => 'Balasan berhasil dihapus.']);
    }

    public function moderateDiscussion(Request $request, ClassModel $class, Discussion $discussion): JsonResponse
    {
        $this->authorizeClassView($class);
        abort_unless((int) $discussion->class_id === (int) $class->id, 404);
        abort_unless($this->canModerateDiscussions($class), 403, 'Hanya dosen pengampu yang dapat memoderasi diskusi.');

        $discussion->update($request->validate([
            'is_pinned' => 'required|boolean',
            'is_locked' => 'required|boolean',
        ]));

        return response()->json(['message' => 'Pengaturan diskusi berhasil diperbarui.', 'data' => $discussion->fresh()]);
    }

    public function destroyJournal(LectureJournal $journal): JsonResponse
    {
        $this->authorizeClassManage($journal->class_);
        abort_if($journal->review_status === 'APPROVED', 409, 'Jurnal yang sudah disetujui tidak dapat dihapus.');
        $journal->attendances()->delete();
        $journal->delete();
        return response()->json(['message' => 'Jurnal pertemuan berhasil dihapus.']);
    }

    public function updateJournal(Request $request, LectureJournal $journal): JsonResponse
    {
        $this->authorizeClassManage($journal->class_);
        abort_if($journal->review_status === 'APPROVED', 409, 'Jurnal yang sudah disetujui tidak dapat diubah.');
        $validated = $request->validate([
            'meeting_number'    => 'sometimes|integer|min:1|max:16',
            'meeting_date'      => 'sometimes|date',
            'topic'             => 'sometimes|string|max:255',
            'description'       => 'nullable|string',
            'learning_activity' => 'nullable|string',
            'delivery_mode'     => 'sometimes|required|in:ONSITE,ONLINE',
            'status'            => 'nullable|in:PLANNED,COMPLETED,CANCELLED',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
        ]);
        $journal->update($validated);
        $journal->review_status = $journal->status === 'COMPLETED' ? 'PENDING' : 'NOT_SUBMITTED';
        $journal->reviewed_by = null;
        $journal->reviewed_at = null;
        $journal->review_note = null;
        $journal->save();
        return response()->json(['message' => 'Jurnal berhasil diupdate.', 'data' => $journal->fresh()]);
    }

    public function destroyAssignment(Assignment $assignment): JsonResponse
    {
        $assignment->submissions()->delete();
        if ($assignment->attachment_path) {
            Storage::disk('public')->delete($assignment->attachment_path);
        }
        $assignment->delete();
        return response()->json(['message' => 'Tugas berhasil dihapus.']);
    }

    public function updateAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $wasPublished = (bool) $assignment->is_published;
        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'description'  => 'nullable|string',
            'instructions' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip,jpg,jpeg,png,mp4,webm|max:20480',
            'type'         => 'sometimes|in:INDIVIDU,KELOMPOK',
            'due_date'     => 'nullable|date',
            'max_score'    => 'nullable|integer|min:1',
            'is_published' => 'boolean',
            'allow_late'   => 'boolean',
            'sub_cpmk_ids' => 'sometimes|array',
            'sub_cpmk_ids.*' => 'integer|distinct',
        ]);
        $subCpmkIds = $validated['sub_cpmk_ids'] ?? null;
        if ($subCpmkIds !== null) {
            app(ClassSubCpmkResolver::class)->assertBelongsToClass($assignment->class_, $subCpmkIds);
            unset($validated['sub_cpmk_ids']);
        }
        if ($request->hasFile('attachment')) {
            if ($assignment->attachment_path) {
                Storage::disk('public')->delete($assignment->attachment_path);
            }
            $validated['attachment_path'] = $request->file('attachment')->store('assignment-attachments', 'public');
            $validated['attachment_type'] = $request->file('attachment')->getClientMimeType();
            $validated['attachment_url'] = null;
            unset($validated['attachment']);
        }
        DB::transaction(function () use ($assignment, $validated, $subCpmkIds): void {
            $assignment->update($validated);
            if ($subCpmkIds !== null) {
                $assignment->subCpmks()->sync($subCpmkIds);
            }
        });
        if ($assignment->attachment_path) {
            $assignment->attachment_url = url(Storage::disk('public')->url($assignment->attachment_path));
        }
        if (!$wasPublished && !empty($validated['is_published'])) {
            $this->notifyClassStudents($assignment->class_, 'Tugas Baru',
                "Tugas \"{$assignment->title}\" telah dipublikasikan.", 'tugas');
        }
        return response()->json(['message' => 'Tugas berhasil diupdate.', 'data' => $assignment->fresh()]);
    }

    public function destroyAnnouncement(ClassAnnouncement $announcement): JsonResponse
    {
        $announcement->delete();
        return response()->json(['message' => 'Pengumuman berhasil dihapus.']);
    }

    public function updateAnnouncement(Request $request, ClassAnnouncement $announcement): JsonResponse
    {
        $validated = $request->validate([
            'title'   => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
        ]);
        $announcement->update($validated);
        $this->notifyClassStudents(
            $announcement->class_,
            'Pengumuman Kelas Diperbarui',
            "Pengumuman \"{$announcement->title}\" telah diperbarui.",
            'pengumuman'
        );
        return response()->json(['message' => 'Pengumuman berhasil diupdate.', 'data' => $announcement->fresh()]);
    }

    private function notifyClassStudents(
        ClassModel $class,
        string $title,
        string $message,
        string $tab = 'ringkasan',
        ?int $exceptUserId = null
    ): void
    {
        $participants = $this->participantStudents($class);
        foreach ($participants as $student) {
            if ($student->user_id && (int) $student->user_id !== $exceptUserId) {
                AppNotification::send(
                    (int) $student->user_id,
                    $title,
                    $message,
                    'info',
                    '/perkuliahan/'.$class->id.'?tab='.$tab
                );
            }
        }
    }

    private function notifyClassLecturers(
        ClassModel $class,
        string $title,
        string $message,
        string $tab,
        ?int $exceptUserId = null
    ): void {
        $class->loadMissing('lecturer');
        $userIds = collect([$class->lecturer?->user_id])
            ->merge(DB::table('lecturers')
                ->join('class_lecturers', 'class_lecturers.lecturer_id', '=', 'lecturers.id')
                ->where('class_lecturers.class_id', $class->id)
                ->pluck('lecturers.user_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === $exceptUserId);

        foreach ($userIds as $userId) {
            AppNotification::send(
                $userId,
                $title,
                $message,
                'info',
                '/perkuliahan/'.$class->id.'?tab='.$tab
            );
        }
    }

    private function authorizeClassView(ClassModel $class): void
    {
        $user = auth()->user();
        if ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')) {
            return;
        }

        $lecturer = $user->lecturer;
        if ($lecturer) {
            $isAssigned = (int) $class->lecturer_id === (int) $lecturer->id
                || $class->lecturers()->whereKey($lecturer->id)->exists();
            $hasProgramScope = $lecturer->positions()
                ->where('is_active', true)
                ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                ->where('scope_type', 'study_program')
                ->where('scope_id', $class->study_program_id)
                ->exists();
            if ($isAssigned || $hasProgramScope) {
                return;
            }
        }

        $studentId = $user->student?->id;
        if ($studentId && $this->classStudentIds($class)->contains((int) $studentId)) {
            return;
        }

        abort(403, 'Anda tidak memiliki akses ke kelas ini.');
    }

    private function authorizeClassManage(ClassModel $class): void
    {
        if (auth()->user()->student) {
            abort(403, 'Mahasiswa tidak diizinkan mengubah data kelas.');
        }

        $this->authorizeClassView($class);
    }

    private function authorizeDiscussionParticipation(ClassModel $class): void
    {
        $this->authorizeClassView($class);
        $user = auth()->user();

        if ($user->student) {
            abort_unless($this->classStudentIds($class)->contains((int) $user->student->id), 403, 'Anda bukan peserta kelas ini.');
            return;
        }

        abort_unless(
            $this->canModerateDiscussions($class),
            403,
            'Hanya peserta kelas dan dosen pengampu yang dapat berpartisipasi dalam diskusi.'
        );
    }

    private function canModerateDiscussions(ClassModel $class): bool
    {
        $user = auth()->user();
        if ($user->student) {
            return false;
        }

        if ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')) {
            return true;
        }

        $lecturerId = $user->lecturer?->id;
        if (! $lecturerId) {
            return false;
        }

        $isAssigned = (int) $class->lecturer_id === (int) $lecturerId
            || $class->lecturers()->whereKey($lecturerId)->exists();
        $hasProgramScope = $user->lecturer->positions()
            ->where('is_active', true)
            ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
            ->where('scope_type', 'study_program')
            ->where('scope_id', $class->study_program_id)
            ->exists();

        return $isAssigned || $hasProgramScope;
    }

    private function authorizeClassExport(ClassModel $class): void
    {
        if (auth()->user()->student) {
            abort(403, 'Mahasiswa tidak diizinkan mengunduh dokumen kelas.');
        }

        $this->authorizeClassView($class);
    }

    private function classStudentIds(ClassModel $class)
    {
        $memberIds = $class->members()->pluck('student_id');
        $krsStudentIds = \App\Models\KrsDetail::query()
            ->where('class_id', $class->id)
            ->where('krs_details.status', 'AKTIF')
            ->whereHas('krs', fn ($query) => $query
                ->where('student_id', auth()->user()->student?->id)
                ->where('status', 'APPROVED'))
            ->join('krs', 'krs.id', '=', 'krs_details.krs_id')
            ->pluck('krs.student_id');

        return $memberIds->merge($krsStudentIds)->map(fn ($id) => (int) $id)->unique()->values();
    }
}
