<?php

namespace App\Http\Middleware;

use App\Models\ClassModel;
use App\Models\Lecturer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClassLecturer
{
    /**
     * Pastikan user yang mengakses kelas adalah dosen pengampu kelas tersebut,
     * atau admin/kaprodi yang punya akses penuh.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admin & Kaprodi bypass
        if ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')) {
            return $next($request);
        }

        // Ambil kelas dari parameter route, termasuk route turunan yang hanya
        // membawa jurnal/materi/tugas/pengumuman.
        $class = $request->route('class');
        if (! $class instanceof ClassModel) {
            $class = is_numeric($class) ? ClassModel::find($class) : null;
        }
        foreach (['journal', 'material', 'assignment', 'announcement', 'submission'] as $parameter) {
            if ($class) {
                break;
            }
            $resource = $request->route($parameter);
            if ($resource) {
                $class = $resource->class_ ?? $resource->assignment?->class_ ?? null;
            }
        }

        if (! $class) {
            return response()->json(['message' => 'Kelas tidak ditemukan.'], 404);
        }

        // Kaprodi/Sekprodi hanya boleh mengelola kelas dalam lingkup prodinya.
        $lecturer = Lecturer::where('user_id', $user->id)->first();
        if ($lecturer) {
            $isKaprodi = \App\Models\LecturerPosition::where('lecturer_id', $lecturer->id)
                ->where('is_active', true)
                ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
                ->where('scope_type', 'study_program')
                ->where('scope_id', $class->study_program_id)
                ->exists();
            if ($isKaprodi) {
                return $next($request);
            }
        }

        // Pengampu utama maupun anggota tim pengajar boleh mengelola kelas.
        $isAssigned = $lecturer && (
            (int) $class->lecturer_id === (int) $lecturer->id
            || $class->lecturers()->whereKey($lecturer->id)->exists()
        );
        if (! $isAssigned) {
            return response()->json([
                'message' => 'Anda bukan pengampu mata kuliah di kelas ini.',
            ], 403);
        }

        return $next($request);
    }
}
