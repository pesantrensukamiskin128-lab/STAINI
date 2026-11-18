<?php

namespace App\Http\Controllers\Api;

use App\Exports\CourseExport;
use App\Http\Controllers\Controller;
use App\Imports\CourseImport;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = Course::with('studyProgram.faculty')
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%"))
            ->when($request->study_program_id, fn ($q) => $q->where('study_program_id', $request->study_program_id))
            ->when($request->semester, fn ($q) => $q->where('semester', $request->semester))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->paginate($request->per_page ?? 15);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'study_program_id' => 'required|exists:study_programs,id',
            'code' => 'required|string|max:20|unique:courses',
            'name' => 'required|string|max:255',
            'course_group' => 'nullable|string|max:150',
            'credits' => 'required|integer|min:1|max:6',
            'theory_credits' => 'nullable|integer|min:0|max:6',
            'practical_credits' => 'nullable|integer|min:0|max:6',
            'semester' => 'required|integer|min:1|max:8',
            'type' => 'required|in:Wajib,Pilihan,Praktikum',
            'delivery_mode' => 'nullable|in:TATAP_MUKA,NON_TATAP_MUKA',
            'status' => 'boolean',
        ]);

        $course = Course::create($this->normalizeCredits($validated));

        return response()->json([
            'message' => 'Mata kuliah berhasil ditambahkan.',
            'data' => $course->load('studyProgram'),
        ], 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json($course->load('studyProgram.faculty'));
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'study_program_id' => 'sometimes|exists:study_programs,id',
            'code' => "sometimes|string|max:20|unique:courses,code,{$course->id}",
            'name' => 'sometimes|string|max:255',
            'course_group' => 'nullable|string|max:150',
            'credits' => 'sometimes|integer|min:1|max:6',
            'theory_credits' => 'sometimes|integer|min:0|max:6',
            'practical_credits' => 'sometimes|integer|min:0|max:6',
            'semester' => 'sometimes|integer|min:1|max:8',
            'type' => 'sometimes|in:Wajib,Pilihan,Praktikum',
            'delivery_mode' => 'sometimes|in:TATAP_MUKA,NON_TATAP_MUKA',
            'status' => 'boolean',
        ]);

        $course->update($this->normalizeCredits($validated, $course));

        return response()->json([
            'message' => 'Mata kuliah berhasil diupdate.',
            'data' => $course->fresh('studyProgram'),
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json(['message' => 'Mata kuliah berhasil dihapus.']);
    }

    public function export(Request $request)
    {
        $filename = 'mata-kuliah-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new CourseExport($request->study_program_id, $request->semester),
            $filename
        );
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);

        $import = new CourseImport;
        Excel::import($import, $request->file('file'));

        $errors = collect($import->errors())->map(fn ($e) => $e->getMessage())->values();

        return response()->json([
            'message' => 'Import selesai.'.($errors->count() ? ' Beberapa baris dilewati.' : ''),
            'errors' => $errors,
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $studyProgramId = $request->integer('study_program_id') ?: null;

        return response()->json(
            Course::with('studyProgram:id,code,name')
                ->where('status', true)
                ->when($studyProgramId, fn ($q) => $q->where('study_program_id', $studyProgramId))
                ->when(
                    $request->boolean('active_curriculum_only'),
                    fn ($q) => $q->inActiveCurriculum($studyProgramId)
                )
                ->when($request->semester, fn ($q) => $q->where('semester', $request->semester))
                ->select(
                    'id', 'study_program_id', 'code', 'name', 'course_group', 'credits',
                    'theory_credits', 'practical_credits', 'semester', 'type'
                    , 'delivery_mode'
                )
                ->orderBy('code')
                ->get()
        );
    }

    /**
     * Menjaga total SKS selalu sama dengan jumlah SKS teori dan praktik.
     * Payload lama yang hanya mengirim total SKS tetap didukung.
     */
    private function normalizeCredits(array $validated, ?Course $course = null): array
    {
        $hasBreakdown = array_key_exists('theory_credits', $validated)
            || array_key_exists('practical_credits', $validated);

        if (! $hasBreakdown) {
            if (array_key_exists('credits', $validated)) {
                $validated['theory_credits'] = (int) $validated['credits'];
                $validated['practical_credits'] = 0;
            }

            return $validated;
        }

        $theory = (int) ($validated['theory_credits'] ?? $course?->theory_credits ?? 0);
        $practical = (int) ($validated['practical_credits'] ?? $course?->practical_credits ?? 0);
        $total = $theory + $practical;

        if ($total < 1 || $total > 6) {
            throw ValidationException::withMessages([
                'credits' => 'Jumlah SKS teori dan praktik harus antara 1 sampai 6 SKS.',
            ]);
        }

        $validated['theory_credits'] = $theory;
        $validated['practical_credits'] = $practical;
        $validated['credits'] = $total;

        return $validated;
    }
}
