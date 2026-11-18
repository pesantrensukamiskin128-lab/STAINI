<?php

namespace App\Http\Controllers\Api;

use App\Helpers\QrCodeHelper;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Institution;
use App\Models\Lecturer;
use App\Models\LecturerPosition;
use App\Models\Rpkps;
use App\Models\RpkpsCpmk;
use App\Models\RpkpsWeeklyPlan;
use App\Support\RpkpsSubCpmkCode;
use App\Models\Semester;
use App\Exports\RpkpsWorkbookExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class RpkpsController extends Controller
{
    /** List RPKPS (dengan filter) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK');
        $isMahasiswa = $user->hasRole('MAHASISWA');

        $data = Rpkps::with(['course', 'lecturer', 'curriculum', 'academicYear'])
            ->when($request->search, fn ($q) => $q->whereHas('course', fn ($c) => $c->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%")))
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->lecturer_id, fn ($q) => $q->where('lecturer_id', $request->lecturer_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->academic_year_id, fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            // Mahasiswa: hanya lihat RPS yang sudah disetujui/dikunci
            ->when($isMahasiswa, fn ($q) => $q->whereIn('status', ['DISETUJUI', 'DIKUNCI']))
            // Dosen/admin: filter berdasarkan role
            ->when(! $isSuperAdmin && ! $isMahasiswa, function ($q) use ($user) {
                $lecturer = Lecturer::where('user_id', $user->id)->first();
                if (! $lecturer) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                // Cek apakah Kaprodi/Sekprodi → bisa lihat semua RPS di prodinya
                $isKaprodi = LecturerPosition::where('lecturer_id', $lecturer->id)
                    ->where('is_active', true)
                    ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                    ->exists();

                if ($isKaprodi) {
                    $prodiId = $lecturer->study_program_id;
                    $q->where(fn ($scope) => $scope
                        ->whereHas('course', fn ($cq) => $cq->where('study_program_id', $prodiId))
                        ->orWhere('lecturer_id', $lecturer->id));
                } else {
                    $q->where('lecturer_id', $lecturer->id);
                }
            })
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($data);
    }

    /** Mata kuliah yang bisa dibuat RPS oleh dosen (yang ditugaskan via kelas) */
    public function myAssignedCourses(Request $request): JsonResponse
    {
        $user = $request->user();
        $lecturer = Lecturer::where('user_id', $user->id)->first();

        if (! $lecturer) {
            return response()->json([]);
        }

        $classes = ClassModel::query()
            ->where('is_active', true)
            ->where(function ($query) use ($lecturer) {
                $query->where('lecturer_id', $lecturer->id)
                    ->orWhereHas('lecturers', fn ($lecturerQuery) => $lecturerQuery->where('lecturers.id', $lecturer->id))
                    ->orWhereHas('schedules', fn ($scheduleQuery) => $scheduleQuery->where('lecturer_id', $lecturer->id));
            })
            ->with(['course:id,study_program_id,code,name,credits', 'offeredCourses:id,study_program_id,code,name,credits'])
            ->get();

        $courses = $classes
            ->flatMap(fn ($class) => $class->offeredCourses->isNotEmpty() ? $class->offeredCourses : collect([$class->course]))
            ->filter()
            ->unique('id')
            ->values();

        return response()->json([
            'lecturer_id' => $lecturer->id,
            'courses' => $courses,
        ]);
    }

    /** Buat RPKPS baru */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'curriculum_id' => 'required|exists:curriculums,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'semester_id' => 'nullable|exists:semesters,id',
            'lecturer_id' => 'required|exists:lecturers,id',
            'coordinator_id' => 'nullable|exists:lecturers,id',
            'course_description' => 'nullable|string',
            'course_urgency' => 'nullable|string',
            'course_scope' => 'nullable|string',
            'course_position' => 'nullable|string',
            'prerequisites' => 'nullable|string',
        ]);

        if (! Curriculum::whereKey($validated['curriculum_id'])->where('status', 'Aktif')->exists()) {
            return response()->json(['message' => 'RPS hanya dapat dibuat menggunakan kurikulum yang aktif.'], 422);
        }

        // Validasi: Dosen hanya bisa buat RPS untuk MK yang diampu
        $user = $request->user();
        $isSuperAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK');

        if (! $isSuperAdmin) {
            $lecturer = Lecturer::where('user_id', $user->id)->first();
            if (! $lecturer || $lecturer->id != $validated['lecturer_id']) {
                return response()->json(['message' => 'Anda hanya bisa membuat RPKPS untuk diri sendiri.'], 403);
            }

            // Pengampu utama, pengampu tim, dan dosen pada jadwal kelas diakui.
            $isAssigned = ClassModel::query()
                ->where('is_active', true)
                ->whereHas('offeredCourses', fn ($courseQuery) => $courseQuery->where('courses.id', $validated['course_id']))
                ->where(function ($query) use ($lecturer) {
                    $query->where('lecturer_id', $lecturer->id)
                        ->orWhereHas('lecturers', fn ($lecturerQuery) => $lecturerQuery->where('lecturers.id', $lecturer->id))
                        ->orWhereHas('schedules', fn ($scheduleQuery) => $scheduleQuery->where('lecturer_id', $lecturer->id));
                })
                ->exists();

            if (! $isAssigned) {
                return response()->json([
                    'message' => 'Anda belum ditugaskan mengampu mata kuliah ini. Hubungi Kaprodi untuk penugasan.',
                ], 403);
            }
        }

        $curriculumContainsCourse = Curriculum::whereKey($validated['curriculum_id'])
            ->whereHas('courses', fn ($query) => $query->where('courses.id', $validated['course_id']))
            ->exists();
        if (! $curriculumContainsCourse) {
            return response()->json(['message' => 'Mata kuliah tidak tercantum dalam kurikulum yang dipilih.'], 422);
        }

        $validated['code'] = Rpkps::generateCode($validated['course_id']);

        // Auto-fill semester aktif jika tidak diisi
        if (empty($validated['semester_id'])) {
            $activeSemester = Semester::where('is_active', true)->first();
            if ($activeSemester) {
                $validated['semester_id'] = $activeSemester->id;
            }
        }

        $rpkps = Rpkps::create($validated);

        // Otomatis sync CPL dari pemetaan kurikulum (cpl_course_mappings)
        $cplIds = DB::table('cpl_course_mappings')
            ->where('course_id', $validated['course_id'])
            ->whereIn('learning_outcome_id', function ($q) use ($validated) {
                $q->select('id')->from('learning_outcomes')
                    ->where('curriculum_id', $validated['curriculum_id']);
            })
            ->pluck('learning_outcome_id')
            ->toArray();

        if ($cplIds) {
            $rpkps->cpls()->sync($cplIds);
        }

        return response()->json(['message' => 'RPKPS berhasil dibuat.', 'data' => $rpkps->load(['course', 'lecturer', 'cpls'])], 201);
    }

    /** Detail RPKPS lengkap */
    public function show(Rpkps $rpkp): JsonResponse
    {
        return response()->json($rpkp->load([
            'course.studyProgram.faculty',
            'curriculum', 'academicYear', 'semester',
            'lecturer', 'coordinator', 'approvedByUser',
            'cpls', 'cpmks.subCpmks', 'cpmks.cpls',
            'learningMaterials', 'weeklyPlans',
            'assessments.rubrics', 'gradeScales', 'assessmentInstruments', 'rubrics',
            'references', 'approvals.user',
        ]));
    }

    /** Update data deskripsi RPKPS */
    public function update(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        if (in_array($rpkp->status, ['DIKUNCI', 'DIARSIPKAN'])) {
            return response()->json(['message' => 'RPKPS sudah dikunci, tidak bisa diubah.'], 422);
        }

        $validated = $request->validate([
            'course_description' => 'nullable|string',
            'course_urgency' => 'nullable|string',
            'course_scope' => 'nullable|string',
            'course_position' => 'nullable|string',
            'prerequisites' => 'nullable|string',
            'coordinator_id' => 'nullable|exists:lecturers,id',
        ]);

        $rpkp->update($validated);

        return response()->json(['message' => 'RPKPS berhasil diupdate.', 'data' => $rpkp->fresh()]);
    }

    /** Sync CPL yang dipilih */
    public function syncCpls(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $request->validate(['cpl_ids' => 'required|array', 'cpl_ids.*' => 'exists:learning_outcomes,id']);
        $rpkp->cpls()->sync($request->cpl_ids);

        return response()->json(['message' => 'CPL berhasil disinkronkan.']);
    }

    /** CRUD CPMK */
    public function storeCpmk(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'description' => 'required|string',
            'cpl_ids' => 'nullable|array',
            'cpl_ids.*' => 'exists:learning_outcomes,id',
            'sub_cpmks' => 'nullable|array',
            'sub_cpmks.*.code' => 'required|string|max:20',
            'sub_cpmks.*.description' => 'required|string',
        ]);

        $cpmk = $rpkp->cpmks()->create([
            'code' => $validated['code'],
            'description' => $validated['description'],
            'order' => ((int) $rpkp->cpmks()->max('order')) + 1,
        ]);

        if (! empty($validated['cpl_ids'])) {
            $cpmk->cpls()->sync($validated['cpl_ids']);
        }

        if (! empty($validated['sub_cpmks'])) {
            foreach ($validated['sub_cpmks'] as $i => $sub) {
                $cpmk->subCpmks()->create([
                    'code' => RpkpsSubCpmkCode::make($cpmk->order, $i + 1),
                    'description' => $sub['description'],
                    'order' => $i + 1,
                ]);
            }
        }

        $rpkp->refreshSubCpmkCodes();

        return response()->json(['message' => 'CPMK berhasil ditambahkan.', 'data' => $cpmk->fresh(['subCpmks', 'cpls'])], 201);
    }

    public function updateCpmk(Request $request, Rpkps $rpkp, RpkpsCpmk $cpmk): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $validated = $request->validate([
            'code' => 'sometimes|string|max:20',
            'description' => 'sometimes|string',
            'cpl_ids' => 'nullable|array',
            'sub_cpmks' => 'sometimes|array',
            'sub_cpmks.*.id' => 'nullable|integer',
            'sub_cpmks.*.code' => 'nullable|string|max:20',
            'sub_cpmks.*.description' => 'required|string',
        ]);

        DB::transaction(function () use ($cpmk, $rpkp, $validated): void {
            $cpmk->update(collect($validated)->except(['cpl_ids', 'sub_cpmks'])->toArray());
            if (isset($validated['cpl_ids'])) {
                $cpmk->cpls()->sync($validated['cpl_ids']);
            }
            if (array_key_exists('sub_cpmks', $validated)) {
                $existing = $cpmk->subCpmks()->get()->keyBy('id');
                $keptIds = [];
                foreach ($validated['sub_cpmks'] as $i => $sub) {
                    $record = isset($sub['id']) ? $existing->get($sub['id']) : null;
                    if (isset($sub['id']) && !$record) {
                        throw ValidationException::withMessages([
                            'sub_cpmks' => ['Sub-CPMK yang dipilih bukan bagian dari CPMK ini.'],
                        ]);
                    }

                    if ($record) {
                        $record->update(['description' => $sub['description'], 'order' => $i + 1]);
                    } else {
                        $record = $cpmk->subCpmks()->create([
                            'code' => '',
                            'description' => $sub['description'],
                            'order' => $i + 1,
                        ]);
                    }
                    $keptIds[] = $record->id;
                }

                $removed = $existing->except($keptIds);
                foreach ($removed as $record) {
                    if ((\Illuminate\Support\Facades\Schema::hasTable('assignment_sub_cpmk')
                            && DB::table('assignment_sub_cpmk')->where('rpkps_sub_cpmk_id', $record->id)->exists())
                        || (\Illuminate\Support\Facades\Schema::hasTable('exam_sub_cpmk')
                            && DB::table('exam_sub_cpmk')->where('rpkps_sub_cpmk_id', $record->id)->exists())
                        || $this->hasSubCpmkTextReferences($rpkp, $record->code)) {
                        throw ValidationException::withMessages([
                            'sub_cpmks' => ["{$record->code} masih digunakan dalam RPS atau aktivitas kelas dan tidak dapat dihapus."],
                        ]);
                    }
                    $record->delete();
                }
            }

            $rpkp->refreshSubCpmkCodes();
        });

        return response()->json(['message' => 'CPMK berhasil diupdate.', 'data' => $cpmk->fresh(['subCpmks', 'cpls'])]);
    }

    public function destroyCpmk(Rpkps $rpkp, RpkpsCpmk $cpmk): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $subCpmkIds = $cpmk->subCpmks()->pluck('id');
        $subCpmkCodes = $cpmk->subCpmks()->pluck('code');
        foreach (['assignment_sub_cpmk', 'exam_sub_cpmk'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)
                && DB::table($table)->whereIn('rpkps_sub_cpmk_id', $subCpmkIds)->exists()) {
                throw ValidationException::withMessages([
                    'cpmk' => ['CPMK ini memiliki Sub-CPMK yang masih digunakan oleh tugas atau ujian.'],
                ]);
            }
        }
        foreach ($subCpmkCodes as $code) {
            if ($this->hasSubCpmkTextReferences($rpkp, $code)) {
                throw ValidationException::withMessages([
                    'cpmk' => ["{$code} masih digunakan dalam rencana atau instrumen RPS dan CPMK tidak dapat dihapus."],
                ]);
            }
        }
        DB::transaction(function () use ($cpmk, $rpkp): void {
            $cpmk->delete();
            $rpkp->refreshSubCpmkCodes();
        });

        return response()->json(['message' => 'CPMK berhasil dihapus.']);
    }

    private function hasSubCpmkTextReferences(Rpkps $rpkp, string $code): bool
    {
        $pattern = '/(?<![\pL\pN_.-])'.preg_quote($code, '/').'(?![\pL\pN_.-])/iu';
        foreach (['rpkps_weekly_plans', 'rpkps_assessment_instruments'] as $table) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->where('rpkps_id', $rpkp->id)->pluck('sub_cpmk')
                ->contains(fn ($value): bool => is_string($value) && preg_match($pattern, $value) === 1)) {
                return true;
            }
        }

        return false;
    }

    /** Batch save weekly plans (16 minggu sekaligus) */
    public function saveWeeklyPlans(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $validated = $request->validate([
            'plans' => 'required|array',
            'plans.*.week_number' => 'required|integer|min:1|max:16',
            'plans.*.sub_cpmk' => 'nullable|string',
            'plans.*.indicators' => 'nullable|string',
            'plans.*.learning_material' => 'nullable|string',
            'plans.*.methods' => 'nullable|array',
            'plans.*.offline_learning' => 'nullable|string',
            'plans.*.online_learning' => 'nullable|string',
            'plans.*.lecturer_activity' => 'nullable|string',
            'plans.*.student_activity' => 'nullable|string',
            'plans.*.assessment_form' => 'nullable|string',
            'plans.*.assessment_criteria' => 'nullable|string',
            'plans.*.media' => 'nullable|string',
            'plans.*.duration' => 'nullable|string',
            'plans.*.weight' => 'nullable|integer|min:0',
        ]);

        DB::transaction(function () use ($rpkp, $validated) {
            foreach ($validated['plans'] as $plan) {
                if (in_array((int) $plan['week_number'], [8, 16], true)) {
                    $plan['sub_cpmk'] = (int) $plan['week_number'] === 8
                        ? 'Evaluasi Tengah Semester'
                        : 'Evaluasi Akhir Semester';
                }
                RpkpsWeeklyPlan::updateOrCreate(
                    ['rpkps_id' => $rpkp->id, 'week_number' => $plan['week_number']],
                    $plan
                );
            }
        });

        return response()->json(['message' => 'Rencana mingguan berhasil disimpan.']);
    }

    /** Save assessments (komponen penilaian) */
    public function saveAssessments(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $validated = $request->validate([
            'assessments' => 'required|array',
            'assessments.*.id' => [
                'nullable',
                Rule::exists('rpkps_assessments', 'id')
                    ->where(fn ($query) => $query->where('rpkps_id', $rpkp->id)),
            ],
            'assessments.*.name' => 'required|string',
            'assessments.*.weight' => 'required|integer|min:0|max:100',
            'assessments.*.description' => 'nullable|string',
            'final_grade_formula' => 'nullable|string',
            'grade_scales' => 'nullable|array',
            'grade_scales.*.min_score' => 'required|numeric|min:0|max:100',
            'grade_scales.*.max_score' => 'required|numeric|min:0|max:100',
            'grade_scales.*.letter' => 'required|string|max:5',
            'grade_scales.*.grade_point' => 'required|numeric|min:0|max:4',
        ]);

        // Validasi total bobot = 100
        $total = collect($validated['assessments'])->sum('weight');
        if ($total !== 100) {
            return response()->json(['message' => "Total bobot harus 100%. Saat ini: {$total}%."], 422);
        }

        DB::transaction(function () use ($rpkp, $validated) {
            $retainedIds = [];
            foreach ($validated['assessments'] as $i => $a) {
                $assessmentId = $a['id'] ?? null;
                unset($a['id']);
                $assessment = $assessmentId
                    ? $rpkp->assessments()->findOrFail($assessmentId)
                    : $rpkp->assessments()->make();
                $assessment->fill(array_merge($a, ['order' => $i + 1]));
                $assessment->save();
                $retainedIds[] = $assessment->id;
            }
            $rpkp->assessments()->whereNotIn('id', $retainedIds)->delete();
            $rpkp->update(['final_grade_formula' => $validated['final_grade_formula'] ?? null]);
            $rpkp->gradeScales()->delete();
            foreach ($validated['grade_scales'] ?? [] as $i => $scale) {
                if ((float) $scale['max_score'] < (float) $scale['min_score']) {
                    throw ValidationException::withMessages([
                        "grade_scales.{$i}.max_score" => 'Nilai maksimum tidak boleh lebih kecil daripada nilai minimum.',
                    ]);
                }
                $rpkp->gradeScales()->create(array_merge($scale, ['order' => $i + 1]));
            }
        });

        return response()->json(['message' => 'Komponen asesmen berhasil disimpan.']);
    }

    /** Simpan kisi-kisi instrumen dan rubrik penilaian. */
    public function saveEvaluationTools(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $assessmentRule = Rule::exists('rpkps_assessments', 'id')
            ->where(fn ($query) => $query->where('rpkps_id', $rpkp->id));

        $validated = $request->validate([
            'instruments' => 'nullable|array',
            'instruments.*.rpkps_assessment_id' => ['nullable', $assessmentRule],
            'instruments.*.sub_cpmk' => 'nullable|string',
            'instruments.*.instrument_name' => 'required|string|max:255',
            'instruments.*.instrument_type' => 'nullable|string|max:50',
            'instruments.*.technique' => 'nullable|string|max:100',
            'instruments.*.cognitive_level' => 'nullable|string|max:30',
            'instruments.*.affective_level' => 'nullable|string|max:30',
            'instruments.*.psychomotor_level' => 'nullable|string|max:30',
            'instruments.*.item_numbers' => 'nullable|string|max:100',
            'instruments.*.weight' => 'nullable|integer|min:0|max:100',
            'instruments.*.timing' => 'nullable|string|max:100',
            'instruments.*.description' => 'nullable|string',
            'rubrics' => 'nullable|array',
            'rubrics.*.rpkps_assessment_id' => ['nullable', $assessmentRule],
            'rubrics.*.title' => 'nullable|string|max:255',
            'rubrics.*.criteria' => 'required|string|max:255',
            'rubrics.*.weight' => 'nullable|integer|min:0|max:100',
            'rubrics.*.excellent' => 'nullable|string',
            'rubrics.*.good' => 'nullable|string',
            'rubrics.*.fair' => 'nullable|string',
            'rubrics.*.poor' => 'nullable|string',
            'rubrics.*.very_poor' => 'nullable|string',
        ]);

        DB::transaction(function () use ($rpkp, $validated): void {
            $rpkp->assessmentInstruments()->delete();
            foreach ($validated['instruments'] ?? [] as $i => $instrument) {
                $rpkp->assessmentInstruments()->create(array_merge($instrument, ['order' => $i + 1]));
            }

            $rpkp->rubrics()->delete();
            foreach ($validated['rubrics'] ?? [] as $i => $rubric) {
                $rpkp->rubrics()->create(array_merge($rubric, ['order' => $i + 1]));
            }
        });

        return response()->json(['message' => 'Kisi-kisi instrumen dan rubrik berhasil disimpan.']);
    }

    /** Save references */
    public function saveReferences(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $validated = $request->validate([
            'references' => 'required|array',
            'references.*.type' => 'required|in:Utama,Pendukung',
            'references.*.category' => 'required|string|max:100',
            'references.*.title' => 'required|string',
            'references.*.author' => 'nullable|string',
            'references.*.year' => 'nullable|string',
            'references.*.publisher' => 'nullable|string',
            'references.*.isbn_doi' => 'nullable|string',
            'references.*.url' => 'nullable|string',
        ]);

        DB::transaction(function () use ($rpkp, $validated) {
            $rpkp->references()->delete();
            foreach ($validated['references'] as $i => $r) {
                $rpkp->references()->create(array_merge($r, ['order' => $i + 1]));
            }
        });

        return response()->json(['message' => 'Referensi berhasil disimpan.']);
    }

    public function exportExcel(Rpkps $rpkp)
    {
        $this->authorizeDocumentEdit($rpkp);
        $rpkp->load(['course','curriculum','academicYear','semester','lecturer','coordinator','cpmks.cpls','cpmks.subCpmks','learningMaterials','weeklyPlans','assessments','assessmentInstruments.assessment','rubrics.assessment','gradeScales','references']);
        return Excel::download(new RpkpsWorkbookExport($rpkp), 'RPKPS-'.$rpkp->code.'.xlsx');
    }

    public function templateExcel()
    {
        return Excel::download(new RpkpsWorkbookExport(null, true), 'template-import-RPKPS.xlsx');
    }

    public function importExcel(Request $request, Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);
        $sheets = Excel::toArray([], $request->file('file'));
        $byName = [];
        foreach ($sheets as $sheet) {
            if (!$sheet) continue;
            $header = array_map(fn ($v) => trim((string) $v), array_shift($sheet));
            $byName[$this->excelSheetName($header, $sheet)] = collect($sheet)
                ->filter(fn ($row) => collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())
                ->map(fn ($row) => collect($header)->combine(array_pad($row, count($header), null))->all())
                ->values();
        }

        $cpmkRows = $byName['CPMK'] ?? collect();
        $assessmentRows = $byName['ASESMEN'] ?? collect();
        $totalWeight = $assessmentRows->sum(fn ($r) => (int) ($r['weight'] ?? 0));
        if ($assessmentRows->isNotEmpty() && $totalWeight !== 100) {
            throw ValidationException::withMessages(['file' => "Total bobot ASESMEN harus 100%. Saat ini {$totalWeight}%."]);
        }

        $rpkpCpls = $rpkp->cpls()->get(['learning_outcomes.id', 'learning_outcomes.code']);
        $cplByCode = $rpkpCpls->keyBy(fn ($cpl) => strtoupper(trim($cpl->code)));
        $invalidCplCodes = collect();
        foreach ($cpmkRows as $row) {
            $codes = collect(explode('|', (string) ($row['cpl_codes'] ?? '')))->map(fn ($code) => strtoupper(trim($code)))->filter();
            if ($codes->isEmpty()) {
                throw ValidationException::withMessages(['file' => "CPMK {$row['code']} wajib memiliki minimal satu cpl_codes."]);
            }
            $invalidCplCodes = $invalidCplCodes->merge($codes->reject(fn ($code) => $cplByCode->has($code)));
        }
        if ($invalidCplCodes->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'cpl_codes tidak ditemukan pada CPL RPS: '.$invalidCplCodes->unique()->implode(', ').'.']);
        }

        $subRows = $byName['SUB_CPMK'] ?? collect();
        $cpmkOrderByCode = $cpmkRows
            ->sortBy(fn ($row, $index) => (int) ($row['order'] ?? $index + 1))
            ->values()
            ->mapWithKeys(fn ($row, $index) => [(string) $row['code'] => $index + 1]);
        $legacySubCodeTargets = [];
        $subRows = $subRows->groupBy(fn ($row) => (string) ($row['cpmk_code'] ?? ''))
            ->flatMap(function ($rows, $cpmkCode) use ($cpmkOrderByCode, &$legacySubCodeTargets) {
                if (!$cpmkOrderByCode->has((string) $cpmkCode)) {
                    throw ValidationException::withMessages([
                        'file' => ["CPMK {$cpmkCode} pada sheet SUB_CPMK tidak ditemukan di sheet CPMK."],
                    ]);
                }
                $cpmkOrder = $cpmkOrderByCode->get((string) $cpmkCode);
                return $rows->sortBy(fn ($row, $index) => (int) ($row['order'] ?? $index + 1))
                    ->values()
                    ->map(function ($row, $index) use ($cpmkOrder, &$legacySubCodeTargets) {
                        $legacyCode = trim((string) ($row['code'] ?? ''));
                        $code = RpkpsSubCpmkCode::make($cpmkOrder, $index + 1);
                        if ($legacyCode !== '') {
                            $legacySubCodeTargets[strtoupper($legacyCode)][] = $code;
                        }
                        $row['code'] = $code;
                        $row['order'] = $index + 1;
                        return $row;
                    });
            })->values();

        $subCodesByCpmk = $subRows->groupBy(fn ($row) => (string) ($row['cpmk_code'] ?? ''));
        foreach ($cpmkRows as $row) {
            if ($subCodesByCpmk->get((string) $row['code'], collect())->isEmpty()) {
                throw ValidationException::withMessages(['file' => "CPMK {$row['code']} wajib memiliki minimal satu Sub-CPMK."]);
            }
        }
        $allowedSubCpmkCodes = $subRows->pluck('code')->map(fn ($code) => trim((string) $code))->filter()->unique();
        $instrumentRows = ($byName['INSTRUMEN'] ?? collect())->map(function ($row) use ($legacySubCodeTargets, $allowedSubCpmkCodes) {
            $value = trim((string) ($row['sub_cpmk'] ?? ''));
            $codes = collect(preg_split('/[|,;\r\n]+/', $value))->map(fn ($code) => trim((string) $code))->filter();
            $row['sub_cpmk'] = $codes->map(function ($code) use ($legacySubCodeTargets, $allowedSubCpmkCodes) {
                if ($allowedSubCpmkCodes->contains($code)) {
                    return $code;
                }
                $targets = collect($legacySubCodeTargets[strtoupper($code)] ?? [])->unique()->values();
                if ($targets->count() === 1) {
                    return $targets->first();
                }
                if ($targets->isNotEmpty() || preg_match('/^(SUB|Sub)-/i', $code)) {
                    $message = $targets->isNotEmpty()
                        ? "Kode lama {$code} pada sheet INSTRUMEN ambigu. Ganti dengan kode Sub-CPMK baru."
                        : "Sub-CPMK {$code} pada sheet INSTRUMEN tidak ditemukan di sheet SUB_CPMK.";
                    throw ValidationException::withMessages(['file' => $message]);
                }
                return $code;
            })->implode('|');
            return $row;
        });
        $byName['INSTRUMEN'] = $instrumentRows;
        $weeklyRows = ($byName['RENCANA_MINGGUAN'] ?? collect())->map(function ($row) use ($legacySubCodeTargets, $allowedSubCpmkCodes) {
            $week = (int) ($row['week_number'] ?? 0);
            $value = trim((string) ($row['sub_cpmk'] ?? ''));
            if (in_array($week, [8, 16], true)) {
                $row['sub_cpmk'] = $week === 8 ? 'Evaluasi Tengah Semester' : 'Evaluasi Akhir Semester';
                return $row;
            }
            $codes = collect(preg_split('/[|,;\r\n]+/', $value))->map(fn ($code) => trim((string) $code))->filter();
            $normalizedCodes = $codes->map(function ($code) use ($legacySubCodeTargets, $allowedSubCpmkCodes, $week) {
                if ($allowedSubCpmkCodes->contains($code)) {
                    return $code;
                }
                $targets = collect($legacySubCodeTargets[strtoupper($code)] ?? [])->unique()->values();
                if ($targets->count() === 1) {
                    return $targets->first();
                }
                $message = $targets->isEmpty()
                    ? "Sub-CPMK {$code} pada minggu {$week} tidak ditemukan di sheet SUB_CPMK."
                    : "Kode lama {$code} pada minggu {$week} ambigu. Ganti dengan kode Sub-CPMK baru dari sheet SUB_CPMK.";
                throw ValidationException::withMessages(['file' => $message]);
            });
            $row['sub_cpmk'] = $normalizedCodes->implode('|');
            return $row;
        });
        $byName['RENCANA_MINGGUAN'] = $weeklyRows;

        foreach ($weeklyRows as $row) {
            $week = (int) ($row['week_number'] ?? 0);
            if ($week < 1 || $week > 16) {
                throw ValidationException::withMessages(['file' => "Nomor minggu {$week} harus berada pada rentang 1 sampai 16."]);
            }
        }

        DB::transaction(function () use ($rpkp, $byName, $cpmkRows, $assessmentRows, $cplByCode): void {
            $identity = ($byName['IDENTITAS'] ?? collect())->first();
            if ($identity) {
                $resolve = function (string $label, $value, string $model, string $column = 'id') {
                    if ($value === null || trim((string) $value) === '') {
                        return null;
                    }
                    $record = $model::where($column, trim((string) $value))->first();
                    if (!$record) {
                        throw ValidationException::withMessages(['file' => "{$label} '{$value}' tidak ditemukan pada data master."]);
                    }
                    return $record->id;
                };
                $courseId = $identity['course_code'] ?? null
                    ? $resolve('Kode mata kuliah', $identity['course_code'], Course::class, 'code')
                    : $resolve('course_id', $identity['course_id'] ?? null, Course::class);
                $curriculumId = $identity['curriculum_code'] ?? null
                    ? $resolve('Kode kurikulum', $identity['curriculum_code'], Curriculum::class, 'code')
                    : $resolve('curriculum_id', $identity['curriculum_id'] ?? null, Curriculum::class);
                $academicYearId = $identity['academic_year'] ?? null
                    ? $resolve('Tahun akademik', $identity['academic_year'], AcademicYear::class, 'name')
                    : $resolve('academic_year_id', $identity['academic_year_id'] ?? null, AcademicYear::class);
                $lecturerId = $identity['lecturer_nidn'] ?? null
                    ? $resolve('NIDN dosen', $identity['lecturer_nidn'], Lecturer::class, 'nidn')
                    : $resolve('lecturer_id', $identity['lecturer_id'] ?? null, Lecturer::class);
                $coordinatorId = $identity['coordinator_nidn'] ?? null
                    ? $resolve('NIDN koordinator', $identity['coordinator_nidn'], Lecturer::class, 'nidn')
                    : $resolve('coordinator_id', $identity['coordinator_id'] ?? null, Lecturer::class);
                $semesterId = $identity['semester_name'] ?? null
                    ? Semester::where('academic_year_id', $academicYearId)->where('name', trim((string) $identity['semester_name']))->value('id')
                    : ($identity['semester_id'] ?? null);
                if (($identity['semester_name'] ?? null) && !$semesterId) {
                    throw ValidationException::withMessages(['file' => "Semester '{$identity['semester_name']}' tidak ditemukan pada tahun akademik yang dipilih."]);
                }
                $rpkp->update(collect([
                    'course_id' => $courseId, 'curriculum_id' => $curriculumId, 'academic_year_id' => $academicYearId,
                    'semester_id' => $semesterId, 'lecturer_id' => $lecturerId, 'coordinator_id' => $coordinatorId,
                    'course_description' => $identity['course_description'] ?? null, 'course_urgency' => $identity['course_urgency'] ?? null,
                    'course_scope' => $identity['course_scope'] ?? null, 'course_position' => $identity['course_position'] ?? null,
                    'prerequisites' => $identity['prerequisites'] ?? null,
                ])->filter(fn ($value) => $value !== null && $value !== '')->all());
            }
            $cpmks = $rpkp->cpmks()->get()->keyBy('code')->all();
            foreach ($cpmkRows as $i => $row) {
                $cpmk = $rpkp->cpmks()->updateOrCreate(['code' => $row['code']], [
                    'code' => $row['code'], 'description' => $row['description'], 'order' => $row['order'] ?? $i + 1,
                ]);
                $cpmks[(string) $row['code']] = $cpmk;
                $cplCodes = collect(explode('|', (string) ($row['cpl_codes'] ?? '')))->map(fn ($code) => strtoupper(trim($code)))->filter();
                $cpmk->cpls()->sync($cplCodes->map(fn ($code) => $cplByCode[$code]->id)->all());
            }
            foreach (($byName['SUB_CPMK'] ?? collect()) as $row) {
                $cpmk = $cpmks[(string) $row['cpmk_code']] ?? null;
                if ($cpmk) {
                    $subCpmk = $cpmk->subCpmks()->where('code', $row['code'])->first()
                        ?? $cpmk->subCpmks()->where('order', $row['order'])->first();
                    if ($subCpmk) {
                        $subCpmk->update([
                            'code' => $row['code'],
                            'description' => $row['description'],
                            'order' => $row['order'],
                        ]);
                    } else {
                        $cpmk->subCpmks()->create([
                            'code' => $row['code'], 'description' => $row['description'], 'order' => $row['order'],
                        ]);
                    }
                }
            }
            foreach (($byName['BAHAN_KAJIAN'] ?? collect()) as $i => $row) {
                $rpkp->learningMaterials()->updateOrCreate(
                    ['order' => $row['order'] ?? $i + 1],
                    ['topic' => $row['topic'], 'subtopics' => $row['subtopics'] ?? null]
                );
            }
            foreach (($byName['RENCANA_MINGGUAN'] ?? collect()) as $row) {
                $data = collect($row)->only([
                    'sub_cpmk', 'indicators', 'learning_material', 'offline_learning', 'online_learning',
                    'lecturer_activity', 'student_activity', 'assessment_form', 'assessment_criteria',
                    'media', 'duration', 'weight',
                ])->all();
                $methods = trim((string) ($row['methods'] ?? ''));
                $methodSeparator = str_contains($methods, '|') ? '/\s*\|\s*/u' : '/\s*,\s*/u';
                $data['methods'] = $methods === ''
                    ? []
                    : array_values(array_filter(array_map('trim', preg_split($methodSeparator, $methods) ?: []), fn ($method) => $method !== ''));
                $data['weight'] = (int) ($row['weight'] ?? 0);
                $rpkp->weeklyPlans()->updateOrCreate(['week_number' => (int) $row['week_number']], $data);
            }
            $assessments = $rpkp->assessments()->get()->keyBy('name')->all();
            foreach ($assessmentRows as $i => $row) {
                $assessment = $rpkp->assessments()->updateOrCreate(['name' => $row['name']], [
                    'name'=>$row['name'],'weight'=>(int)$row['weight'],'description'=>$row['description'] ?? null,'order'=>$row['order'] ?? $i + 1,
                ]);
                $assessments[(string) $row['name']] = $assessment;
                if (!empty($row['final_grade_formula'])) {
                    $rpkp->final_grade_formula = $row['final_grade_formula'];
                }
            }
            $rpkp->save();
            foreach (($byName['SKALA_NILAI'] ?? collect()) as $i => $row) {
                $order = $row['order'] ?? $i + 1;
                $rpkp->gradeScales()->updateOrCreate(['order' => $order], collect($row)->except('order')->all());
            }
            foreach (($byName['INSTRUMEN'] ?? collect()) as $i => $row) {
                $data = collect($row)->except('assessment_name')->all();
                $assessment = $assessments[(string) ($row['assessment_name'] ?? '')] ?? null;
                $data['rpkps_assessment_id'] = $assessment?->id;
                if ($data['weight'] === null || $data['weight'] === '') {
                    $data['weight'] = $assessment?->weight ?? 0;
                }
                $data['order'] = $row['order'] ?? $i + 1;
                $rpkp->assessmentInstruments()->updateOrCreate(['order' => $data['order']], $data);
            }
            foreach (($byName['RUBRIK'] ?? collect()) as $i => $row) {
                $data = collect($row)->except('assessment_name')->all();
                $data['rpkps_assessment_id'] = $assessments[(string) ($row['assessment_name'] ?? '')]->id ?? null;
                $data['order'] = $row['order'] ?? $i + 1;
                $rpkp->rubrics()->updateOrCreate(['order' => $data['order']], $data);
            }
            foreach (($byName['REFERENSI'] ?? collect()) as $i => $row) {
                $data = array_merge($row, ['order' => $row['order'] ?? $i + 1]);
                $referenceTypes = ['Utama', 'Pendukung'];
                if (! in_array($data['type'] ?? null, $referenceTypes, true)
                    && in_array($data['category'] ?? null, $referenceTypes, true)) {
                    [$data['type'], $data['category']] = [$data['category'], $data['type']];
                }
                if (! in_array($data['type'] ?? null, $referenceTypes, true)) {
                    throw ValidationException::withMessages([
                        'file' => "Jenis referensi '{$data['type']}' harus berupa Utama atau Pendukung.",
                    ]);
                }
                $category = trim((string) ($data['category'] ?? ''));
                if ($category === '' || mb_strlen($category) > 100) {
                    throw ValidationException::withMessages([
                        'file' => 'Kategori referensi wajib diisi dan maksimal 100 karakter.',
                    ]);
                }
                $data['category'] = $category;
                $rpkp->references()->updateOrCreate(
                    ['type' => $data['type'], 'order' => $data['order']],
                    collect($data)->except(['type', 'order'])->all()
                );
            }
            $rpkp->refreshSubCpmkCodes();
        });
        return response()->json(['message' => 'RPKPS berhasil diimport tanpa menghapus baris yang tidak disertakan. CPL tetap mengikuti kurikulum.']);
    }

    private function excelSheetName(array $header, $rows): string
    {
        return match (true) {
            in_array('cpmk_code', $header, true) => 'SUB_CPMK',
            in_array('week_number', $header, true) => 'RENCANA_MINGGUAN',
            in_array('assessment_name', $header, true) && in_array('instrument_name', $header, true) => 'INSTRUMEN',
            in_array('assessment_name', $header, true) => 'RUBRIK',
            in_array('min_score', $header, true) => 'SKALA_NILAI',
            in_array('isbn_doi', $header, true) => 'REFERENSI',
            in_array('instrument_name', $header, true) => 'INSTRUMEN',
            in_array('criteria', $header, true) => 'RUBRIK',
            in_array('topic', $header, true) => 'BAHAN_KAJIAN',
            in_array('code', $header, true) && in_array('description', $header, true) => 'CPMK',
            in_array('name', $header, true) && in_array('weight', $header, true) => 'ASESMEN',
            default => 'IDENTITAS',
        };
    }

    // === WORKFLOW ===

    /** Ajukan RPKPS (DRAFT → DIAJUKAN) */
    public function submit(Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        if ($rpkp->status !== 'DRAFT' && $rpkp->status !== 'REVISI') {
            return response()->json(['message' => 'RPKPS tidak dalam status yang bisa diajukan.'], 422);
        }
        $rpkp->update(['status' => 'DIAJUKAN']);
        $rpkp->approvals()->create(['user_id' => auth()->id(), 'action' => 'DIAJUKAN']);

        // Kirim notifikasi ke Kaprodi
        $rpkp->loadMissing(['course', 'lecturer']);
        $prodiId = $rpkp->course?->study_program_id;
        if ($prodiId) {
            // Cari Kaprodi prodi ini
            $kaprodiPosition = LecturerPosition::where('position_code', 'KAPRODI')
                ->where('scope_type', 'study_program')
                ->where('scope_id', $prodiId)
                ->where('is_active', true)
                ->first();

            if ($kaprodiPosition) {
                $kaprodiLecturer = Lecturer::find($kaprodiPosition->lecturer_id);
                if ($kaprodiLecturer?->user_id) {
                    AppNotification::send(
                        $kaprodiLecturer->user_id,
                        'Pengajuan RPKPS Baru',
                        "Dosen {$rpkp->lecturer?->display_name} mengajukan RPKPS untuk MK {$rpkp->course?->name}. Mohon direview.",
                        'warning',
                        '/rps/'.$rpkp->id
                    );
                }
            }
        }

        return response()->json(['message' => 'RPKPS berhasil diajukan untuk validasi.']);
    }

    /** Kaprodi: Setujui */
    public function approve(Request $request, Rpkps $rpkp): JsonResponse
    {
        $rpkp->update([
            'status' => 'DISETUJUI',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        $rpkp->approvals()->create(['user_id' => auth()->id(), 'action' => 'DISETUJUI', 'note' => $request->note]);

        // Notifikasi ke dosen pembuat RPS
        $rpkp->loadMissing(['course', 'lecturer']);
        if ($rpkp->lecturer?->user_id) {
            AppNotification::send(
                $rpkp->lecturer->user_id,
                'RPKPS Disetujui ✓',
                "RPKPS untuk MK {$rpkp->course?->name} telah disetujui oleh Kaprodi.",
                'success',
                '/rps/'.$rpkp->id
            );
        }

        return response()->json(['message' => 'RPKPS berhasil disetujui.']);
    }

    /** Kaprodi: Minta revisi */
    public function revise(Request $request, Rpkps $rpkp): JsonResponse
    {
        $request->validate(['note' => 'required|string']);
        $rpkp->update(['status' => 'REVISI', 'revision_note' => $request->note]);
        $rpkp->approvals()->create(['user_id' => auth()->id(), 'action' => 'REVISI', 'note' => $request->note]);

        // Notifikasi ke dosen pembuat RPS
        $rpkp->loadMissing(['course', 'lecturer']);
        if ($rpkp->lecturer?->user_id) {
            AppNotification::send(
                $rpkp->lecturer->user_id,
                'RPKPS Perlu Revisi',
                "RPKPS untuk MK {$rpkp->course?->name} dikembalikan untuk revisi. Catatan: \"{$request->note}\"",
                'warning',
                '/rps/'.$rpkp->id
            );
        }

        return response()->json(['message' => 'RPKPS dikembalikan untuk revisi.']);
    }

    /** Kunci RPKPS (setelah disetujui) */
    public function lock(Rpkps $rpkp): JsonResponse
    {
        if ($rpkp->status !== 'DISETUJUI') {
            return response()->json(['message' => 'Hanya RPKPS yang disetujui yang bisa dikunci.'], 422);
        }
        $rpkp->update(['status' => 'DIKUNCI']);
        $rpkp->approvals()->create(['user_id' => auth()->id(), 'action' => 'DIKUNCI']);

        $rpkp->loadMissing(['course', 'lecturer']);
        if ($rpkp->lecturer?->user_id && (int) $rpkp->lecturer->user_id !== (int) auth()->id()) {
            AppNotification::send(
                $rpkp->lecturer->user_id,
                'RPKPS Dikunci',
                "RPKPS untuk MK {$rpkp->course?->name} telah dikunci dan tidak dapat diedit.",
                'info',
                '/rps/'.$rpkp->id
            );
        }

        return response()->json(['message' => 'RPKPS berhasil dikunci.']);
    }

    /** Duplikasi ke semester baru */
    public function duplicate(Request $request, Rpkps $rpkp): JsonResponse
    {
        if (! in_array($rpkp->status, ['DISETUJUI', 'DIKUNCI', 'DIARSIPKAN'], true)) {
            return response()->json([
                'message' => 'Hanya RPKPS lama yang sudah disetujui, dikunci, atau diarsipkan yang dapat digunakan kembali.',
            ], 422);
        }

        $validated = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'semester_id' => 'nullable|exists:semesters,id',
            'curriculum_id' => 'nullable|exists:curriculums,id',
            'lecturer_id' => 'nullable|exists:lecturers,id',
        ]);

        if (! empty($validated['curriculum_id'])) {
            $validCurriculum = Curriculum::whereKey($validated['curriculum_id'])
                ->where('status', 'Aktif')
                ->whereHas('courses', fn ($query) => $query->where('courses.id', $rpkp->course_id))
                ->exists();
            if (! $validCurriculum) {
                return response()->json(['message' => 'Mata kuliah tidak tercantum dalam kurikulum tujuan.'], 422);
            }
        }

        $user = $request->user();
        $isAdmin = $user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']);
        $lecturer = $user->lecturer;
        if (! $isAdmin) {
            abort_unless($lecturer && $this->lecturerIsAssignedToCourse($lecturer->id, $rpkp->course_id), 403,
                'Anda belum ditugaskan mengampu mata kuliah ini.');
        }

        $new = DB::transaction(function () use ($rpkp, $validated, $isAdmin, $lecturer) {
            $copy = $rpkp->duplicate($validated['academic_year_id'], $validated['semester_id'] ?? null);
            $copy->update([
                'curriculum_id' => $validated['curriculum_id'] ?? $rpkp->curriculum_id,
                'lecturer_id' => $isAdmin
                    ? ($validated['lecturer_id'] ?? $rpkp->lecturer_id)
                    : $lecturer->id,
                'status' => 'DRAFT',
                'revision_note' => null,
            ]);

            return $copy;
        });

        return response()->json(['message' => 'RPKPS lama berhasil digunakan sebagai draf baru dan siap direvisi.', 'data' => $new->load(['course', 'lecturer'])], 201);
    }

    public function reusable(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']);
        $courseIds = null;
        if (! $isAdmin) {
            $assigned = $this->myAssignedCourses($request)->getData(true);
            $courseIds = collect($assigned['courses'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return response()->json(Rpkps::with(['course:id,code,name,credits', 'lecturer:id,full_name,degree_front,degree_back', 'academicYear:id,name'])
            ->whereIn('status', ['DISETUJUI', 'DIKUNCI', 'DIARSIPKAN'])
            ->when($courseIds !== null, fn ($query) => $query->whereIn('course_id', $courseIds))
            ->when($request->course_id, fn ($query) => $query->where('course_id', $request->course_id))
            ->orderByDesc('academic_year_id')
            ->limit(100)
            ->get());
    }

    /** Statistik RPKPS (untuk dashboard) */
    public function statistics(Request $request): JsonResponse
    {
        $query = Rpkps::query()
            ->when($request->academic_year_id, fn ($q) => $q->where('academic_year_id', $request->academic_year_id));

        $stats = [
            'total' => (clone $query)->count(),
            'draft' => (clone $query)->where('status', 'DRAFT')->count(),
            'diajukan' => (clone $query)->where('status', 'DIAJUKAN')->count(),
            'dalam_pemeriksaan' => (clone $query)->where('status', 'DALAM_PEMERIKSAAN')->count(),
            'revisi' => (clone $query)->where('status', 'REVISI')->count(),
            'disetujui' => (clone $query)->where('status', 'DISETUJUI')->count(),
            'dikunci' => (clone $query)->where('status', 'DIKUNCI')->count(),
        ];

        return response()->json($stats);
    }

    /** Hapus RPKPS yang belum dikunci */
    public function destroy(Rpkps $rpkp): JsonResponse
    {
        $this->authorizeDocumentEdit($rpkp);
        if (! in_array($rpkp->status, ['DRAFT', 'REVISI', 'DISETUJUI'], true)) {
            return response()->json(['message' => 'Hanya RPKPS berstatus DRAFT, REVISI, atau DISETUJUI yang belum dikunci yang bisa dihapus.'], 422);
        }
        $rpkp->delete();

        return response()->json(['message' => 'RPKPS berhasil dihapus.']);
    }

    /** Download PDF RPKPS dengan format standar dan QR code */
    public function downloadPdf(Rpkps $rpkp)
    {
        $rpkp->load([
            'course.studyProgram.faculty',
            'curriculum', 'academicYear', 'semester',
            'lecturer', 'coordinator',
            'cpls', 'cpmks.subCpmks', 'cpmks.cpls',
            'learningMaterials', 'weeklyPlans',
            'assessments', 'gradeScales', 'assessmentInstruments', 'rubrics', 'references',
        ]);

        $institution = Institution::first();
        $logoPath = $institution?->logo_path
            ? storage_path('app/public/'.$institution->logo_path)
            : null;
        $logoDataUri = null;
        if ($institution?->logo_path && Storage::disk('public')->exists($institution->logo_path)) {
            $logoContents = Storage::disk('public')->get($institution->logo_path);
            $logoMime = Storage::disk('public')->mimeType($institution->logo_path) ?: 'image/png';
            $logoDataUri = 'data:'.$logoMime.';base64,'.base64_encode($logoContents);
        } elseif ($logoPath && is_file($logoPath) && is_readable($logoPath)) {
            $logoContents = file_get_contents($logoPath);
            if ($logoContents !== false) {
                $logoDataUri = 'data:image/png;base64,'.base64_encode($logoContents);
            }
        }

        // Cari Kaprodi
        $prodiId = $rpkp->course?->study_program_id;
        $kaprodi = null;
        if ($prodiId) {
            $kaprodiPosition = LecturerPosition::where('position_code', 'KAPRODI')
                ->where('scope_type', 'study_program')
                ->where('scope_id', $prodiId)
                ->where('is_active', true)
                ->first();
            if ($kaprodiPosition) {
                $kaprodi = Lecturer::find($kaprodiPosition->lecturer_id);
            }
        }

        // Identitas penjaminan mutu ditampilkan pada lembar otorisasi.
        $lpmPosition = LecturerPosition::where('position_code', 'KETUA_LPM')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('scope_type', 'institution')
                    ->orWhereNull('scope_type');
            })
            ->latest('id')
            ->first()
            ?? LecturerPosition::where('position_code', 'KETUA_LPM')
                ->where('is_active', true)
                ->latest('id')
                ->first();
        $lpm = $lpmPosition ? Lecturer::find($lpmPosition->lecturer_id) : null;

        // QR Code via helper (biru + logo untuk footer)
        $verifyUrl = rtrim(config('app.frontend_url'), '/')."/verify/rpkps/{$rpkp->verification_code}";
        $qrDosenUrl = QrCodeHelper::generate($verifyUrl.'?signer=dosen', 120);
        $qrLpmUrl = QrCodeHelper::generate($verifyUrl.'?signer=lpm', 120);
        $qrKaprodiUrl = QrCodeHelper::generate($verifyUrl.'?signer=kaprodi', 120);
        $qrFooter = QrCodeHelper::generateWithLogo($verifyUrl, 160);

        $pdf = Pdf::loadView('pdf.rpkps', [
            'rpkps' => $rpkp,
            'institution' => $institution,
            'logoPath' => $logoPath,
            'logoDataUri' => $logoDataUri,
            'kaprodi' => $kaprodi,
            'lpm' => $lpm,
            'qrDosenUrl' => $qrDosenUrl,
            'qrLpmUrl' => $qrLpmUrl,
            'qrKaprodiUrl' => $qrKaprodiUrl,
            'qrFooter' => $qrFooter,
            'verifyUrl' => $verifyUrl,
        ])->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', true);

        $filename = "RPKPS-{$rpkp->course?->code}-{$rpkp->code}.pdf";

        return $pdf->download($filename);
    }

    private function lecturerIsAssignedToCourse(int $lecturerId, int $courseId): bool
    {
        return ClassModel::query()
            ->where('is_active', true)
            ->whereHas('offeredCourses', fn ($courseQuery) => $courseQuery->where('courses.id', $courseId))
            ->where(function ($query) use ($lecturerId) {
                $query->where('lecturer_id', $lecturerId)
                    ->orWhereHas('lecturers', fn ($lecturerQuery) => $lecturerQuery->where('lecturers.id', $lecturerId))
                    ->orWhereHas('schedules', fn ($scheduleQuery) => $scheduleQuery->where('lecturer_id', $lecturerId));
            })
            ->exists();
    }

    private function authorizeDocumentEdit(Rpkps $rpkps): void
    {
        $user = request()->user();
        if ($user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            return;
        }

        $lecturerId = $user?->lecturer?->id;
        abort_unless(
            $lecturerId
            && (int) $rpkps->lecturer_id === (int) $lecturerId
            && $this->lecturerIsAssignedToCourse($lecturerId, $rpkps->course_id),
            403,
            'RPKPS hanya dapat direvisi oleh dosen penyusun yang masih ditugaskan mengampu mata kuliah tersebut.'
        );
    }
}
