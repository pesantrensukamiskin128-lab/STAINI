<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use App\Services\AcademicCalendarSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SemesterController extends Controller
{
    public function __construct(private readonly AcademicCalendarSyncService $calendarSync) {}

    public function index(Request $request): JsonResponse
    {
        $data = Semester::with('academicYear')
            ->when($request->academic_year_id, fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            ->orderByDesc('start_date')
            ->paginate($request->per_page ?? 15);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:100',
            'type' => 'required|in:Ganjil,Genap,Pendek',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'krs_start' => 'nullable|date',
            'krs_end' => [
                'nullable',
                'date',
                Rule::when($request->filled('krs_start'), 'after_or_equal:krs_start'),
            ],
            'exam_mid_start' => 'nullable|date',
            'exam_mid_end' => [
                'nullable',
                'date',
                Rule::when($request->filled('exam_mid_start'), 'after_or_equal:exam_mid_start'),
            ],
            'exam_final_start' => 'nullable|date',
            'exam_final_end' => [
                'nullable',
                'date',
                Rule::when($request->filled('exam_final_start'), 'after_or_equal:exam_final_start'),
            ],
            'is_active' => 'boolean',
        ]);

        $semester = Semester::create($validated);
        $this->calendarSync->syncSemester($semester);

        if (! empty($validated['is_active'])) {
            $semester->setAsActive();
        }

        return response()->json([
            'message' => 'Semester berhasil ditambahkan.',
            'data' => $semester->load('academicYear'),
        ], 201);
    }

    public function show(Semester $semester): JsonResponse
    {
        return response()->json($semester->load('academicYear'));
    }

    public function update(Request $request, Semester $semester): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => 'sometimes|exists:academic_years,id',
            'name' => 'sometimes|string|max:100',
            'type' => 'sometimes|in:Ganjil,Genap,Pendek',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'krs_start' => 'nullable|date',
            'krs_end' => [
                'nullable',
                'date',
                Rule::when($request->filled('krs_start'), 'after_or_equal:krs_start'),
            ],
            'exam_mid_start' => 'nullable|date',
            'exam_mid_end' => [
                'nullable',
                'date',
                Rule::when($request->filled('exam_mid_start'), 'after_or_equal:exam_mid_start'),
            ],
            'exam_final_start' => 'nullable|date',
            'exam_final_end' => [
                'nullable',
                'date',
                Rule::when($request->filled('exam_final_start'), 'after_or_equal:exam_final_start'),
            ],
            'is_active' => 'boolean',
        ]);

        $semester->update($validated);
        $this->calendarSync->syncSemester($semester->fresh());

        if (! empty($validated['is_active'])) {
            $semester->setAsActive();
        }

        return response()->json([
            'message' => 'Semester berhasil diupdate.',
            'data' => $semester->fresh('academicYear'),
        ]);
    }

    public function destroy(Semester $semester): JsonResponse
    {
        if ($semester->is_active) {
            return response()->json(['message' => 'Tidak dapat menghapus semester yang sedang aktif.'], 422);
        }

        $semester->delete();

        return response()->json(['message' => 'Semester berhasil dihapus.']);
    }

    public function activate(Semester $semester): JsonResponse
    {
        $semester->setAsActive();

        return response()->json([
            'message' => "Semester {$semester->name} berhasil diaktifkan.",
            'data' => $semester->fresh(),
        ]);
    }
}
