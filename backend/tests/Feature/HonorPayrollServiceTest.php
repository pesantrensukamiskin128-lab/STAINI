<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HonorController;
use App\Http\Controllers\Api\VerifyController;
use App\Models\HonorActivity;
use App\Models\HonorPayroll;
use App\Models\HonorPayrollDeduction;
use App\Models\HonorPayrollItem;
use App\Models\HonorRate;
use App\Models\User;
use App\Services\HonorPayrollService;
use App\Support\HonorSlipVerification;
use App\Support\OperationalDocumentVerification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HonorPayrollServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'honor_payroll_deductions', 'honor_payroll_items', 'honor_payrolls', 'honor_activities', 'honor_rates',
            'lecture_journals', 'lecturer_positions', 'krs', 'students', 'semesters', 'staff', 'lecturers', 'users', 'notifications',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('type')->default('info');
            $table->string('link')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('nidn')->nullable();
            $table->timestamps();
        });
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('nip')->nullable();
            $table->string('position')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('nim')->nullable();
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->string('status')->default('Aktif');
        });
        Schema::create('krs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->string('status')->default('DRAFT');
        });
        Schema::create('lecturer_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lecturer_id');
            $table->string('position_code');
            $table->string('position_name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('lecture_journals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedInteger('meeting_number');
            $table->date('meeting_date');
            $table->string('topic');
            $table->string('status');
            $table->string('review_status')->default('NOT_SUBMITTED');
            $table->string('delivery_mode', 10)->nullable();
            $table->timestamps();
        });
        Schema::create('honor_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category', 20);
            $table->string('unit', 20);
            $table->string('position_code', 100)->nullable();
            $table->string('delivery_mode', 10)->nullable();
            $table->decimal('amount', 14, 0);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('honor_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lecturer_id');
            $table->unsignedBigInteger('honor_rate_id');
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->string('source_key')->unique();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('description');
            $table->date('activity_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_rate_snapshot', 14, 0);
            $table->decimal('amount_snapshot', 14, 0);
            $table->string('status', 15)->default('PENDING');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
        });
        Schema::create('honor_payrolls', function (Blueprint $table): void {
            $table->id();
            $table->string('period_key')->unique();
            $table->string('period_type', 10);
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 15)->default('DRAFT');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });
        Schema::create('honor_payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('honor_payroll_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('honor_rate_id')->nullable();
            $table->string('source_key');
            $table->string('description');
            $table->date('activity_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_rate_snapshot', 14, 0);
            $table->decimal('amount_snapshot', 14, 0);
            $table->timestamps();
            $table->unique(['honor_payroll_id', 'source_key']);
        });
        Schema::create('honor_payroll_deductions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('honor_payroll_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('deduction_type', 10);
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            ['id' => 11, 'name' => 'Dosen Satu'],
            ['id' => 12, 'name' => 'Dosen Dua'],
        ]);
        DB::table('lecturers')->insert([
            ['id' => 1, 'user_id' => 11, 'full_name' => 'Dosen Satu'],
            ['id' => 2, 'user_id' => 12, 'full_name' => 'Dosen Dua'],
        ]);
        DB::table('staff')->insert([
            ['id' => 1, 'user_id' => null, 'name' => 'Admin Umum', 'nip' => 'T001', 'position' => 'Administrasi Umum', 'status' => true],
            ['id' => 2, 'user_id' => null, 'name' => 'Petugas Nonaktif', 'nip' => 'T002', 'position' => 'Arsip', 'status' => false],
        ]);
    }

    public function test_completed_meetings_use_delivery_specific_rates_and_must_be_verified(): void
    {
        HonorRate::create([
            'name' => 'Honor tenaga kependidikan',
            'category' => 'STAFF_MONTHLY',
            'unit' => 'MONTHLY',
            'amount' => 1800000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        HonorRate::create([
            'name' => 'Mengajar luring',
            'category' => 'TEACHING',
            'unit' => 'PER_MEETING',
            'delivery_mode' => 'ONSITE',
            'amount' => 100000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        HonorRate::create([
            'name' => 'Mengajar daring',
            'category' => 'TEACHING',
            'unit' => 'PER_MEETING',
            'delivery_mode' => 'ONLINE',
            'amount' => 80000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        DB::table('lecture_journals')->insert([
            ['id' => 1, 'lecturer_id' => 1, 'meeting_number' => 1, 'meeting_date' => '2026-10-05', 'topic' => 'Kelas luring', 'status' => 'COMPLETED', 'review_status' => 'APPROVED', 'delivery_mode' => 'ONSITE'],
            ['id' => 2, 'lecturer_id' => 1, 'meeting_number' => 2, 'meeting_date' => '2026-10-12', 'topic' => 'Kelas hybrid, dosen online', 'status' => 'COMPLETED', 'review_status' => 'APPROVED', 'delivery_mode' => 'ONLINE'],
            ['id' => 3, 'lecturer_id' => 1, 'meeting_number' => 3, 'meeting_date' => '2026-10-19', 'topic' => 'Belum disetujui', 'status' => 'COMPLETED', 'review_status' => 'PENDING', 'delivery_mode' => 'ONSITE'],
        ]);
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');

        $prepared = app(HonorPayrollService::class)->prepare($payroll);
        $this->assertCount(3, $prepared->items);
        $this->assertSame(1, $prepared->items->whereNotNull('staff_id')->count());
        $this->assertSame(2, HonorActivity::query()->where('status', 'VERIFIED')->count());
        $this->assertSame(0, HonorActivity::query()->where('status', 'PENDING')->count());
        $this->assertDatabaseMissing('honor_activities', [
            'source_key' => 'lecture-journal:3:rate:'.$this->rateId('ONSITE'),
        ]);

        DB::table('honor_activities')->insert([
            'lecturer_id' => 1,
            'honor_rate_id' => $this->rateId('ONSITE'),
            'source_key' => 'lecture-journal:3:rate:'.$this->rateId('ONSITE'),
            'source_type' => 'LECTURE_JOURNAL',
            'source_id' => 3,
            'description' => 'Belum disetujui',
            'activity_date' => '2026-10-19',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
            'status' => 'VERIFIED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $prepared = app(HonorPayrollService::class)->prepare($payroll);

        $this->assertCount(3, $prepared->items);
        $this->assertSame(1980000, (int) $prepared->items->sum('amount_snapshot'));
        $this->assertSame(
            100000,
            (int) $prepared->items->first(fn ($item) => str_starts_with($item->source_key, 'lecture-journal:1:'))->amount_snapshot,
        );
        $this->assertSame(
            80000,
            (int) $prepared->items->first(fn ($item) => str_starts_with($item->source_key, 'lecture-journal:2:'))->amount_snapshot,
        );

        $payroll->update(['status' => 'PAID']);
        $duplicatePayroll = $this->monthlyPayroll('DUPLICATE-2026-10');
        $this->expectException(ValidationException::class);
        app(HonorPayrollService::class)->prepare($duplicatePayroll);
    }

    public function test_completed_meeting_without_delivery_mode_cannot_be_calculated(): void
    {
        DB::table('lecture_journals')->insert([
            'id' => 1,
            'lecturer_id' => 1,
            'meeting_number' => 1,
            'meeting_date' => '2026-10-05',
            'topic' => 'Jurnal lama',
            'status' => 'COMPLETED',
            'review_status' => 'APPROVED',
            'delivery_mode' => null,
        ]);

        $this->expectException(ValidationException::class);
        app(HonorPayrollService::class)->prepare($this->monthlyPayroll('MONTHLY-2026-10'));
    }

    private function rateId(string $deliveryMode): int
    {
        return (int) HonorRate::query()->where('delivery_mode', $deliveryMode)->value('id');
    }

    public function test_staff_receive_full_monthly_rate_and_inactive_staff_are_excluded(): void
    {
        HonorRate::create([
            'name' => 'Honor tenaga kependidikan',
            'category' => 'STAFF_MONTHLY',
            'unit' => 'MONTHLY',
            'amount' => 1800000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);

        $prepared = app(HonorPayrollService::class)->prepare($this->monthlyPayroll('MONTHLY-2026-10'));

        $this->assertCount(1, $prepared->items);
        $this->assertNull($prepared->items[0]->lecturer_id);
        $this->assertSame(1, (int) $prepared->items[0]->staff_id);
        $this->assertSame(1800000, (int) $prepared->items[0]->amount_snapshot);
    }

    public function test_staff_position_rate_overrides_the_general_monthly_rate(): void
    {
        HonorRate::create([
            'name' => 'Honor umum',
            'category' => 'STAFF_MONTHLY',
            'unit' => 'MONTHLY',
            'amount' => 1500000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        HonorRate::create([
            'name' => 'Honor administrasi umum',
            'category' => 'STAFF_MONTHLY',
            'unit' => 'MONTHLY',
            'position_code' => 'Administrasi Umum',
            'amount' => 1900000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);

        $prepared = app(HonorPayrollService::class)->prepare($this->monthlyPayroll('MONTHLY-2026-10'));

        $this->assertCount(1, $prepared->items);
        $this->assertSame(1900000, (int) $prepared->items[0]->amount_snapshot);
    }

    public function test_structural_honor_is_generated_for_a_part_month_position(): void
    {
        HonorRate::create([
            'name' => 'Honor tenaga kependidikan',
            'category' => 'STAFF_MONTHLY',
            'unit' => 'MONTHLY',
            'amount' => 1800000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        HonorRate::create([
            'name' => 'Honor Kaprodi',
            'category' => 'STRUCTURAL',
            'unit' => 'MONTHLY',
            'position_code' => 'KAPRODI',
            'amount' => 750000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        DB::table('lecturer_positions')->insert([
            'lecturer_id' => 1,
            'position_code' => 'KAPRODI',
            'position_name' => 'Ketua Program Studi',
            'start_date' => '2026-10-15',
            'is_active' => true,
        ]);

        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        app(HonorPayrollService::class)->prepare($payroll);
        $activity = HonorActivity::query()->sole();
        $this->assertSame(750000, (int) $activity->amount_snapshot);
        $this->assertSame('2026-10-15', $activity->activity_date->toDateString());
        $activity->update(['status' => 'VERIFIED', 'verified_at' => now()]);
        $prepared = app(HonorPayrollService::class)->prepare($payroll);

        $lecturerItem = $prepared->items->firstWhere('lecturer_id', 1);
        $this->assertNotNull($lecturerItem);
        $this->assertSame(750000, (int) $lecturerItem->amount_snapshot);
        $this->assertSame('2026-10-15', $lecturerItem->activity_date->toDateString());
    }

    public function test_semester_advisor_honor_counts_only_active_advisees(): void
    {
        DB::table('semesters')->insert([
            'id' => 1,
            'name' => 'Semester Ganjil 2026/2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-01-31',
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'nim' => 'S001', 'advisor_id' => 1, 'status' => 'Aktif'],
            ['id' => 2, 'nim' => 'S002', 'advisor_id' => 1, 'status' => 'Aktif'],
            ['id' => 3, 'nim' => 'S003', 'advisor_id' => 1, 'status' => 'Cuti'],
            ['id' => 4, 'nim' => 'S004', 'advisor_id' => 2, 'status' => 'Aktif'],
        ]);
        DB::table('krs')->insert([
            ['student_id' => 1, 'semester_id' => 1, 'advisor_id' => 1, 'status' => 'Approved'],
            ['student_id' => 2, 'semester_id' => 1, 'advisor_id' => 1, 'status' => 'Approved'],
            ['student_id' => 3, 'semester_id' => 1, 'advisor_id' => 1, 'status' => 'Approved'],
            ['student_id' => 4, 'semester_id' => 1, 'advisor_id' => 2, 'status' => 'Approved'],
        ]);
        HonorRate::create([
            'name' => 'Pembimbing akademik',
            'category' => 'ADVISOR',
            'unit' => 'PER_STUDENT',
            'amount' => 50000,
            'effective_from' => '2026-08-01',
            'is_active' => true,
        ]);
        $payroll = HonorPayroll::create([
            'period_key' => 'SEMESTER-1',
            'period_type' => 'SEMESTER',
            'semester_id' => 1,
            'period_start' => '2026-08-01',
            'period_end' => '2027-01-31',
            'status' => 'DRAFT',
        ]);

        app(HonorPayrollService::class)->prepare($payroll);
        $this->assertCount(3, HonorActivity::query()->where('status', 'PENDING')->get());
        HonorActivity::query()->update(['status' => 'VERIFIED', 'verified_at' => now()]);
        $prepared = app(HonorPayrollService::class)->prepare($payroll);

        $this->assertCount(3, $prepared->items);
        $this->assertSame(150000, (int) $prepared->items->sum('amount_snapshot'));
        $this->assertSame([1, 2], $prepared->items->pluck('lecturer_id')->unique()->sort()->values()->all());
    }

    public function test_rate_configuration_prevents_overlapping_rates_for_same_teaching_mode(): void
    {
        $admin = User::findOrFail(11);
        $requestData = [
            'name' => 'Honor mengajar luring',
            'category' => 'TEACHING',
            'unit' => 'PER_MEETING',
            'delivery_mode' => 'ONSITE',
            'amount' => 100000,
            'effective_from' => '2026-10-01',
        ];
        $request = Request::create('/api/finance/honor/rates', 'POST', $requestData);
        $request->setUserResolver(fn () => $admin);

        $response = app(HonorController::class)->storeRate($request);

        $this->assertSame('ONSITE', $response->getData(true)['data']['delivery_mode']);
        $overlap = Request::create('/api/finance/honor/rates', 'POST', $requestData);
        $overlap->setUserResolver(fn () => $admin);
        try {
            app(HonorController::class)->storeRate($overlap);
            $this->fail('The overlapping rate should not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }

        $online = Request::create('/api/finance/honor/rates', 'POST', [
            ...$requestData,
            'name' => 'Honor mengajar daring',
            'delivery_mode' => 'ONLINE',
        ]);
        $online->setUserResolver(fn () => $admin);
        $this->assertSame('ONLINE', app(HonorController::class)->storeRate($online)->getData(true)['data']['delivery_mode']);
    }

    public function test_incidental_activity_requires_unique_reference_and_finance_verification(): void
    {
        $admin = User::findOrFail(11);
        $rate = HonorRate::create([
            'name' => 'Honor penguji',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'amount' => 250000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        $requestData = [
            'lecturer_id' => 1,
            'honor_rate_id' => $rate->id,
            'reference_number' => 'SK-UJIAN-001',
            'description' => 'Penguji skripsi',
            'activity_date' => '2026-10-05',
            'quantity' => 2,
        ];
        $request = Request::create('/api/finance/honor/activities', 'POST', $requestData);
        $request->setUserResolver(fn () => $admin);
        $response = app(HonorController::class)->storeActivity($request);
        $activity = $response->getData(true)['data'];

        $this->assertSame(500000, (int) $activity['amount_snapshot']);
        $this->assertSame('PENDING', $activity['status']);

        $duplicate = Request::create('/api/finance/honor/activities', 'POST', $requestData);
        $duplicate->setUserResolver(fn () => $admin);
        try {
            app(HonorController::class)->storeActivity($duplicate);
            $this->fail('Duplicate activity reference should not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference_number', $exception->errors());
        }

        $verify = Request::create('/api/finance/honor/activities/1/verify', 'POST', [
            'status' => 'VERIFIED',
            'verification_note' => 'Surat tugas telah diperiksa.',
        ]);
        $verify->setUserResolver(fn () => $admin);
        $verified = app(HonorController::class)->verifyActivity($verify, HonorActivity::findOrFail($activity['id']))->getData(true)['data'];

        $this->assertSame('VERIFIED', $verified['status']);
        $this->assertSame(11, $verified['verified_by']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => 11,
            'title' => 'Aktivitas Honor Disetujui',
            'link' => '/keuangan/honor-saya',
        ]);
    }

    public function test_incidental_daily_payroll_contains_only_verified_activities_for_its_date(): void
    {
        $admin = User::findOrFail(11);
        $rate = HonorRate::create([
            'name' => 'Honor penguji',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'amount' => 250000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        foreach ([
            ['key' => 'manual:daily-one', 'date' => '2026-10-05', 'status' => 'VERIFIED'],
            ['key' => 'manual:daily-two', 'date' => '2026-10-05', 'status' => 'PENDING'],
            ['key' => 'manual:other-day', 'date' => '2026-10-06', 'status' => 'VERIFIED'],
        ] as $activityData) {
            HonorActivity::create([
                'lecturer_id' => 1,
                'honor_rate_id' => $rate->id,
                'source_key' => $activityData['key'],
                'source_type' => 'INCIDENTAL',
                'reference_number' => $activityData['key'],
                'description' => 'Honor kegiatan',
                'activity_date' => $activityData['date'],
                'quantity' => 1,
                'unit_rate_snapshot' => $rate->amount,
                'amount_snapshot' => $rate->amount,
                'status' => $activityData['status'],
            ]);
        }

        $request = Request::create('/api/finance/honor/payrolls', 'POST', [
            'period_type' => 'INCIDENTAL',
            'activity_date' => '2026-10-05',
        ]);
        $request->setUserResolver(fn () => $admin);
        $created = app(HonorController::class)->storePayroll($request)->getData(true)['data'];
        $this->assertSame('INCIDENTAL-2026-10-05', $created['period_key']);
        $this->assertSame('2026-10-05', HonorPayroll::findOrFail($created['id'])->period_start->toDateString());
        $this->assertSame(
            HonorPayroll::findOrFail($created['id'])->period_start->toDateString(),
            HonorPayroll::findOrFail($created['id'])->period_end->toDateString(),
        );

        $daily = app(HonorPayrollService::class)->prepare(HonorPayroll::findOrFail($created['id']));
        $this->assertCount(1, $daily->items);
        $this->assertSame('manual:daily-one', $daily->items->sole()->source_key);

        HonorRate::create([
            'name' => 'Honor tenaga kependidikan',
            'category' => 'STAFF_MONTHLY',
            'unit' => 'MONTHLY',
            'amount' => 1800000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        $monthly = app(HonorPayrollService::class)->prepare($this->monthlyPayroll('MONTHLY-2026-10'));
        $this->assertFalse($monthly->items->contains(fn (HonorPayrollItem $item) => str_starts_with($item->source_key, 'manual:')));
    }

    public function test_incidental_daily_payroll_rejects_activities_already_present_in_another_draft(): void
    {
        $rate = HonorRate::create([
            'name' => 'Honor panitia',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'amount' => 100000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        $activity = HonorActivity::create([
            'lecturer_id' => 1,
            'honor_rate_id' => $rate->id,
            'source_key' => 'manual:legacy-monthly-draft',
            'source_type' => 'INCIDENTAL',
            'reference_number' => 'LEGACY-MONTHLY',
            'description' => 'Panitia kegiatan akademik',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
            'status' => 'VERIFIED',
        ]);
        $daily = HonorPayroll::create([
            'period_key' => 'INCIDENTAL-2026-10-05',
            'period_type' => 'INCIDENTAL',
            'period_start' => '2026-10-05',
            'period_end' => '2026-10-05',
            'status' => 'DRAFT',
        ]);
        $monthly = $this->monthlyPayroll('MONTHLY-2026-10');
        HonorPayrollItem::create([
            'honor_payroll_id' => $monthly->id,
            'lecturer_id' => $activity->lecturer_id,
            'honor_rate_id' => $rate->id,
            'source_key' => $activity->source_key,
            'description' => $activity->description,
            'activity_date' => $activity->activity_date,
            'quantity' => $activity->quantity,
            'unit_rate_snapshot' => $activity->unit_rate_snapshot,
            'amount_snapshot' => $activity->amount_snapshot,
        ]);

        try {
            app(HonorPayrollService::class)->prepare($daily);
            $this->fail('An activity already in another draft must not be added to a second payroll.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Hitung ulang atau hapus draft lama', $exception->errors()['activities'][0]);
        }
    }

    public function test_lecturer_monthly_history_only_returns_their_own_paid_items(): void
    {
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        $payroll->update(['status' => 'PAID', 'paid_at' => now()]);
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 1,
            'source_key' => 'private-one',
            'description' => 'Honor dosen satu',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
        ]);
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 2,
            'source_key' => 'private-two',
            'description' => 'Honor dosen dua',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 200000,
            'amount_snapshot' => 200000,
        ]);

        $request = Request::create('/api/finance/honor/my-payrolls');
        $request->setUserResolver(fn () => User::findOrFail(11));
        $response = app(HonorController::class)->myPayrolls($request)->getData(true);

        $this->assertCount(1, $response['data']);
        $this->assertSame(100000, (int) $response['data'][0]['total_amount']);
        $this->assertSame('Honor dosen satu', $response['data'][0]['items'][0]['description']);
    }

    public function test_lecturer_cannot_download_another_lecturers_slip(): void
    {
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        $payroll->update(['status' => 'PAID', 'paid_at' => now()]);
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 2,
            'source_key' => 'private-two',
            'description' => 'Honor dosen dua',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 200000,
            'amount_snapshot' => 200000,
        ]);

        $request = Request::create('/api/finance/honor/my-payrolls/1/pdf');
        $request->setUserResolver(fn () => User::findOrFail(11));

        $this->expectException(NotFoundHttpException::class);
        app(HonorController::class)->myPayrollPdf($request, $payroll);
    }

    public function test_paid_honor_slip_verification_is_bound_to_its_snapshot_and_signers(): void
    {
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        $payroll->update(['status' => 'PAID', 'paid_at' => now()]);
        $item = HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 1,
            'source_key' => 'private-slip',
            'description' => 'Honor mengajar',
            'activity_date' => '2026-10-05',
            'quantity' => 2,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 200000,
        ]);
        $deduction = HonorPayrollDeduction::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 1,
            'deduction_type' => 'TAX',
            'description' => 'PPh 21',
            'amount' => 10000,
        ]);
        $reference = HonorSlipVerification::reference(
            $payroll,
            'lecturer',
            1,
            'Dosen Satu',
            null,
            collect([$item]),
            7,
            'Petugas Keuangan',
            8,
            'Wakil Ketua II',
            collect([$deduction]),
        );
        $token = OperationalDocumentVerification::issue('honor-slip', $payroll->id, $reference);
        $request = Request::create('/api/verify/honor-slip/'.$token, 'GET', [
            'recipient' => 'lecturer:1',
            'finance_signer_id' => 7,
            'finance_signer_name' => 'Petugas Keuangan',
            'waket2_signer_id' => 8,
            'waket2_signer_name' => 'Wakil Ketua II',
            'signer' => 'finance',
        ]);

        $verified = app(VerifyController::class)->verifyHonorSlip($request, $token);
        $data = $verified->getData(true);
        $this->assertTrue($data['valid']);
        $this->assertSame(HonorSlipVerification::number($payroll, 'lecturer', 1), $data['number']);
        $this->assertSame('Petugas Keuangan', $data['signer_info']['name']);

        $this->assertArrayNotHasKey('amount', $data);
        $this->assertArrayNotHasKey('total', $data);

        $html = view('pdf.lecturer-honor-slip', [
            'recipientName' => 'Dosen Satu',
            'recipientIdentifierLabel' => 'NIDN',
            'recipientIdentifier' => null,
            'recipientType' => 'lecturer',
            'payroll' => $payroll->load('semester'),
            'items' => collect([$item]),
            'grossTotal' => 200000,
            'deductions' => collect([$deduction]),
            'total' => 190000,
            'slipNumber' => $data['number'],
            'recipientKey' => 'lecturer:1',
            'institution' => null,
            'logoPath' => null,
            'letterheadPath' => null,
            'verifyUrl' => 'https://example.test/verify/honor-slip/'.$token,
            'publicVerifyUrl' => 'https://example.test/verify/honor-slip/'.$token.'?recipient=lecturer%3A1',
            'qrVerification' => 'data:image/png;base64,verification',
            'financeSignatureUrl' => 'data:image/png;base64,finance',
            'waket2SignatureUrl' => 'data:image/png;base64,waket2',
            'financeSignerName' => 'Petugas Keuangan',
            'financeSignerId' => 7,
            'waket2SignerId' => 8,
            'waket2Name' => 'Wakil Ketua II',
            'printedAt' => now(),
        ])->render();
        $this->assertStringContainsString('SLIP HONOR DOSEN', $html);
        $this->assertStringContainsString($data['number'], $html);
        $this->assertStringContainsString('Mengetahui,', $html);
        $this->assertStringContainsString('Petugas Keuangan', $html);
        $this->assertStringContainsString('@page { margin: 5mm 15mm 28mm; }', $html);
        $this->assertStringContainsString('width: 100%; height: auto;', $html);
        $this->assertStringNotContainsString('max-height: 25mm', $html);
        $this->assertStringContainsString('alt="QR verifikasi slip honor"', $html);
        $this->assertStringContainsString('Pajak — PPh 21', $html);
        $this->assertStringContainsString('Rp 190.000', $html);
        $this->assertStringNotContainsString('Dokumen pribadi', $html);
        $this->assertStringNotContainsString('Slip ini memuat rincian honor', $html);
        $this->assertLessThan(strpos($html, 'Bagian Keuangan,'), strpos($html, 'Mengetahui,'));

        $deduction->update(['amount' => 15000]);
        $invalid = app(VerifyController::class)->verifyHonorSlip($request, $token);
        $this->assertFalse($invalid->getData(true)['valid']);
        $this->assertSame(404, $invalid->getStatusCode());
    }

    public function test_draft_payroll_deductions_are_recipient_scoped_and_cannot_exceed_gross(): void
    {
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 1,
            'source_key' => 'deduction-test',
            'description' => 'Honor mengajar',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
        ]);

        $request = Request::create('/api/finance/honor/payrolls/'.$payroll->id.'/deductions', 'POST', [
            'recipient_type' => 'lecturer',
            'recipient_id' => 1,
            'deduction_type' => 'TAX',
            'description' => 'PPh 21',
            'amount' => 10000,
        ]);
        $request->setUserResolver(fn () => User::findOrFail(11));
        $response = app(HonorController::class)->storePayrollDeduction($request, $payroll)->getData(true);

        $this->assertSame('PPh 21', $response['data']['description']);
        $payrollData = app(HonorController::class)->showPayroll($payroll->fresh())->getData(true)['data'];
        $this->assertSame(100000, (int) $payrollData['gross_amount']);
        $this->assertSame(10000, (int) $payrollData['deduction_amount']);
        $this->assertSame(90000, (int) $payrollData['total_amount']);

        $request->merge(['description' => 'Potongan melebihi honor', 'amount' => 100001]);
        try {
            app(HonorController::class)->storePayrollDeduction($request, $payroll);
            $this->fail('Deductions greater than the recipient gross must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $payroll->update(['status' => 'APPROVED']);
        try {
            app(HonorController::class)->storePayrollDeduction($request, $payroll);
            $this->fail('Approved payroll deductions must remain locked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_staff_monthly_history_only_returns_their_own_paid_items(): void
    {
        DB::table('users')->insert(['id' => 13, 'name' => 'Admin Umum']);
        DB::table('staff')->where('id', 1)->update(['user_id' => 13]);
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        $payroll->update(['status' => 'PAID', 'paid_at' => now()]);
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'staff_id' => 1,
            'source_key' => 'staff-private-one',
            'description' => 'Honor bulanan tenaga kependidikan',
            'activity_date' => '2026-10-01',
            'quantity' => 1,
            'unit_rate_snapshot' => 1800000,
            'amount_snapshot' => 1800000,
        ]);
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'staff_id' => 2,
            'source_key' => 'staff-private-two',
            'description' => 'Honor jabatan lain',
            'activity_date' => '2026-10-01',
            'quantity' => 1,
            'unit_rate_snapshot' => 2100000,
            'amount_snapshot' => 2100000,
        ]);

        $request = Request::create('/api/finance/honor/my-payrolls');
        $request->setUserResolver(fn () => User::findOrFail(13));
        $response = app(HonorController::class)->myPayrolls($request)->getData(true);

        $this->assertCount(1, $response['data']);
        $this->assertSame(1800000, (int) $response['data'][0]['total_amount']);
        $this->assertSame('Honor bulanan tenaga kependidikan', $response['data'][0]['items'][0]['description']);
    }

    public function test_unused_rate_can_be_updated_and_deleted_but_used_rate_is_locked(): void
    {
        $rate = HonorRate::create([
            'name' => 'Honor insidental',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'amount' => 100000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        $request = Request::create('/api/finance/honor/rates/'.$rate->id, 'PUT', [
            'name' => 'Honor penguji',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'position_code' => null,
            'delivery_mode' => null,
            'amount' => 125000,
            'effective_from' => '2026-10-01',
            'effective_until' => null,
        ]);

        $updated = app(HonorController::class)->updateRate($request, $rate)->getData(true)['data'];
        $this->assertSame('Honor penguji', $updated['name']);
        $this->assertSame(125000, (int) $updated['amount']);
        app(HonorController::class)->destroyRate($rate->fresh());
        $this->assertDatabaseMissing('honor_rates', ['id' => $rate->id]);

        $usedRate = HonorRate::create([
            'name' => 'Honor aktivitas',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'amount' => 100000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        HonorActivity::create([
            'lecturer_id' => 1,
            'honor_rate_id' => $usedRate->id,
            'source_key' => 'manual:used-rate',
            'source_type' => 'INCIDENTAL',
            'reference_number' => 'USED-RATE',
            'description' => 'Aktivitas terikat tarif',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
            'status' => 'PENDING',
        ]);
        $changeUsedRate = Request::create('/api/finance/honor/rates/'.$usedRate->id, 'PUT', [
            'name' => 'Tarif diubah',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'position_code' => null,
            'delivery_mode' => null,
            'amount' => 150000,
            'effective_from' => '2026-10-01',
            'effective_until' => null,
        ]);

        try {
            app(HonorController::class)->updateRate($changeUsedRate, $usedRate);
            $this->fail('A rate referenced by an activity must remain immutable.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_manual_verified_activity_can_be_edited_or_deleted_until_payroll_is_locked(): void
    {
        $rate = HonorRate::create([
            'name' => 'Honor insidental',
            'category' => 'INCIDENTAL',
            'unit' => 'PER_ACTIVITY',
            'amount' => 200000,
            'effective_from' => '2026-10-01',
            'is_active' => true,
        ]);
        $activity = HonorActivity::create([
            'lecturer_id' => 1,
            'honor_rate_id' => $rate->id,
            'source_key' => 'manual:activity-edit',
            'source_type' => 'INCIDENTAL',
            'reference_number' => 'ACTIVITY-EDIT',
            'description' => 'Kegiatan awal',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 200000,
            'amount_snapshot' => 200000,
            'status' => 'PENDING',
        ]);
        $draft = $this->monthlyPayroll('MONTHLY-2026-10');
        HonorPayrollItem::create([
            'honor_payroll_id' => $draft->id,
            'lecturer_id' => 1,
            'honor_rate_id' => $rate->id,
            'source_key' => $activity->source_key,
            'description' => $activity->description,
            'activity_date' => $activity->activity_date,
            'quantity' => 1,
            'unit_rate_snapshot' => 200000,
            'amount_snapshot' => 200000,
        ]);
        $request = Request::create('/api/finance/honor/activities/'.$activity->id, 'PUT', [
            'lecturer_id' => 1,
            'honor_rate_id' => $rate->id,
            'reference_number' => 'ACTIVITY-EDIT',
            'description' => 'Kegiatan diperbarui',
            'activity_date' => '2026-10-06',
            'quantity' => 2,
            'semester_id' => null,
        ]);
        $updated = app(HonorController::class)->updateActivity($request, $activity)->getData(true)['data'];
        $this->assertSame('PENDING', $updated['status']);
        $this->assertSame(400000, (int) $updated['amount_snapshot']);
        $this->assertDatabaseMissing('honor_payroll_items', ['source_key' => $activity->source_key]);

        $activity->update([
            'status' => 'VERIFIED',
            'verified_by' => 11,
            'verified_at' => now(),
            'verification_note' => 'Sudah diperiksa.',
        ]);
        $request->merge([
            'description' => 'Kegiatan diperbarui setelah verifikasi',
            'activity_date' => '2026-10-07',
        ]);
        $updated = app(HonorController::class)->updateActivity($request, $activity)->getData(true)['data'];
        $this->assertSame('PENDING', $updated['status']);
        $this->assertNull($updated['verified_by']);
        $this->assertNull($updated['verified_at']);
        $this->assertNull($updated['verification_note']);

        $activity->refresh()->update(['status' => 'VERIFIED']);
        $draft->update(['status' => 'APPROVED']);
        HonorPayrollItem::create([
            'honor_payroll_id' => $draft->id,
            'lecturer_id' => 1,
            'honor_rate_id' => $rate->id,
            'source_key' => $activity->source_key,
            'description' => $activity->description,
            'activity_date' => $activity->activity_date,
            'quantity' => 2,
            'unit_rate_snapshot' => 200000,
            'amount_snapshot' => 400000,
        ]);
        $lockedActivity = app(HonorController::class)
            ->activities(Request::create('/api/finance/honor/activities'))
            ->getData(true)['data'][0];
        $this->assertTrue((bool) $lockedActivity['is_locked']);

        try {
            app(HonorController::class)->destroyActivity($activity);
            $this->fail('An activity in an approved payroll must remain locked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $draft->update(['status' => 'DRAFT']);
        $draft->items()->delete();
        $activity->refresh()->update(['status' => 'VERIFIED']);
        app(HonorController::class)->destroyActivity($activity);
        $this->assertDatabaseMissing('honor_activities', ['id' => $activity->id]);
    }

    public function test_draft_and_paid_payroll_periods_can_be_deleted_but_approved_period_cannot(): void
    {
        $payroll = $this->monthlyPayroll('MONTHLY-2026-10');
        HonorPayrollItem::create([
            'honor_payroll_id' => $payroll->id,
            'lecturer_id' => 1,
            'source_key' => 'period-snapshot',
            'description' => 'Snapshot draft',
            'activity_date' => '2026-10-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
        ]);
        $request = Request::create('/api/finance/honor/payrolls/'.$payroll->id, 'PUT', [
            'period_type' => 'MONTHLY',
            'year' => 2026,
            'month' => 11,
        ]);
        $updated = app(HonorController::class)->updatePayroll($request, $payroll)->getData(true);
        $this->assertSame('MONTHLY-2026-11', $updated['data']['period_key']);
        $this->assertDatabaseMissing('honor_payroll_items', ['source_key' => 'period-snapshot']);

        app(HonorController::class)->destroyPayroll($payroll->fresh());
        $this->assertDatabaseMissing('honor_payrolls', ['id' => $payroll->id]);

        $paid = $this->monthlyPayroll('MONTHLY-2026-11');
        $paid->update([
            'status' => 'PAID',
            'paid_at' => now(),
            'payment_reference' => 'BANK-TRANSFER-123',
        ]);
        $paidItem = HonorPayrollItem::create([
            'honor_payroll_id' => $paid->id,
            'lecturer_id' => 1,
            'source_key' => 'paid-period-snapshot',
            'description' => 'Honor paid snapshot',
            'activity_date' => '2026-11-05',
            'quantity' => 1,
            'unit_rate_snapshot' => 100000,
            'amount_snapshot' => 100000,
        ]);
        app(HonorController::class)->destroyPayroll($paid->fresh());
        $this->assertDatabaseMissing('honor_payrolls', ['id' => $paid->id]);
        $this->assertDatabaseMissing('honor_payroll_items', ['id' => $paidItem->id]);

        $locked = $this->monthlyPayroll('MONTHLY-2026-12');
        $locked->update(['status' => 'APPROVED']);
        try {
            app(HonorController::class)->destroyPayroll($locked);
            $this->fail('An approved payroll must not be deletable.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function monthlyPayroll(string $key): HonorPayroll
    {
        return HonorPayroll::create([
            'period_key' => $key,
            'period_type' => 'MONTHLY',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'DRAFT',
        ]);
    }
}
