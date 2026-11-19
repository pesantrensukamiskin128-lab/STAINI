<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HonorActivity;
use App\Models\HonorPayroll;
use App\Models\HonorPayrollDeduction;
use App\Models\HonorPayrollItem;
use App\Models\HonorRate;
use App\Models\Institution;
use App\Models\LectureJournal;
use App\Models\Lecturer;
use App\Models\LecturerPosition;
use App\Models\Semester;
use App\Models\Staff;
use App\Helpers\QrCodeHelper;
use App\Services\HonorPayrollService;
use App\Support\HonorSlipVerification;
use App\Support\OperationalDocumentVerification;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HonorController extends Controller
{
    public function __construct(private readonly HonorPayrollService $payrolls) {}

    public function lectureJournalReviews(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])],
            'per_page' => 'nullable|integer|min:1|max:100',
            'lecturer' => 'nullable|string|max:100',
            'course' => 'nullable|string|max:100',
            'study_program_id' => 'nullable|integer',
        ]);

        $journals = LectureJournal::query()
            ->when($validated['lecturer'] ?? null, fn ($q, $v) => $q->whereHas(
                'lecturer',
                fn ($l) => $l->where('full_name', 'like', "%{$v}%"),
            ))
            ->when($validated['course'] ?? null, fn ($q, $v) => $q->whereHas(
                'class_.course',
                fn ($c) => $c->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"),
            ))
            ->when($validated['study_program_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'class_.course',
                fn ($c) => $c->where('study_program_id', $v),
            ))
            ->with([
                'class_.course:id,code,name,study_program_id',
                'class_.course.studyProgram:id,name',
                'class_.semester:id,name',
                'lecturer:id,full_name,degree_front,degree_back',
                'reviewer:id,name',
            ])
            ->where('status', 'COMPLETED')
            ->where('review_status', $validated['status'] ?? 'PENDING')
            ->orderByDesc('meeting_date')
            ->orderBy('class_id')
            ->orderBy('meeting_number')
            ->paginate($validated['per_page'] ?? 50);

        $journals->getCollection()->each(function (LectureJournal $journal): void {
            $journal->setAttribute(
                'photo_url',
                $journal->photo_path ? url(Storage::disk('public')->url($journal->photo_path)) : null,
            );
        });

        return response()->json($journals);
    }

    public function reviewLectureJournal(Request $request, LectureJournal $journal): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'review_note' => ['nullable', 'required_if:status,REJECTED', 'string', 'max:2000'],
        ]);

        $journal = DB::transaction(function () use ($journal, $request, $validated): LectureJournal {
            $journal = LectureJournal::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless(
                $journal->status === 'COMPLETED' && $journal->review_status === 'PENDING',
                409,
                'Jurnal ini tidak lagi menunggu pemeriksaan.',
            );

            $journal->review_status = $validated['status'];
            $journal->reviewed_by = $request->user()->id;
            $journal->reviewed_at = now();
            $journal->review_note = $validated['review_note'] ?? null;
            $journal->save();

            return $journal->fresh(['reviewer:id,name']);
        });

        return response()->json([
            'message' => $journal->review_status === 'APPROVED'
                ? 'Jurnal disetujui dan akan dihitung satu kali dalam honor.'
                : 'Jurnal ditolak dan menunggu perbaikan dosen.',
            'data' => $journal,
        ]);
    }

    public function rates(): JsonResponse
    {
        $rates = HonorRate::query()
            ->withCount('activities')
            ->addSelect([
                'payroll_items_count' => DB::table('honor_payroll_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('honor_payroll_items.honor_rate_id', 'honor_rates.id'),
            ])
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json($rates);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => ['required', Rule::in(HonorRate::CATEGORIES)],
            'unit' => ['required', Rule::in(HonorRate::UNITS)],
            'position_code' => 'nullable|string|max:100',
            'delivery_mode' => ['nullable', Rule::in(HonorRate::DELIVERY_MODES)],
            'amount' => 'required|integer|min:1',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $this->assertRateConfiguration($validated);
        $this->assertNoOverlappingRate($validated);

        $rate = HonorRate::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Tarif honor berhasil dibuat.', 'data' => $rate], 201);
    }

    public function updateRate(Request $request, HonorRate $rate): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => ['sometimes', 'required', Rule::in(HonorRate::CATEGORIES)],
            'unit' => ['sometimes', 'required', Rule::in(HonorRate::UNITS)],
            'position_code' => 'sometimes|nullable|string|max:100',
            'delivery_mode' => ['sometimes', 'nullable', Rule::in(HonorRate::DELIVERY_MODES)],
            'amount' => 'sometimes|required|integer|min:1',
            'effective_from' => 'sometimes|required|date',
            'effective_until' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|required|boolean',
        ]);

        $configuration = [
            ...$rate->only(['name', 'category', 'unit', 'position_code', 'delivery_mode', 'amount']),
            'effective_from' => $rate->effective_from->toDateString(),
            'effective_until' => $rate->effective_until?->toDateString(),
            ...$validated,
        ];
        $configurationChanged = collect([
            'name', 'category', 'unit', 'position_code', 'delivery_mode', 'amount', 'effective_from',
        ])->contains(fn (string $field): bool => array_key_exists($field, $validated)
            && (string) ($configuration[$field] ?? '') !== (string) ($rate->{$field} ?? ''));
        $hasUsage = $rate->activities()->exists()
            || HonorPayrollItem::query()->where('honor_rate_id', $rate->id)->exists();
        abort_if($configurationChanged && $hasUsage, 409, 'Tarif sudah dipakai pada aktivitas atau rincian honor dan tidak dapat diedit. Buat tarif baru untuk perubahan berikutnya.');
        abort_if(
            ! $rate->is_active && ($validated['is_active'] ?? false),
            409,
            'Tarif yang sudah dinonaktifkan tidak dapat diaktifkan kembali.',
        );

        $this->assertRateConfiguration($configuration);
        $effectiveFrom = $configuration['effective_from'];
        $effectiveUntil = $configuration['effective_until'];
        if ($effectiveUntil && $effectiveUntil < $effectiveFrom) {
            throw ValidationException::withMessages([
                'effective_until' => ['Tanggal akhir tarif tidak boleh sebelum tanggal mulai.'],
            ]);
        }

        if ($rate->activities()->exists() && $effectiveUntil) {
            $lastActivityDate = $rate->activities()->max('activity_date');
            abort_if(
                $lastActivityDate && $lastActivityDate > $effectiveUntil,
                422,
                'Masa berlaku tidak dapat berakhir sebelum aktivitas yang sudah dicatat.',
            );
        }
        if (($validated['is_active'] ?? $rate->is_active) === false && ! $effectiveUntil) {
            throw ValidationException::withMessages(['effective_until' => ['Tentukan tanggal akhir tarif saat menonaktifkannya.']]);
        }

        $this->assertNoOverlappingRate($configuration, $rate->id);
        $rate->update([
            ...$validated,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'is_active' => $validated['is_active'] ?? $rate->is_active,
        ]);

        return response()->json(['message' => 'Tarif honor berhasil diperbarui.', 'data' => $rate->fresh()]);
    }

    public function destroyRate(HonorRate $rate): JsonResponse
    {
        abort_if(
            $rate->activities()->exists() || HonorPayrollItem::query()->where('honor_rate_id', $rate->id)->exists(),
            409,
            'Tarif sudah dipakai pada aktivitas atau rincian honor dan tidak dapat dihapus.',
        );

        $rate->delete();

        return response()->json(['message' => 'Tarif honor berhasil dihapus.']);
    }

    public function activities(Request $request): JsonResponse
    {
        $activities = HonorActivity::query()
            ->with(['lecturer:id,full_name,degree_front,degree_back,nidn,user_id', 'rate:id,name,category,unit,amount', 'semester:id,name', 'verifier:id,name'])
            ->withExists([
                'payrollItems as is_locked' => fn ($query) => $query->whereHas(
                    'payroll',
                    fn ($payrollQuery) => $payrollQuery->whereIn('status', ['APPROVED', 'PAID']),
                ),
            ])
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->honor_rate_id, fn ($query, $rateId) => $query->where('honor_rate_id', $rateId))
            ->when(trim((string) $request->search) !== '', function ($query) use ($request) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->search)).'%';
                $query->where(fn ($q) => $q->where('description', 'like', $term)
                    ->orWhere('reference_number', 'like', $term)
                    ->orWhereHas('lecturer', fn ($l) => $l->where('full_name', 'like', $term)->orWhere('nidn', 'like', $term))
                    ->orWhereHas('rate', fn ($r) => $r->where('name', 'like', $term)));
            })
            ->when($request->date_from, fn ($query, $date) => $query->whereDate('activity_date', '>=', $date))
            ->when($request->date_to, fn ($query, $date) => $query->whereDate('activity_date', '<=', $date))
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 30), 1), 100));

        return response()->json($activities);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lecturer_id' => 'required|exists:lecturers,id',
            'honor_rate_id' => 'required|exists:honor_rates,id',
            'reference_number' => [
                'required', 'string', 'max:100',
                Rule::unique('honor_activities')->where(fn ($query) => $query->where('lecturer_id', $request->input('lecturer_id'))),
            ],
            'description' => 'required|string|max:255',
            'activity_date' => 'required|date',
            'quantity' => 'required|numeric|min:0.01|max:100000',
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        $rate = HonorRate::query()->findOrFail($validated['honor_rate_id']);
        abort_unless($rate->category === 'INCIDENTAL' && $rate->unit === 'PER_ACTIVITY', 422, 'Aktivitas insidental harus menggunakan tarif insidental.');
        $this->assertRateEffective($rate, $validated['activity_date']);
        $this->assertActivityPeriodIsOpen($validated['activity_date'], $rate->category);

        $amount = (int) round((float) $rate->amount * (float) $validated['quantity']);
        $activity = HonorActivity::create([
            ...$validated,
            'source_key' => 'manual:'.Str::uuid(),
            'source_type' => 'INCIDENTAL',
            'unit_rate_snapshot' => $rate->amount,
            'amount_snapshot' => $amount,
            'status' => 'PENDING',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Aktivitas honor dicatat dan menunggu verifikasi.',
            'data' => $activity->load(['lecturer', 'rate', 'semester']),
        ], 201);
    }

    public function updateActivity(Request $request, HonorActivity $activity): JsonResponse
    {
        $this->assertActivityCanBeEdited($activity);
        $validated = $request->validate([
            'lecturer_id' => 'required|exists:lecturers,id',
            'honor_rate_id' => 'required|exists:honor_rates,id',
            'reference_number' => [
                'required', 'string', 'max:100',
                Rule::unique('honor_activities')
                    ->where(fn ($query) => $query->where('lecturer_id', $request->input('lecturer_id')))
                    ->ignore($activity->id),
            ],
            'description' => 'required|string|max:255',
            'activity_date' => 'required|date',
            'quantity' => 'required|numeric|min:0.01|max:100000',
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        $rate = HonorRate::query()->findOrFail($validated['honor_rate_id']);
        abort_unless($rate->category === 'INCIDENTAL' && $rate->unit === 'PER_ACTIVITY', 422, 'Aktivitas insidental harus menggunakan tarif insidental.');
        $this->assertRateEffective($rate, $validated['activity_date']);
        $this->assertActivityPeriodIsOpen($validated['activity_date'], $rate->category);

        DB::transaction(function () use ($activity, $validated, $rate): void {
            $activity = HonorActivity::query()->lockForUpdate()->findOrFail($activity->id);
            $this->assertActivityCanBeEdited($activity);

            HonorPayrollItem::query()
                ->where('source_key', $activity->source_key)
                ->whereHas('payroll', fn ($query) => $query->where('status', 'DRAFT'))
                ->delete();

            $activity->update([
                ...$validated,
                'unit_rate_snapshot' => $rate->amount,
                'amount_snapshot' => (int) round((float) $rate->amount * (float) $validated['quantity']),
                'status' => 'PENDING',
                'verified_by' => null,
                'verified_at' => null,
                'verification_note' => null,
            ]);
        });

        return response()->json([
            'message' => 'Aktivitas honor diperbarui dan dikembalikan ke status menunggu verifikasi.',
            'data' => $activity->fresh(['lecturer', 'rate', 'semester']),
        ]);
    }

    public function destroyActivity(HonorActivity $activity): JsonResponse
    {
        $this->assertActivityCanBeEdited($activity);

        DB::transaction(function () use ($activity): void {
            $activity = HonorActivity::query()->lockForUpdate()->findOrFail($activity->id);
            $this->assertActivityCanBeEdited($activity);

            HonorPayrollItem::query()
                ->where('source_key', $activity->source_key)
                ->whereHas('payroll', fn ($query) => $query->where('status', 'DRAFT'))
                ->delete();
            $activity->delete();
        });

        return response()->json(['message' => 'Aktivitas honor berhasil dihapus.']);
    }

    private function assertActivityCanBeEdited(HonorActivity $activity): void
    {
        abort_unless(
            $activity->source_type === 'INCIDENTAL'
                && str_starts_with($activity->source_key, 'manual:')
                && in_array($activity->status, ['PENDING', 'VERIFIED', 'REJECTED'], true),
            409,
            'Hanya aktivitas insidental manual yang dapat diedit atau dihapus.',
        );

        $lockedPayrollItemExists = HonorPayrollItem::query()
            ->where('source_key', $activity->source_key)
            ->whereHas('payroll', fn ($query) => $query->whereIn('status', ['APPROVED', 'PAID']))
            ->exists();
        abort_if($lockedPayrollItemExists, 409, 'Aktivitas sudah masuk periode honor yang disahkan atau dibayar dan tidak dapat diubah.');
    }

    public function verifyActivity(Request $request, HonorActivity $activity): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['VERIFIED', 'REJECTED'])],
            'verification_note' => 'nullable|string|max:1000',
        ]);

        abort_unless($activity->status === 'PENDING', 409, 'Aktivitas ini sudah diverifikasi.');
        $this->assertActivityPeriodIsOpen($activity->activity_date->toDateString(), $activity->rate->category);

        $activity->update([
            ...$validated,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);
        $activity->loadMissing('lecturer');
        if ($activity->lecturer?->user_id) {
            \App\Models\AppNotification::send(
                $activity->lecturer->user_id,
                $validated['status'] === 'VERIFIED' ? 'Aktivitas Honor Disetujui' : 'Aktivitas Honor Ditolak',
                $validated['status'] === 'VERIFIED'
                    ? "Aktivitas honor \"{$activity->description}\" telah diverifikasi."
                    : "Aktivitas honor \"{$activity->description}\" ditolak.".($validated['verification_note'] ? ' Catatan: '.$validated['verification_note'] : ''),
                $validated['status'] === 'VERIFIED' ? 'success' : 'warning',
                '/keuangan/honor-saya'
            );
        }

        return response()->json(['message' => 'Verifikasi aktivitas berhasil disimpan.', 'data' => $activity->fresh(['lecturer', 'rate', 'verifier'])]);
    }

    public function payrolls(): JsonResponse
    {
        $payrolls = HonorPayroll::query()
            ->with(['semester:id,name', 'creator:id,name', 'approver:id,name', 'payer:id,name'])
            ->withCount('items')
            ->orderByDesc('period_start')
            ->paginate(30);

        $payrolls->getCollection()->transform(function (HonorPayroll $payroll): HonorPayroll {
            $gross = (int) $payroll->items()->sum('amount_snapshot');
            $deductions = (int) $payroll->deductions()->sum('amount');
            $payroll->setAttribute('gross_amount', $gross);
            $payroll->setAttribute('deduction_amount', $deductions);
            $payroll->setAttribute('total_amount', $gross - $deductions);

            return $payroll;
        });

        return response()->json($payrolls);
    }

    public function payrollPdf(Request $request, HonorPayroll $payroll)
    {
        $payroll->load([
            'semester:id,name',
            'items.lecturer:id,full_name,degree_front,degree_back,nidn',
            'items.staff:id,name,nip,position',
            'items.rate:id,name,category,unit,delivery_mode',
            'deductions',
        ]);

        $search = mb_strtolower(trim((string) $request->input('search', '')));
        $groups = [];
        foreach ($payroll->items as $item) {
            $isLecturer = $item->lecturer_id !== null;
            $key = ($isLecturer ? 'lecturer:' : 'staff:').($item->lecturer_id ?? $item->staff_id);
            $groups[$key] ??= [
                'name' => $isLecturer ? ($item->lecturer?->display_name ?? '-') : ($item->staff?->name ?? '-'),
                'identifier' => $isLecturer ? $item->lecturer?->nidn : $item->staff?->nip,
                'lines' => [],
                'gross' => 0,
                'deduction' => 0,
            ];
            $groups[$key]['gross'] += (int) $item->amount_snapshot;
            $groups[$key]['lines'][] = [
                'label' => $item->description,
                'quantity' => (float) $item->quantity,
                'rate' => (int) $item->unit_rate_snapshot,
                'amount' => (int) $item->amount_snapshot,
            ];
        }
        foreach ($payroll->deductions as $deduction) {
            $key = ($deduction->lecturer_id ? 'lecturer:' : 'staff:').($deduction->lecturer_id ?? $deduction->staff_id);
            if (isset($groups[$key])) {
                $groups[$key]['deduction'] += (int) $deduction->amount;
            }
        }
        if ($search !== '') {
            $groups = array_filter($groups, fn (array $group): bool => str_contains(mb_strtolower($group['name'].' '.$group['identifier'].' '.collect($group['lines'])->pluck('label')->implode(' ')), $search));
        }
        uasort($groups, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $institution = Institution::first();
        $pdf = Pdf::loadView('pdf.honor-payroll-recap', [
            'payroll' => $payroll,
            'groups' => array_values($groups),
            'grossTotal' => array_sum(array_column($groups, 'gross')),
            'deductionTotal' => array_sum(array_column($groups, 'deduction')),
            'institution' => $institution,
            'filtered' => $search !== '',
            'printedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('rincian-honor-'.$payroll->period_key.'.pdf');
    }

    public function showPayroll(HonorPayroll $payroll): JsonResponse
    {
        $payroll->load([
            'semester:id,name',
            'items.lecturer:id,full_name,degree_front,degree_back,nidn',
            'items.lecturer.user:id,name,email',
            'items.staff:id,name,nip,position,user_id',
            'items.staff.user:id,name,email',
            'items.rate:id,name,category,unit,delivery_mode',
            'deductions.lecturer:id,full_name,degree_front,degree_back',
            'deductions.staff:id,name',
            'approver:id,name',
            'payer:id,name',
        ]);
        $gross = (int) $payroll->items->sum('amount_snapshot');
        $deductions = (int) $payroll->deductions->sum('amount');
        $payroll->setAttribute('gross_amount', $gross);
        $payroll->setAttribute('deduction_amount', $deductions);
        $payroll->setAttribute('total_amount', $gross - $deductions);
        $payroll->setAttribute('pending_activity_count', $this->pendingActivityCount($payroll));

        return response()->json(['data' => $payroll]);
    }

    public function storePayrollDeduction(Request $request, HonorPayroll $payroll): JsonResponse
    {
        $validated = $request->validate([
            'recipient_type' => ['required', Rule::in(['lecturer', 'staff'])],
            'recipient_id' => 'required|integer|min:1',
            'deduction_type' => ['required', Rule::in(['TAX', 'OTHER'])],
            'description' => 'required|string|max:255',
            'amount' => 'required|integer|min:1',
        ]);
        $recipientColumn = $validated['recipient_type'] === 'lecturer' ? 'lecturer_id' : 'staff_id';

        $deduction = DB::transaction(function () use ($request, $payroll, $validated, $recipientColumn): HonorPayrollDeduction {
            $lockedPayroll = HonorPayroll::query()->lockForUpdate()->findOrFail($payroll->id);
            abort_unless($lockedPayroll->status === 'DRAFT', 409, 'Potongan hanya dapat diubah selama periode masih draft.');
            abort_unless(
                $lockedPayroll->items()->where($recipientColumn, $validated['recipient_id'])->exists(),
                422,
                'Penerima tidak memiliki rincian honor pada periode ini.',
            );

            $gross = (int) $lockedPayroll->items()->where($recipientColumn, $validated['recipient_id'])->sum('amount_snapshot');
            $deducted = (int) $lockedPayroll->deductions()->where($recipientColumn, $validated['recipient_id'])->sum('amount');
            abort_if($deducted + $validated['amount'] > $gross, 422, 'Jumlah potongan tidak boleh melebihi honor bruto penerima.');

            return $lockedPayroll->deductions()->create([
                $recipientColumn => $validated['recipient_id'],
                'deduction_type' => $validated['deduction_type'],
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Potongan honor berhasil ditambahkan.',
            'data' => $deduction->load($validated['recipient_type']),
        ], 201);
    }

    public function destroyPayrollDeduction(HonorPayroll $payroll, HonorPayrollDeduction $deduction): JsonResponse
    {
        abort_unless((int) $deduction->honor_payroll_id === (int) $payroll->id, 404);

        DB::transaction(function () use ($payroll, $deduction): void {
            $lockedPayroll = HonorPayroll::query()->lockForUpdate()->findOrFail($payroll->id);
            abort_unless($lockedPayroll->status === 'DRAFT', 409, 'Potongan hanya dapat diubah selama periode masih draft.');
            $deduction->delete();
        });

        return response()->json(['message' => 'Potongan honor berhasil dihapus.']);
    }

    public function storePayroll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_type' => ['required', Rule::in(['MONTHLY', 'SEMESTER', 'INCIDENTAL'])],
            'year' => 'required_if:period_type,MONTHLY|integer|min:2000|max:2200',
            'month' => 'required_if:period_type,MONTHLY|integer|min:1|max:12',
            'semester_id' => 'required_if:period_type,SEMESTER|exists:semesters,id',
            'activity_date' => 'required_if:period_type,INCIDENTAL|date',
        ]);

        $period = $this->resolvePayrollPeriod($validated);

        $payroll = HonorPayroll::query()->firstOrCreate(
            ['period_key' => $period['period_key']],
            [
                'period_type' => $validated['period_type'],
                'semester_id' => $period['semester_id'],
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'status' => 'DRAFT',
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'message' => $payroll->wasRecentlyCreated ? 'Periode honor berhasil dibuat.' : 'Periode honor sudah tersedia.',
            'data' => $payroll->load('semester'),
        ], $payroll->wasRecentlyCreated ? 201 : 200);
    }

    public function updatePayroll(Request $request, HonorPayroll $payroll): JsonResponse
    {
        abort_unless($payroll->status === 'DRAFT', 409, 'Hanya periode draft yang dapat diedit.');
        $validated = $request->validate([
            'period_type' => ['required', Rule::in(['MONTHLY', 'SEMESTER', 'INCIDENTAL'])],
            'year' => 'required_if:period_type,MONTHLY|integer|min:2000|max:2200',
            'month' => 'required_if:period_type,MONTHLY|integer|min:1|max:12',
            'semester_id' => 'required_if:period_type,SEMESTER|exists:semesters,id',
            'activity_date' => 'required_if:period_type,INCIDENTAL|date',
        ]);

        $period = $this->resolvePayrollPeriod($validated);
        $duplicate = HonorPayroll::query()
            ->where('period_key', $period['period_key'])
            ->where('id', '!=', $payroll->id)
            ->exists();
        abort_if($duplicate, 409, 'Periode honor tersebut sudah tersedia.');

        $periodChanged = collect(['period_key', 'period_type', 'semester_id', 'period_start', 'period_end'])
            ->contains(fn (string $field): bool => (string) ($payroll->{$field} ?? '') !== (string) ($period[$field] ?? ''));

        DB::transaction(function () use ($payroll, $period, $periodChanged): void {
            if ($periodChanged) {
                $payroll->items()->delete();
                $payroll->deductions()->delete();
            }
            $payroll->update($period);
        });

        return response()->json([
            'message' => $periodChanged
                ? 'Periode honor diperbarui. Snapshot draft lama dihapus; hitung ulang sebelum pengesahan.'
                : 'Periode honor berhasil diperbarui.',
            'data' => $payroll->fresh(['semester']),
        ]);
    }

    public function destroyPayroll(HonorPayroll $payroll): JsonResponse
    {
        abort_unless(
            in_array($payroll->status, ['DRAFT', 'PAID'], true),
            409,
            'Hanya periode draft atau sudah dibayar yang dapat dihapus.',
        );

        DB::transaction(function () use ($payroll): void {
            $payroll->items()->delete();
            $payroll->deductions()->delete();
            $payroll->delete();
        });

        return response()->json(['message' => 'Periode honor beserta seluruh snapshot dan referensi pembayarannya berhasil dihapus.']);
    }

    private function resolvePayrollPeriod(array $validated): array
    {
        if ($validated['period_type'] === 'INCIDENTAL') {
            $date = Carbon::parse($validated['activity_date'])->toDateString();

            return [
                'period_key' => 'INCIDENTAL-'.$date,
                'period_type' => 'INCIDENTAL',
                'semester_id' => null,
                'period_start' => $date,
                'period_end' => $date,
            ];
        }

        if ($validated['period_type'] === 'MONTHLY') {
            $start = Carbon::create((int) $validated['year'], (int) $validated['month'], 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            return [
                'period_key' => 'MONTHLY-'.$start->format('Y-m'),
                'period_type' => 'MONTHLY',
                'semester_id' => null,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ];
        }

        $semester = Semester::query()->findOrFail($validated['semester_id']);
        abort_unless($semester->start_date && $semester->end_date, 422, 'Tanggal mulai dan akhir semester harus dikonfigurasi terlebih dahulu.');
        $start = $semester->start_date->copy()->startOfDay();
        $end = $semester->end_date->copy()->startOfDay();
        abort_if($end->lt($start), 422, 'Rentang tanggal semester tidak valid.');

        return [
            'period_key' => 'SEMESTER-'.$semester->id,
            'period_type' => 'SEMESTER',
            'semester_id' => $semester->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ];
    }

    public function calculatePayroll(HonorPayroll $payroll): JsonResponse
    {
        $payroll = $this->payrolls->prepare($payroll);

        return response()->json([
            'message' => 'Perhitungan disiapkan dari aktivitas terverifikasi. Aktivitas yang belum diverifikasi tidak dimasukkan.',
            'data' => $payroll,
            'summary' => [
                'item_count' => $payroll->items->count(),
                'gross_amount' => (int) $payroll->items->sum('amount_snapshot'),
                'deduction_amount' => (int) $payroll->deductions()->sum('amount'),
                'total_amount' => (int) $payroll->items->sum('amount_snapshot') - (int) $payroll->deductions()->sum('amount'),
                'pending_activity_count' => $this->pendingActivityCount($payroll),
            ],
        ]);
    }

    public function approvePayroll(Request $request, HonorPayroll $payroll): JsonResponse
    {
        $response = DB::transaction(function () use ($request, $payroll): JsonResponse {
            $payroll = HonorPayroll::query()->lockForUpdate()->findOrFail($payroll->id);
            abort_unless($payroll->status === 'DRAFT', 409, 'Hanya periode draft yang dapat disahkan.');
            abort_if($payroll->items()->count() === 0, 422, 'Hitung aktivitas terverifikasi sebelum mengesahkan periode.');
            abort_if($this->pendingActivityCount($payroll) > 0, 422, 'Masih ada aktivitas dalam periode ini yang belum diverifikasi.');
            $this->assertDeductionsWithinGross($payroll);
            abort_if($payroll->period_end->isFuture(), 422, 'Periode honor belum berakhir dan belum dapat disahkan.');
            $sourceKeys = $payroll->items()->pluck('source_key');
            $alreadyLocked = HonorPayrollItem::query()
                ->whereIn('source_key', $sourceKeys)
                ->where('honor_payroll_id', '!=', $payroll->id)
                ->whereHas('payroll', fn ($query) => $query->whereIn('status', ['APPROVED', 'PAID']))
                ->exists();
            abort_if($alreadyLocked, 422, 'Salah satu aktivitas sudah disahkan atau dibayar pada periode lain.');

            $payroll->update([
                'status' => 'APPROVED',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return response()->json(['message' => 'Periode honor berhasil disahkan.', 'data' => $payroll->fresh()]);
        });
        $this->notifyPayrollRecipients($payroll, 'Honor Disahkan', 'Periode honor '.$payroll->period_key.' telah disahkan dan menunggu pembayaran.', 'info');

        return $response;
    }

    private function assertDeductionsWithinGross(HonorPayroll $payroll): void
    {
        $grossByRecipient = $payroll->items()
            ->get()
            ->groupBy(fn (HonorPayrollItem $item): string => $item->lecturer_id
                ? 'lecturer:'.$item->lecturer_id
                : 'staff:'.$item->staff_id)
            ->map(fn ($items): int => (int) $items->sum('amount_snapshot'));
        $deductionsByRecipient = $payroll->deductions()
            ->get()
            ->groupBy(fn (HonorPayrollDeduction $deduction): string => $deduction->lecturer_id
                ? 'lecturer:'.$deduction->lecturer_id
                : 'staff:'.$deduction->staff_id)
            ->map(fn ($deductions): int => (int) $deductions->sum('amount'));

        foreach ($deductionsByRecipient as $recipient => $amount) {
            abort_if(
                $amount > ($grossByRecipient[$recipient] ?? 0),
                422,
                'Jumlah potongan melebihi honor bruto penerima. Sesuaikan potongan sebelum mengesahkan periode.',
            );
        }
    }

    public function markPayrollPaid(Request $request, HonorPayroll $payroll): JsonResponse
    {
        $validated = $request->validate(['payment_reference' => 'nullable|string|max:100']);

        $response = DB::transaction(function () use ($request, $payroll, $validated): JsonResponse {
            $payroll = HonorPayroll::query()->lockForUpdate()->findOrFail($payroll->id);
            abort_unless($payroll->status === 'APPROVED', 409, 'Periode harus disahkan sebelum ditandai dibayar.');

            $payroll->update([
                'status' => 'PAID',
                'paid_by' => $request->user()->id,
                'paid_at' => now(),
                'payment_reference' => $validated['payment_reference'] ?? null,
            ]);

            return response()->json(['message' => 'Periode honor ditandai telah dibayar.', 'data' => $payroll->fresh()]);
        });
        $this->notifyPayrollRecipients($payroll, 'Honor Telah Dibayar', 'Periode honor '.$payroll->period_key.' telah dibayarkan dan slip dapat dilihat pada menu Honor Saya.', 'success');

        return $response;
    }

    private function notifyPayrollRecipients(HonorPayroll $payroll, string $title, string $message, string $type): void
    {
        $payroll->loadMissing(['items.lecturer', 'items.staff']);
        $userIds = $payroll->items
            ->map(fn (HonorPayrollItem $item) => $item->lecturer?->user_id ?? $item->staff?->user_id)
            ->filter()
            ->map(fn ($userId) => (int) $userId)
            ->unique();

        foreach ($userIds as $userId) {
            \App\Models\AppNotification::send($userId, $title, $message, $type, '/keuangan/honor-saya');
        }
    }

    public function myPayrolls(Request $request): JsonResponse
    {
        $recipient = $this->resolveOwnRecipient($request);
        $recipientId = $recipient['record']->id;
        $payrolls = HonorPayroll::query()
            ->where('status', 'PAID')
            ->whereHas('items', fn ($query) => $this->constrainRecipientItems($query, $recipient))
            ->with(['semester:id,name'])
            ->orderByDesc('paid_at')
            ->limit(24)
            ->get();

        $payrolls->each(function (HonorPayroll $payroll) use ($recipient): void {
            $items = $payroll->items()
                ->where(fn ($query) => $this->constrainRecipientItems($query, $recipient))
                ->orderBy('activity_date')
                ->get();
            $deductions = $payroll->deductions()
                ->where($recipient['type'] === 'lecturer' ? 'lecturer_id' : 'staff_id', $recipient['record']->id)
                ->orderBy('id')
                ->get();
            $payroll->setRelation('items', $items);
            $payroll->setRelation('deductions', $deductions);
            $gross = (int) $items->sum('amount_snapshot');
            $deducted = (int) $deductions->sum('amount');
            $payroll->setAttribute('gross_amount', $gross);
            $payroll->setAttribute('deduction_amount', $deducted);
            $payroll->setAttribute('total_amount', $gross - $deducted);
        });

        return response()->json(['data' => $payrolls]);
    }

    public function myPayrollPdf(Request $request, HonorPayroll $payroll)
    {
        $recipient = $this->resolveOwnRecipient($request);
        abort_unless($payroll->status === 'PAID', 404);

        $items = $payroll->items()
            ->where(fn ($query) => $this->constrainRecipientItems($query, $recipient))
            ->orderBy('activity_date')
            ->get();
        abort_if($items->isEmpty(), 404);
        $deductions = $payroll->deductions()
            ->where($recipient['type'] === 'lecturer' ? 'lecturer_id' : 'staff_id', $recipient['record']->id)
            ->orderBy('id')
            ->get();

        $record = $recipient['record'];
        $recipientId = (int) $record->id;
        $recipientType = $recipient['type'] === 'lecturer' ? 'lecturer' : 'staff';
        $recipientKey = $recipientType.':'.$recipientId;
        $paidAt = $payroll->paid_at ?? now();
        $financeSigner = Staff::query()
            ->where('status', true)
            ->whereRaw("LOWER(COALESCE(department, '')) LIKE ?", ['%keuangan%'])
            ->orderBy('id')
            ->first();
        $waket2 = LecturerPosition::query()
            ->with('lecturer')
            ->where('position_code', 'WK2')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $paidAt))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $paidAt))
            ->whereHas('lecturer', fn ($query) => $query->where('status', true))
            ->orderByDesc('id')
            ->first()?->lecturer;
        $financeSignerId = (int) ($financeSigner?->id ?? 0);
        $financeSignerName = $financeSigner?->name;
        $waket2SignerId = (int) ($waket2?->id ?? 0);
        $waket2SignerName = $waket2?->display_name;
        $verificationReference = HonorSlipVerification::reference(
            $payroll,
            $recipientType,
            $recipientId,
            $record instanceof Lecturer ? $record->display_name : $record->name,
            $record instanceof Lecturer ? $record->nidn : $record->nip,
            $items,
            $financeSignerId,
            $financeSignerName,
            $waket2SignerId,
            $waket2SignerName,
            $deductions,
        );
        $verificationToken = OperationalDocumentVerification::issue('honor-slip', $payroll->id, $verificationReference);
        $verifyUrl = rtrim((string) config('app.frontend_url'), '/').'/verify/honor-slip/'.$verificationToken;
        $signerContext = '&finance_signer_id='.$financeSignerId
            .'&finance_signer_name='.urlencode($financeSignerName ?? '')
            .'&waket2_signer_id='.$waket2SignerId
            .'&waket2_signer_name='.urlencode($waket2SignerName ?? '');
        $signatureBaseUrl = $verifyUrl.'?recipient='.urlencode($recipientKey).$signerContext.'&signer=';
        $publicVerifyUrl = $verifyUrl.'?recipient='.urlencode($recipientKey).$signerContext;
        $institution = Institution::first();
        $logoPath = $institution?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($institution->logo_path) : null;
        $letterheadPath = $institution?->letterhead_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($institution->letterhead_path) : null;

        $pdf = Pdf::loadView('pdf.lecturer-honor-slip', [
            'recipientName' => $record instanceof Lecturer ? $record->display_name : $record->name,
            'recipientIdentifierLabel' => $record instanceof Lecturer ? 'NIDN' : 'NIP',
            'recipientIdentifier' => $record instanceof Lecturer ? $record->nidn : $record->nip,
            'recipientType' => $recipient['type'],
            'payroll' => $payroll->load('semester'),
            'items' => $items,
            'deductions' => $deductions,
            'grossTotal' => (int) $items->sum('amount_snapshot'),
            'deductionTotal' => (int) $deductions->sum('amount'),
            'total' => (int) $items->sum('amount_snapshot') - (int) $deductions->sum('amount'),
            'slipNumber' => HonorSlipVerification::number($payroll, $recipientType, $recipientId),
            'recipientKey' => $recipientKey,
            'institution' => $institution,
            'logoPath' => $logoPath,
            'letterheadPath' => $letterheadPath,
            'verifyUrl' => $verifyUrl,
            'qrVerification' => QrCodeHelper::generateWithLogo($publicVerifyUrl, 160),
            'financeSignatureUrl' => QrCodeHelper::generate($signatureBaseUrl.'finance', 120),
            'waket2SignatureUrl' => QrCodeHelper::generate($signatureBaseUrl.'waket2', 120),
            'financeSignerName' => $financeSignerName,
            'financeSignerId' => $financeSignerId,
            'waket2SignerId' => $waket2SignerId,
            'waket2Name' => $waket2SignerName,
            'publicVerifyUrl' => $publicVerifyUrl,
            'printedAt' => now(),
        ])->setPaper('a4');

        return $pdf->download('slip-honor-'.$payroll->period_key.'.pdf');
    }

    private function assertRateConfiguration(array $rate): void
    {
        $expectedUnit = [
            'TEACHING' => 'PER_MEETING',
            'TEACHING_SKS' => 'PER_SKS',
            'STRUCTURAL' => 'MONTHLY',
            'ADVISOR' => 'PER_STUDENT',
            'INCIDENTAL' => 'PER_ACTIVITY',
            'STAFF_MONTHLY' => 'MONTHLY',
        ][$rate['category']];

        if ($rate['unit'] !== $expectedUnit) {
            throw ValidationException::withMessages(['unit' => ["Kategori {$rate['category']} harus menggunakan satuan {$expectedUnit}."]]);
        }
        if (in_array($rate['category'], ['TEACHING', 'TEACHING_SKS'], true) !== ! empty($rate['delivery_mode'])) {
            throw ValidationException::withMessages(['delivery_mode' => ['Tarif mengajar harus memiliki cara mengajar luring atau daring; kategori lain tidak mengisi cara mengajar.']]);
        }
        if ($rate['category'] === 'STRUCTURAL' && empty($rate['position_code'])) {
            throw ValidationException::withMessages(['position_code' => ['Tarif struktural harus terhubung ke kode jabatan.']]);
        }
        if (! in_array($rate['category'], ['STRUCTURAL', 'STAFF_MONTHLY'], true) && ! empty($rate['position_code'])) {
            throw ValidationException::withMessages(['position_code' => ['Kategori honor ini tidak menggunakan jabatan.']]);
        }
    }

    private function assertNoOverlappingRate(array $rate, ?int $exceptId = null): void
    {
        $isTeaching = in_array($rate['category'], ['TEACHING', 'TEACHING_SKS'], true);
        $query = HonorRate::query()
            ->when(
                $isTeaching,
                fn ($query) => $query->whereIn('category', ['TEACHING', 'TEACHING_SKS']),
                fn ($query) => $query->where('category', $rate['category'])->where('unit', $rate['unit']),
            )
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->when(
                $isTeaching,
                fn ($query) => $query->where('delivery_mode', $rate['delivery_mode']),
            )
            ->when(
                in_array($rate['category'], ['STRUCTURAL', 'STAFF_MONTHLY'], true),
                fn ($query) => $query->where('position_code', $rate['position_code']),
            )
            ->when(
                $rate['category'] === 'INCIDENTAL',
                fn ($query) => $query->where('name', $rate['name']),
            )
            ->whereDate('effective_from', '<=', $rate['effective_until'] ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $rate['effective_from']));

        if ($query->exists()) {
            throw ValidationException::withMessages(['effective_from' => ['Rentang masa berlaku bertumpang tindih dengan tarif aktif sejenis.']]);
        }
    }

    private function assertRateEffective(HonorRate $rate, string $date): void
    {
        abort_unless(
            $rate->effective_from->toDateString() <= $date
                && (! $rate->effective_until || $rate->effective_until->toDateString() >= $date),
            422,
            'Tarif tidak berlaku pada tanggal aktivitas.',
        );
    }

    private function assertActivityPeriodIsOpen(string $date, string $category): void
    {
        $isLocked = HonorPayroll::query()
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->where(function ($query) use ($category): void {
                if ($category === 'ADVISOR') {
                    $query->where('period_type', 'SEMESTER');
                } elseif ($category === 'INCIDENTAL') {
                    $query->whereIn('period_type', ['INCIDENTAL', 'MONTHLY']);
                } else {
                    $query->where('period_type', 'MONTHLY');
                }
            })
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();

        abort_if($isLocked, 409, 'Periode aktivitas sudah disahkan atau dibayar; gunakan mekanisme penyesuaian pada periode berikutnya.');
    }

    private function pendingActivityCount(HonorPayroll $payroll): int
    {
        return HonorActivity::query()
            ->whereBetween('activity_date', [$payroll->period_start, $payroll->period_end])
            ->where('status', 'PENDING')
            ->when(
                $payroll->period_type === 'SEMESTER',
                fn ($query) => $query->where('semester_id', $payroll->semester_id)
                    ->whereHas('rate', fn ($rateQuery) => $rateQuery->where('category', 'ADVISOR')),
                fn ($query) => $query->whereHas('rate', fn ($rateQuery) => $payroll->period_type === 'INCIDENTAL'
                    ? $rateQuery->where('category', 'INCIDENTAL')
                    : $rateQuery->whereIn('category', ['TEACHING', 'TEACHING_SKS', 'STRUCTURAL'])),
            )
            ->count();
    }

    private function resolveOwnRecipient(Request $request): array
    {
        $user = $request->user();
        if ($user->lecturer instanceof Lecturer) {
            return ['type' => 'lecturer', 'record' => $user->lecturer];
        }
        if ($user->staff instanceof Staff) {
            return ['type' => 'staff', 'record' => $user->staff];
        }

        abort(404, 'Profil dosen atau tenaga kependidikan tidak ditemukan.');
    }

    private function constrainRecipientItems(Builder $query, array $recipient): void
    {
        $column = $recipient['type'] === 'lecturer' ? 'lecturer_id' : 'staff_id';
        $query->where($column, $recipient['record']->id);
    }
}
