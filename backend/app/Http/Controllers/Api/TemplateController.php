<?php

namespace App\Http\Controllers\Api;

use App\Exports\ClassOfferingExport;
use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Models\LecturerPosition;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TemplateController extends Controller
{
    private const ALLOWED = ['courses', 'lecturers', 'students', 'student-advisors', 'staff', 'class-offerings', 'krs'];

    public function download(Request $request, string $type)
    {
        if (! in_array($type, self::ALLOWED)) {
            return response()->json(['message' => 'Tipe template tidak valid.'], 422);
        }

        $names = [
            'courses' => 'template-mata-kuliah',
            'lecturers' => 'template-dosen',
            'students' => 'template-mahasiswa',
            'student-advisors' => 'template-dosen-pa-mahasiswa',
            'staff' => 'template-tenaga-kependidikan',
            'class-offerings' => 'template-penawaran-kelas',
            'krs' => 'template-krs-massal',
        ];

        if ($type === 'student-advisors') {
            $user = $request->user();
            $isAdmin = $user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM']);
            $isProgramLeader = $user?->lecturer && LecturerPosition::where('lecturer_id', $user->lecturer->id)
                ->where('is_active', true)
                ->where('position_code', 'KAPRODI')
                ->where('scope_type', 'study_program')
                ->exists();
            abort_unless($isAdmin || $isProgramLeader, 403, 'Template ini hanya tersedia untuk admin dan ketua program studi.');
        }

        if (in_array($type, ['class-offerings', 'krs'], true)) {
            $user = $request->user();
            $isAdmin = $user?->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']);
            $isProgramLeader = $user?->lecturer && LecturerPosition::where('lecturer_id', $user->lecturer->id)
                ->where('is_active', true)
                ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                ->exists();
            abort_unless($isAdmin || $isProgramLeader, 403, 'Template ini hanya tersedia untuk admin, ketua prodi, dan sekretaris prodi.');

            if ($type === 'class-offerings') {
                return Excel::download(ClassOfferingExport::template(), $names[$type].'.xlsx');
            }
        }

        return Excel::download(new TemplateExport($type), $names[$type].'.xlsx');
    }
}
