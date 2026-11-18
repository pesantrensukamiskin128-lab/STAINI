<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mahasiswa berstatus Lulus hanya boleh memakai KHS/Transkrip, Wisuda, Digital Library,
 * Persuratan, Agenda Kegiatan, dan Scan QR (plus fitur dasar akun).
 */
class RestrictGraduatedStudent
{
    public const ALLOWED = [
        // Akun dasar
        'api/auth/*', 'api/dashboard', 'api/notifications', 'api/notifications/*', 'api/templates/*',
        'api/files', 'api/files/*', 'api/user-list', 'api/*/all',
        // KHS / Transkrip
        'api/students/me/academic-history', 'api/grades/khs', 'api/grades/khs/pdf',
        'api/grades/transcript', 'api/grades/transcript/pdf',
        // Wisuda
        'api/graduation/*',
        // Digital Library
        'api/library/*',
        // Persuratan
        'api/outgoing-letters', 'api/outgoing-letters/*', 'api/letter-templates', 'api/letter-templates/*',
        'api/incoming-letters', 'api/incoming-letters/*', 'api/dispositions', 'api/dispositions/*',
        'api/letter-requests', 'api/letter-requests/*', 'api/my-incoming-letters', 'api/my-pending-letters',
        'api/letter-types', 'api/letter-types/*', 'api/signers', 'api/signers/*',
        // Alumni / Tracer Study (mandiri)
        'api/my-alumni', 'api/my-alumni/*',
        // Agenda Kegiatan & Scan QR
        'api/events', 'api/events/*', 'api/meeting-minutes', 'api/meeting-minutes/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $student = $user?->student;

        if ($student && $student->status === 'Lulus' && $this->isStudentOnly($user) && ! $request->is(...self::ALLOWED)) {
            return response()->json([
                'message' => 'Akses dibatasi untuk mahasiswa berstatus Lulus. Fitur yang tersedia: KHS/Transkrip, Daftar Wisuda, Digital Library, Persuratan, Agenda Kegiatan, dan Scan QR.',
            ], 403);
        }

        return $next($request);
    }

    private function isStudentOnly($user): bool
    {
        $roles = $user->getRoleNames()->all();

        return $roles !== [] && count(array_diff($roles, ['MAHASISWA'])) === 0
            && ($user->getActivePositionCodes() ?? []) === [];
    }
}
