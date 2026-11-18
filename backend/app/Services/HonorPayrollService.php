<?php

namespace App\Services;

use App\Models\HonorActivity;
use App\Models\HonorPayroll;
use App\Models\HonorPayrollItem;
use App\Models\HonorRate;
use App\Models\LectureJournal;
use App\Models\LecturerPosition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HonorPayrollService
{
    public function prepare(HonorPayroll $payroll): HonorPayroll
    {
        if ($payroll->status !== 'DRAFT') {
            abort(409, 'Periode honor sudah disahkan dan tidak dapat dihitung ulang.');
        }

        return DB::transaction(function () use ($payroll): HonorPayroll {
            $staffItems = collect();
            if ($payroll->period_type === 'MONTHLY') {
                $this->collectTeachingActivities($payroll);
                $this->collectStructuralActivities($payroll);
                $staffItems = $this->collectStaffItems($payroll);
            } elseif ($payroll->period_type === 'SEMESTER') {
                $this->collectAdvisorActivities($payroll);
            }

            $activities = HonorActivity::query()
                ->whereBetween('activity_date', [$payroll->period_start, $payroll->period_end])
                ->where('status', 'VERIFIED')
                ->when(
                    $payroll->period_type === 'MONTHLY',
                    fn ($query) => $query->where(fn ($sourceQuery) => $sourceQuery
                        ->where('source_type', '!=', 'LECTURE_JOURNAL')
                        ->orWhereIn('source_id', LectureJournal::query()
                            ->where('review_status', 'APPROVED')
                            ->select('id'))),
                )
                ->when(
                    $payroll->period_type === 'SEMESTER',
                    fn ($query) => $query->where('semester_id', $payroll->semester_id)
                        ->whereHas('rate', fn ($rateQuery) => $rateQuery->where('category', 'ADVISOR')),
                    fn ($query) => $query->when(
                        $payroll->period_type === 'INCIDENTAL',
                        fn ($incidentalQuery) => $incidentalQuery->whereHas('rate', fn ($rateQuery) => $rateQuery->where('category', 'INCIDENTAL')),
                        fn ($monthlyQuery) => $monthlyQuery->whereHas('rate', fn ($rateQuery) => $rateQuery
                            ->whereIn('category', ['TEACHING', 'TEACHING_SKS', 'STRUCTURAL'])),
                    ),
                )
                ->get();

            $this->assertNotPreviouslyPaid($activities, $payroll);
            $this->assertNoOtherDraftContains($activities, $payroll);

            $payroll->items()->delete();
            foreach ($activities as $activity) {
                $payroll->items()->create([
                    'lecturer_id' => $activity->lecturer_id,
                    'staff_id' => $activity->staff_id,
                    'honor_rate_id' => $activity->honor_rate_id,
                    'source_key' => $activity->source_key,
                    'description' => $activity->description,
                    'activity_date' => $activity->activity_date,
                    'quantity' => $activity->quantity,
                    'unit_rate_snapshot' => $activity->unit_rate_snapshot,
                    'amount_snapshot' => $activity->amount_snapshot,
                ]);
            }
            foreach ($staffItems as $item) {
                $payroll->items()->create($item);
            }

            $payroll->deductions()
                ->whereNotNull('lecturer_id')
                ->whereNotIn('lecturer_id', $payroll->items()->whereNotNull('lecturer_id')->select('lecturer_id'))
                ->delete();
            $payroll->deductions()
                ->whereNotNull('staff_id')
                ->whereNotIn('staff_id', $payroll->items()->whereNotNull('staff_id')->select('staff_id'))
                ->delete();

            return $payroll->fresh(['items.lecturer.user', 'items.staff.user', 'items.rate', 'semester']);
        });
    }

    private function collectStaffItems(HonorPayroll $payroll): Collection
    {
        $staffMembers = DB::table('staff')
            ->where('status', true)
            ->orderBy('id')
            ->get(['id', 'name', 'nip', 'position']);

        $rates = HonorRate::query()
            ->where('category', 'STAFF_MONTHLY')
            ->where('unit', 'MONTHLY')
            ->whereDate('effective_from', '<=', $payroll->period_end)
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $payroll->period_start))
            ->get();
        $items = collect();

        foreach ($staffMembers as $staff) {
            $position = trim((string) $staff->position);
            $matchingRates = $rates->filter(fn (HonorRate $rate): bool => $rate->position_code === null
                || ($position !== '' && mb_strtolower($rate->position_code) === mb_strtolower($position)));
            $specificRates = $matchingRates->filter(fn (HonorRate $rate): bool => $rate->position_code !== null);
            $applicableRates = $specificRates->isNotEmpty() ? $specificRates : $matchingRates
                ->filter(fn (HonorRate $rate): bool => $rate->position_code === null);

            if ($applicableRates->count() !== 1) {
                $reason = $applicableRates->isEmpty() ? 'belum memiliki tarif honor bulanan yang berlaku' : 'memiliki lebih dari satu tarif honor bulanan yang berlaku';
                throw ValidationException::withMessages([
                    'rates' => ["Tenaga kependidikan {$staff->name} {$reason}. Periksa tarif umum atau tarif khusus posisinya."],
                ]);
            }

            $rate = $applicableRates->first();
            $activityDate = max(array_filter([
                $payroll->period_start->toDateString(),
                $rate->effective_from->toDateString(),
            ]));
            $items->push([
                'lecturer_id' => null,
                'staff_id' => $staff->id,
                'honor_rate_id' => $rate->id,
                'source_key' => "staff:{$staff->id}:rate:{$rate->id}:period:{$payroll->period_key}",
                'description' => 'Honor bulanan tenaga kependidikan'.($position !== '' ? " — {$position}" : ''),
                'activity_date' => $activityDate,
                'quantity' => 1,
                'unit_rate_snapshot' => $rate->amount,
                'amount_snapshot' => $rate->amount,
            ]);
        }

        return $items;
    }

    private function collectTeachingActivities(HonorPayroll $payroll): void
    {
        $journals = LectureJournal::query()
            ->with('class_.course')
            ->where('status', 'COMPLETED')
            ->where('review_status', 'APPROVED')
            ->whereBetween('meeting_date', [$payroll->period_start, $payroll->period_end])
            ->whereNotNull('lecturer_id')
            ->get();

        foreach ($journals as $journal) {
            // Jurnal lama tanpa cara mengajar dihitung luring.
            $rate = $this->singleEffectiveRate(
                ['TEACHING', 'TEACHING_SKS'],
                $journal->meeting_date->toDateString(),
                ['delivery_mode' => $journal->delivery_mode ?: 'ONSITE'],
            );

            $quantity = 1;
            $sksNote = '';
            if ($rate->category === 'TEACHING_SKS') {
                $credits = (float) ($journal->class_?->course?->credits ?? 0);
                if ($credits <= 0) {
                    throw ValidationException::withMessages([
                        'activities' => ["Mata kuliah pada jurnal pertemuan {$journal->meeting_number} (kelas ID {$journal->class_id}) belum memiliki SKS. Lengkapi data mata kuliah sebelum menghitung honor per SKS."],
                    ]);
                }
                $quantity = $credits;
                $sksNote = " ({$credits} SKS)";
            }

            $activity = $this->recordActivity(
                $rate,
                (int) $journal->lecturer_id,
                "lecture-journal:{$journal->id}:rate:{$rate->id}",
                'LECTURE_JOURNAL',
                (int) $journal->id,
                "Mengajar pertemuan {$journal->meeting_number} — {$journal->topic}{$sksNote}",
                $journal->meeting_date->toDateString(),
                $quantity,
            );

            if ($activity->status !== 'VERIFIED') {
                $activity->update([
                    'status' => 'VERIFIED',
                    'verified_by' => $journal->reviewed_by,
                    'verified_at' => $journal->reviewed_at ?? now(),
                    'verification_note' => 'Jurnal perkuliahan telah disetujui.',
                ]);
            }
        }
    }

    private function collectStructuralActivities(HonorPayroll $payroll): void
    {
        $rates = HonorRate::query()
            ->where('category', 'STRUCTURAL')
            ->where('unit', 'MONTHLY')
            ->whereDate('effective_from', '<=', $payroll->period_end)
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $payroll->period_start))
            ->get();

        foreach ($rates as $rate) {
            $positions = LecturerPosition::query()
                ->where('position_code', $rate->position_code)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', $payroll->period_end))
                ->where(fn ($query) => $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $payroll->period_start))
                ->get();

            foreach ($positions as $position) {
                $date = max(array_filter([
                    $payroll->period_start->toDateString(),
                    $rate->effective_from->toDateString(),
                    $position->start_date?->toDateString(),
                ]));
                $this->recordActivity(
                    $rate,
                    (int) $position->lecturer_id,
                    "structural-position:{$position->id}:rate:{$rate->id}:period:{$payroll->period_key}",
                    'STRUCTURAL_POSITION',
                    (int) $position->id,
                    'Honor jabatan struktural: '.$position->position_name,
                    $date,
                    1,
                );
            }
        }
    }

    private function collectAdvisorActivities(HonorPayroll $payroll): void
    {
        $rates = HonorRate::query()
            ->where('category', 'ADVISOR')
            ->where('unit', 'PER_STUDENT')
            ->whereDate('effective_from', '<=', $payroll->period_end)
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $payroll->period_start))
            ->get();

        $students = DB::table('krs')
            ->join('students', 'students.id', '=', 'krs.student_id')
            ->where('krs.semester_id', $payroll->semester_id)
            ->whereRaw('UPPER(krs.status) = ?', ['APPROVED'])
            ->where('students.status', 'Aktif')
            ->whereNotNull('krs.advisor_id')
            ->orderByDesc('krs.id')
            ->get(['krs.student_id as id', 'krs.advisor_id', 'students.nim'])
            ->unique('id');

        foreach ($students as $student) {
            foreach ($rates as $rate) {
                $this->recordActivity(
                    $rate,
                    (int) $student->advisor_id,
                    "advisor-student:{$student->id}:semester:{$payroll->semester_id}:rate:{$rate->id}",
                    'ADVISOR_STUDENT',
                    (int) $student->id,
                    "Honor dosen pembimbing akademik — NIM {$student->nim}",
                    $payroll->period_end->toDateString(),
                    1,
                    $payroll->semester_id,
                );
            }
        }
    }

    private function singleEffectiveRate(string|array $category, string $date, array $filters = []): HonorRate
    {
        $categories = (array) $category;
        $rates = HonorRate::query()
            ->whereIn('category', $categories)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->where($filters)
            ->get();

        if ($rates->count() !== 1) {
            $reason = $rates->isEmpty() ? 'belum memiliki tarif aktif' : 'memiliki lebih dari satu tarif aktif';
            throw ValidationException::withMessages([
                'rates' => ['Honor '.implode('/', $categories)." untuk {$date} {$reason}. Periksa konfigurasi tarif sebelum menghitung."],
            ]);
        }

        return $rates->first();
    }

    private function recordActivity(
        HonorRate $rate,
        int $lecturerId,
        string $sourceKey,
        string $sourceType,
        int $sourceId,
        string $description,
        string $activityDate,
        int|float $quantity,
        ?int $semesterId = null,
    ): HonorActivity {
        return HonorActivity::query()->firstOrCreate(
            ['source_key' => $sourceKey],
            [
                'lecturer_id' => $lecturerId,
                'honor_rate_id' => $rate->id,
                'semester_id' => $semesterId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'activity_date' => $activityDate,
                'quantity' => $quantity,
                'unit_rate_snapshot' => $rate->amount,
                'amount_snapshot' => (int) round((float) $rate->amount * $quantity),
                'status' => 'PENDING',
                'created_by' => auth()->id(),
            ],
        );
    }

    private function assertNotPreviouslyPaid(Collection $activities, HonorPayroll $payroll): void
    {
        $sourceKeys = $activities->pluck('source_key')->all();
        if ($sourceKeys === []) {
            return;
        }

        $alreadyPaid = HonorPayrollItem::query()
            ->whereIn('source_key', $sourceKeys)
            ->whereHas('payroll', fn ($query) => $query
                ->where('id', '!=', $payroll->id)
                ->whereIn('status', ['APPROVED', 'PAID']))
            ->exists();

        if ($alreadyPaid) {
            throw ValidationException::withMessages([
                'activities' => ['Salah satu aktivitas sudah masuk ke periode honor yang disahkan atau dibayar. Aktivitas tidak dapat dibayar dua kali.'],
            ]);
        }
    }

    private function assertNoOtherDraftContains(Collection $activities, HonorPayroll $payroll): void
    {
        $sourceKeys = $activities->pluck('source_key')->all();
        if ($sourceKeys === []) {
            return;
        }

        $alreadyInDraft = HonorPayrollItem::query()
            ->whereIn('source_key', $sourceKeys)
            ->where('honor_payroll_id', '!=', $payroll->id)
            ->whereHas('payroll', fn ($query) => $query->where('status', 'DRAFT'))
            ->exists();

        if ($alreadyInDraft) {
            throw ValidationException::withMessages([
                'activities' => ['Salah satu aktivitas masih tercatat pada draft periode lain. Hitung ulang atau hapus draft lama sebelum menyiapkan periode ini.'],
            ]);
        }
    }
}
