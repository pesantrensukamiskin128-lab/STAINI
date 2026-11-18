<?php

namespace App\Http\Controllers\Api;

use App\Exports\StudentAdvisorExport;
use App\Http\Controllers\Controller;
use App\Imports\StudentAdvisorImport;
use App\Models\LecturerPosition;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StudentAdvisorController extends Controller
{
    public function export(Request $request)
    {
        $validated = $request->validate([
            'study_program_id' => 'nullable|integer|exists:study_programs,id',
            'status' => ['nullable', Rule::in(Student::STATUSES)],
        ]);
        $managedStudyProgramIds = $this->managedStudyProgramIds($request);
        $this->assertProgramFilterAllowed($validated['study_program_id'] ?? null, $managedStudyProgramIds);

        return Excel::download(
            new StudentAdvisorExport(
                $managedStudyProgramIds,
                isset($validated['study_program_id']) ? (int) $validated['study_program_id'] : null,
                $validated['status'] ?? null,
            ),
            'dosen-pa-mahasiswa-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new StudentAdvisorImport($this->managedStudyProgramIds($request));
        Excel::import($import, $request->file('file'));

        $errors = $import->errors();

        return response()->json([
            'message' => 'Import dosen PA selesai: '.$import->updatedCount().' penugasan diperbarui.'
                .(count($errors) ? ' Beberapa baris tidak diproses.' : ''),
            'updated' => $import->updatedCount(),
            'errors' => $errors,
        ]);
    }

    /**
     * Admin roles can manage all programs; a Kaprodi is restricted to active program scopes.
     *
     * @return array<int, int>|null
     */
    private function managedStudyProgramIds(Request $request): ?array
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM'])) {
            return null;
        }

        $lecturerId = $user->lecturer?->id;
        $programIds = $lecturerId
            ? LecturerPosition::query()
                ->where('lecturer_id', $lecturerId)
                ->where('position_code', 'KAPRODI')
                ->where('scope_type', 'study_program')
                ->where('is_active', true)
                ->whereNotNull('scope_id')
                ->pluck('scope_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        abort_if($programIds === [], 403, 'Fitur ini hanya tersedia untuk admin dan ketua program studi.');

        return $programIds;
    }

    private function assertProgramFilterAllowed(?int $studyProgramId, ?array $managedStudyProgramIds): void
    {
        if ($studyProgramId !== null && $managedStudyProgramIds !== null) {
            abort_unless(in_array($studyProgramId, $managedStudyProgramIds, true), 403, 'Program studi di luar cakupan Anda.');
        }
    }
}
