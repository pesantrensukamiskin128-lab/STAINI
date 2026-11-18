<?php

namespace App\Http\Controllers\Api;

use App\Exports\KrsRecapExport;
use App\Http\Controllers\Controller;
use App\Models\Krs;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class KrsRecapController extends Controller
{
    private const UNIVERSITY_WIDE = ['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'WK1', 'WR1', 'WK2', 'WR2', 'KETUA', 'REKTOR', 'DEKAN', 'WADEK1', 'KETUA_LPM', 'SEKRETARIS_LPM'];

    public function index(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $data = (clone $query)->paginate($perPage)->through(fn (Krs $krs) => $this->row($krs));

        return response()->json([
            'data' => $data->items(),
            'meta' => ['total' => $data->total(), 'current_page' => $data->currentPage(), 'last_page' => $data->lastPage()],
            'summary' => $this->summary($query),
        ]);
    }

    public function excel(Request $request)
    {
        $krsList = $this->query($request)->with(['details' => fn ($d) => $d->where('status', 'AKTIF')->with(['course', 'class_'])])->get();

        $summary = $krsList->map(fn (Krs $krs) => $this->row($krs))->all();
        $detail = [];
        foreach ($krsList as $krs) {
            foreach ($krs->details as $d) {
                $detail[] = [
                    'semester' => $krs->semester?->name,
                    'nim' => $krs->student?->nim,
                    'name' => $krs->student?->name,
                    'prodi' => $krs->student?->studyProgram?->name,
                    'class_type' => $krs->student?->class_type,
                    'course_code' => $d->course?->code,
                    'course_name' => $d->course?->name,
                    'credits' => $d->course?->credits,
                    'class_name' => $d->class_?->name,
                ];
            }
        }

        return Excel::download(new KrsRecapExport($summary, $detail, $this->filterLabels($request)), 'rekap-krs-mahasiswa-'.now()->format('Ymd').'.xlsx');
    }

    private function query(Request $request)
    {
        $filters = $request->validate([
            'semester_id' => 'nullable|integer',
            'academic_year_id' => 'nullable|integer',
            'study_program_id' => 'nullable|integer',
            'class_type' => ['nullable', Rule::in(Student::CLASS_TYPES)],
            'status' => 'nullable|string|max:30',
            'search' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $scope = null;
        if (! $user->hasAnyRole(self::UNIVERSITY_WIDE)
            && ! array_intersect($user->getActivePositionCodes(), self::UNIVERSITY_WIDE)) {
            $scope = $user->getManagedStudyProgramIds();
        }

        return Krs::query()
            ->with(['student.studyProgram', 'semester.academicYear'])
            ->withCount(['details as course_count' => fn ($d) => $d->where('status', 'AKTIF')])
            ->when($filters['semester_id'] ?? null, fn ($q, $v) => $q->where('semester_id', $v))
            ->when($filters['academic_year_id'] ?? null, fn ($q, $v) => $q->whereHas('semester', fn ($s) => $s->where('academic_year_id', $v)))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereDisplayStatus($v))
            ->whereHas('student', function ($s) use ($filters, $scope) {
                if ($scope !== null) {
                    $s->whereIn('study_program_id', $scope);
                }
                if (! empty($filters['study_program_id'])) {
                    $s->where('study_program_id', $filters['study_program_id']);
                }
                if (! empty($filters['class_type'])) {
                    $s->where('class_type', $filters['class_type']);
                }
                if (! empty($filters['search'])) {
                    $term = '%'.$filters['search'].'%';
                    $s->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('nim', 'like', $term));
                }
            })
            ->orderBy('semester_id')
            ->orderBy(Student::select('name')->whereColumn('students.id', 'krs.student_id')->limit(1));
    }

    private function row(Krs $krs): array
    {
        return [
            'id' => $krs->id,
            'semester' => $krs->semester?->name,
            'academic_year' => $krs->semester?->academicYear?->name,
            'nim' => $krs->student?->nim,
            'name' => $krs->student?->name,
            'prodi' => $krs->student?->studyProgram?->name,
            'entry_year' => $krs->student?->entry_year,
            'student_status' => $krs->student?->status,
            'class_type' => $krs->student?->class_type,
            'status' => $krs->display_status_label,
            'course_count' => (int) ($krs->course_count ?? 0),
            'total_credits' => (int) $krs->total_credits,
        ];
    }

    private function summary($query): array
    {
        $byClass = [];
        foreach (Student::CLASS_TYPES as $type) {
            $byClass[$type] = (clone $query)->reorder()->whereHas('student', fn ($s) => $s->where('class_type', $type))->count();
        }

        return [
            'total_krs' => (clone $query)->reorder()->count(),
            'total_students' => (clone $query)->reorder()->distinct()->count('student_id'),
            'by_class_type' => $byClass,
        ];
    }

    private function filterLabels(Request $request): array
    {
        $labels = [];
        if ($request->semester_id) {
            $labels[] = 'Semester: '.(\App\Models\Semester::find($request->semester_id)?->name ?? '-');
        }
        if ($request->study_program_id) {
            $labels[] = 'Prodi: '.(\App\Models\StudyProgram::find($request->study_program_id)?->name ?? '-');
        }
        if ($request->class_type) {
            $labels[] = 'Jenis kelas: '.$request->class_type;
        }
        if ($request->status) {
            $labels[] = 'Status KRS: '.$request->status;
        }

        return $labels;
    }
}
