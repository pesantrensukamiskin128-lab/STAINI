<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GraduationDocument;
use App\Models\GraduationPeriod;
use App\Models\GraduationRegistration;
use App\Models\GraduationVerification;
use App\Models\Invoice;
use App\Models\Thesis;
use App\Models\Semester;
use App\Services\AcademicActivityFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GraduationController extends Controller
{
    public function __construct(private readonly AcademicActivityFinanceService $activityFinance) {}
    // === PERIODS ===
    public function periods(Request $request): JsonResponse
    {
        return response()->json(
            GraduationPeriod::with('academicYear')->withCount('registrations')->orderByDesc('graduation_date')->paginate($request->per_page ?? 15)
        );
    }

    public function storePeriod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255', 'academic_year_id' => 'required|exists:academic_years,id',
            'registration_start' => 'required|date', 'registration_end' => 'required|date',
            'graduation_date' => 'required|date', 'venue' => 'nullable|string|max:255',
            'description' => 'nullable|string', 'is_active' => 'boolean',
        ]);
        $period = GraduationPeriod::create($validated);
        return response()->json(['message' => 'Periode wisuda berhasil dibuat.', 'data' => $period], 201);
    }

    public function updatePeriod(Request $request, GraduationPeriod $period): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255', 'registration_start' => 'nullable|date',
            'registration_end' => 'nullable|date', 'graduation_date' => 'nullable|date',
            'venue' => 'nullable|string|max:255', 'description' => 'nullable|string', 'is_active' => 'boolean',
        ]);
        $period->update($validated);
        return response()->json(['message' => 'Periode wisuda diupdate.', 'data' => $period->fresh()]);
    }

    public function destroyPeriod(GraduationPeriod $period): JsonResponse
    {
        if ($period->registrations()->count() > 0) return response()->json(['message' => 'Periode sudah memiliki pendaftar.'], 422);
        $period->delete();
        return response()->json(['message' => 'Periode wisuda dihapus.']);
    }

    // === REGISTRATIONS ===
    public function registrations(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = GraduationRegistration::with(['period', 'student.studyProgram', 'verifications'])
            ->when($request->period_id, fn($q) => $q->where('period_id', $request->period_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->search, fn($q) => $q->whereHas('student', fn($q2) =>
                $q2->where('name', 'like', "%{$request->search}%")->orWhere('nim', 'like', "%{$request->search}%")));

        if ($user->hasRole('MAHASISWA') && $user->student) {
            $query->where('student_id', $user->student->id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate($request->per_page ?? 15));
    }

    public function showRegistration(GraduationRegistration $registration): JsonResponse
    {
        $user = auth()->user();
        if ($user->hasRole('MAHASISWA') && ! $user->hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])) {
            abort_unless($user->student && $registration->student_id === $user->student->id, 403);
        }

        return response()->json($registration->load(['period', 'student.studyProgram', 'verifications.verifier', 'documents']));
    }

    public function destroyRegistration(GraduationRegistration $registration): JsonResponse
    {
        DB::transaction(fn () => $registration->delete());

        return response()->json(['message' => 'Pendaftaran wisuda berhasil dihapus.']);
    }

    /** Data mahasiswa untuk prefill form pendaftaran (read-only di form) */
    public function myProfile(): JsonResponse
    {
        $student = auth()->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Bukan mahasiswa.'], 403);
        }
        $student->loadMissing('studyProgram');
        $thesis = Thesis::where('student_id', $student->id)->where('status', 'LULUS')->first();

        return response()->json([
            'name' => $student->name,
            'nim' => $student->nim,
            'birth_place' => $student->birth_place,
            'birth_date' => $student->birth_date?->format('Y-m-d'),
            'study_program' => $student->studyProgram?->name,
            'phone' => $student->phone,
            'thesis_title' => $thesis?->title,
        ]);
    }

    /** Mahasiswa mendaftar wisuda */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_id'       => 'required|exists:graduation_periods,id',
            'toga_size'       => 'nullable|string|max:10',
            'phone'           => 'nullable|string|max:20',
            'address_current' => 'required|string|max:1000',
            'thesis_title'    => 'required|string|max:500',
            'gpa'             => 'required|numeric|min:0|max:4',
            'impression_message' => 'required|string|max:3000',
            'payment_proof'   => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'photo'           => 'required|image|mimes:jpg,jpeg,png|max:3072',
        ]);

        $user = auth()->user();
        $student = $user->student;
        if (!$student) return response()->json(['message' => 'Bukan mahasiswa.'], 403);

        // Cek sudah terdaftar
        if (GraduationRegistration::where('period_id', $validated['period_id'])->where('student_id', $student->id)->exists()) {
            return response()->json(['message' => 'Sudah terdaftar di periode ini.'], 422);
        }
        $period = GraduationPeriod::findOrFail($validated['period_id']);
        if ($student->status !== 'Lulus') {
            return response()->json(['message' => 'Pendaftaran wisuda hanya untuk mahasiswa berstatus Lulus.'], 422);
        }

        $reg = DB::transaction(function () use ($validated, $student, $request) {
            $reg = GraduationRegistration::create([
                'period_id'     => $validated['period_id'],
                'student_id'    => $student->id,
                'status'        => 'SUBMITTED',
                'toga_size'     => $validated['toga_size'] ?? null,
                'phone'         => $validated['phone'] ?? $student->phone,
                'address_current' => $validated['address_current'],
                'total_credits' => 0, // Akan dihitung saat verifikasi
                'gpa'           => $validated['gpa'],
                'thesis_title'  => $validated['thesis_title'],
                'impression_message' => $validated['impression_message'],
                'payment_proof_path' => $request->file('payment_proof')->store('graduation/payment-proofs', 'public'),
                'photo_path'    => $request->file('photo')->store('graduation/photos', 'public'),
                'submitted_at'  => now(),
            ]);

            // Satu-satunya syarat verifikasi: keuangan, diperiksa admin
            $requirements = [
                ['requirement' => 'Bebas Tagihan Keuangan', 'category' => 'KEUANGAN'],
            ];

            foreach ($requirements as $req) {
                GraduationVerification::create(array_merge($req, ['registration_id' => $reg->id]));
            }

            return $reg;
        });

        foreach (\App\Models\User::role(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])->where('is_active', true)->get(['id']) as $admin) {
            \App\Models\AppNotification::send(
                $admin->id,
                'Pendaftaran Wisuda Baru',
                "Mahasiswa {$student->name} mengajukan pendaftaran wisuda.",
                'info',
                '/wisuda/'.$reg->id
            );
        }

        return response()->json(['message' => 'Pendaftaran wisuda berhasil.', 'data' => $reg->load('verifications')], 201);
    }

    /** Verifikasi syarat */
    public function verify(Request $request, GraduationVerification $verification): JsonResponse
    {
        $validated = $request->validate([
            'is_fulfilled' => 'required|boolean',
            'notes'        => 'nullable|string',
        ]);

        $previousFulfilled = (bool) $verification->is_fulfilled;
        $verification->update([
            'is_fulfilled' => $validated['is_fulfilled'],
            'verified_by'  => auth()->id(),
            'verified_at'  => now(),
            'notes'        => $validated['notes'] ?? null,
        ]);

        // Cek apakah semua verifikasi terpenuhi
        $reg = $verification->registration;
        $allFulfilled = $reg->verifications()->where('is_fulfilled', false)->doesntExist();
        $becameApproved = $allFulfilled && $reg->status !== 'APPROVED';
        if ($becameApproved) {
            $reg->update(['status' => 'APPROVED']);
        }
        $student = $reg->student;
        if ($student?->user_id && ($previousFulfilled !== (bool) $validated['is_fulfilled'] || $becameApproved || $verification->wasChanged('notes'))) {
            \App\Models\AppNotification::send(
                $student->user_id,
                $becameApproved ? 'Persyaratan Wisuda Lengkap' : 'Persyaratan Wisuda Diperbarui',
                $becameApproved
                    ? 'Seluruh persyaratan wisuda Anda telah diverifikasi dan pendaftaran disetujui.'
                    : "Persyaratan \"{$verification->requirement}\" telah ".($validated['is_fulfilled'] ? 'terpenuhi.' : 'dinyatakan belum terpenuhi.').($validated['notes'] ? ' Catatan: '.$validated['notes'] : ''),
                $validated['is_fulfilled'] ? 'success' : 'warning',
                '/akademik-saya/wisuda'
            );
        }

        return response()->json(['message' => 'Verifikasi berhasil diupdate.']);
    }

    /** Auto verifikasi keuangan */
    public function autoVerifyFinance(GraduationRegistration $registration): JsonResponse
    {
        $hasUnpaid = Invoice::where('student_id', $registration->student_id)
            ->whereIn('status', ['UNPAID', 'PARTIAL', 'OVERDUE'])->exists();

        $verification = $registration->verifications()->where('category', 'KEUANGAN')->first();
        if ($verification) {
            $previousFulfilled = (bool) $verification->is_fulfilled;
            $verification->update([
                'is_fulfilled' => !$hasUnpaid,
                'verified_by'  => auth()->id(),
                'verified_at'  => now(),
                'notes'        => $hasUnpaid ? 'Masih ada tagihan belum lunas.' : 'Semua tagihan lunas.',
            ]);
            $verification->loadMissing('registration.student');
            if ($verification->registration?->student?->user_id
                && ($previousFulfilled !== !$hasUnpaid || $verification->wasChanged('notes'))) {
                \App\Models\AppNotification::send(
                    $verification->registration->student->user_id,
                    $hasUnpaid ? 'Verifikasi Keuangan Wisuda' : 'Persyaratan Keuangan Wisuda Terpenuhi',
                    $hasUnpaid
                        ? 'Persyaratan keuangan wisuda Anda belum terpenuhi karena masih ada tagihan.'
                        : 'Persyaratan keuangan wisuda Anda telah dinyatakan terpenuhi.',
                    $hasUnpaid ? 'warning' : 'success',
                    '/akademik-saya/wisuda'
                );
            }
        }

        return response()->json(['message' => $hasUnpaid ? 'Mahasiswa masih memiliki tagihan.' : 'Keuangan clear.', 'is_clear' => !$hasUnpaid]);
    }

    /** Update status pendaftaran */
    public function updateRegistrationStatus(Request $request, GraduationRegistration $registration): JsonResponse
    {
        $request->validate(['status' => 'required|in:SUBMITTED,VERIFIKASI_AKADEMIK,VERIFIKASI_KEUANGAN,VERIFIKASI_PERPUSTAKAAN,APPROVED,REJECTED,WISUDA', 'notes' => 'nullable|string']);
        $registration->update(['status' => $request->status, 'notes' => $request->notes ?? $registration->notes]);

        if ($request->status === 'WISUDA') {
            // Update status mahasiswa ke LULUS
            $registration->student->recordStatus('Lulus', null, 'Wisuda');
        }
        $registration->loadMissing('student');
        if ($registration->student?->user_id && $registration->wasChanged(['status', 'notes'])) {
            \App\Models\AppNotification::send(
                $registration->student->user_id,
                'Status Pendaftaran Wisuda Diperbarui',
                'Status pendaftaran wisuda Anda sekarang: '.$request->status.'.'.($request->notes ? ' Catatan: '.$request->notes : ''),
                $request->status === 'REJECTED' ? 'error' : 'info',
                '/akademik-saya/wisuda'
            );
        }

        return response()->json(['message' => 'Status berhasil diupdate.']);
    }

    /** Set predikat kelulusan */
    public function setPredicate(Request $request, GraduationRegistration $registration): JsonResponse
    {
        $request->validate(['predicate' => 'required|string|max:50', 'gpa' => 'nullable|numeric', 'total_credits' => 'nullable|integer']);
        $registration->update($request->only(['predicate', 'gpa', 'total_credits']));
        $registration->loadMissing('student');
        if ($registration->student?->user_id && $registration->wasChanged(['predicate', 'gpa', 'total_credits'])) {
            \App\Models\AppNotification::send(
                $registration->student->user_id,
                'Predikat Kelulusan Ditetapkan',
                "Predikat kelulusan Anda ditetapkan: {$registration->predicate}.",
                'success',
                '/akademik-saya/wisuda'
            );
        }
        return response()->json(['message' => 'Predikat berhasil ditetapkan.']);
    }

    // === DASHBOARD ===
    public function dashboard(Request $request): JsonResponse
    {
        $periodId = $request->period_id;
        $query = GraduationRegistration::when($periodId, fn($q) => $q->where('period_id', $periodId));

        return response()->json([
            'total'       => (clone $query)->count(),
            'submitted'   => (clone $query)->where('status', 'SUBMITTED')->count(),
            'in_process'  => (clone $query)->whereIn('status', ['VERIFIKASI_AKADEMIK', 'VERIFIKASI_KEUANGAN', 'VERIFIKASI_PERPUSTAKAAN'])->count(),
            'approved'    => (clone $query)->where('status', 'APPROVED')->count(),
            'graduated'   => (clone $query)->where('status', 'WISUDA')->count(),
            'rejected'    => (clone $query)->where('status', 'REJECTED')->count(),
        ]);
    }
}
