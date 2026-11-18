<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\FinanceController;
use App\Models\Semester;
use App\Models\Student;
use App\Services\FeeStructureResolver;
use App\Services\KrsEligibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KrsEligibilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 09:00:00');

        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('current_semester')->nullable();
            $table->string('class_type')->nullable();
            $table->string('nim');
            $table->string('name');
            $table->string('status')->default('Aktif');
            $table->unsignedInteger('entry_year')->nullable();
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
            $table->string('type')->nullable();
            $table->date('krs_start')->nullable();
            $table->date('krs_end')->nullable();
            $table->timestamps();
        });
        Schema::create('fee_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_recurring')->default(true);
            $table->timestamps();
        });
        Schema::create('fee_structures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fee_type_id');
            $table->unsignedBigInteger('study_program_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedInteger('academic_level')->nullable();
            $table->string('class_type')->nullable();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('scholarship_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->string('status');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('fee_type_id');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('amount');
            $table->string('status');
            $table->timestamps();
        });

        DB::table('students')->insert([
            'id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20, 'current_semester' => 3,
            'class_type' => 'REGULER', 'nim' => 'MHS-001', 'name' => 'Mahasiswa',
            'entry_year' => 2024, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('academic_years')->insert([
            'id' => 20, 'name' => '2024/2025', 'start_date' => '2024-09-01',
            'end_date' => '2025-08-31', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('academic_years')->insert([
            'id' => 30, 'name' => '2025/2026', 'start_date' => '2025-09-01',
            'end_date' => '2026-08-31', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('academic_years')->insert([
            'id' => 40, 'name' => 'Tahun Akademik 2026/2027', 'start_date' => '2026-07-01',
            'end_date' => '2027-07-29', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('semesters')->insert([
            'id' => 1, 'academic_year_id' => 30, 'name' => 'Ganjil 2025/2026',
            'type' => 'Ganjil',
            'krs_start' => '2026-09-10', 'krs_end' => '2026-09-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('semesters')->insert([
            'id' => 2, 'academic_year_id' => 40, 'name' => 'Ganjil 2026/2027',
            'type' => 'Ganjil',
            'krs_start' => '2026-09-10', 'krs_end' => '2026-09-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_types')->insert([
            'id' => 1, 'code' => 'SPP', 'name' => 'SPP', 'is_active' => true,
            'is_mandatory' => true, 'is_recurring' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_types')->insert([
            'id' => 2, 'code' => 'HER', 'name' => 'Herregistrasi', 'is_active' => true,
            'is_mandatory' => true, 'is_recurring' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'id' => 3, 'study_program_id' => 10, 'academic_year_id' => 40, 'current_semester' => 1,
            'class_type' => 'REGULER', 'nim' => 'MHS-2026', 'name' => 'Mahasiswa Baru',
            'entry_year' => 2026, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resolver_chooses_the_most_specific_structure_per_fee_type(): void
    {
        DB::table('fee_structures')->insert([
            ['id' => 1, 'fee_type_id' => 1, 'study_program_id' => null, 'academic_year_id' => null, 'academic_level' => null, 'amount' => 100000, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20, 'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resolved = app(FeeStructureResolver::class)->resolve(Student::findOrFail(1), Semester::findOrFail(1));

        $this->assertCount(1, $resolved);
        $this->assertSame(2, $resolved->first()->id);
        $this->assertSame(250000, (int) $resolved->first()->amount);
    }

    public function test_krs_requires_one_verified_spp_payment_regardless_of_amount(): void
    {
        DB::table('fee_structures')->insert([
            'fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20,
            'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(KrsEligibilityService::class);
        $student = Student::findOrFail(1);
        $semester = Semester::findOrFail(1);

        $missingInvoice = $service->evaluate($student, $semester);
        $this->assertFalse($missingInvoice['finance']['is_clear']);
        $this->assertSame([1], $missingInvoice['finance']['missing_fee_type_ids']);

        DB::table('invoices')->insert([
            'id' => 1, 'invoice_number' => 'INV-001', 'student_id' => 1, 'semester_id' => 1,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'total_amount' => 250000,
            'paid_amount' => 5, 'status' => 'PARTIAL', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => 1, 'fee_type_id' => 1, 'amount' => 250000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'id' => 1, 'invoice_id' => 1, 'amount' => 1, 'status' => 'VERIFIED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $onePayment = $service->evaluate($student, $semester);
        $this->assertTrue($onePayment['finance']['is_clear']);
        $this->assertTrue($onePayment['can_submit']);
        $this->assertSame(1, $onePayment['finance']['verified_spp_payments']);
        $this->assertSame(1, $onePayment['finance']['required_spp_payments']);
        $this->assertSame(1, (int) DB::table('payments')->where('invoice_id', 1)->sum('amount'));
    }

    public function test_full_spp_scholarship_removes_the_spp_payment_requirement(): void
    {
        DB::table('fee_structures')->insert([
            'fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20,
            'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'id' => 1, 'invoice_number' => 'INV-001', 'student_id' => 1, 'semester_id' => 1,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'total_amount' => 250000,
            'scholarship_amount' => 250000, 'paid_amount' => 0, 'status' => 'PAID',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => 1, 'fee_type_id' => 1, 'amount' => 250000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $eligibility = app(KrsEligibilityService::class)->evaluate(
            Student::findOrFail(1),
            Semester::findOrFail(1)
        );

        $this->assertTrue($eligibility['finance']['is_clear']);
        $this->assertTrue($eligibility['can_submit']);
        $this->assertSame(0, $eligibility['finance']['verified_spp_payments']);
        $this->assertSame(0, $eligibility['finance']['required_spp_payments']);
    }

    public function test_one_spp_payment_does_not_waive_other_mandatory_fees(): void
    {
        DB::table('fee_types')->insert([
            'id' => 3, 'code' => 'HER', 'name' => 'Herregistrasi', 'is_active' => true,
            'is_mandatory' => true, 'is_recurring' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_structures')->insert([
            ['fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20, 'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['fee_type_id' => 3, 'study_program_id' => 10, 'academic_year_id' => 20, 'academic_level' => 3, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('invoices')->insert([
            'id' => 1, 'invoice_number' => 'INV-001', 'student_id' => 1, 'semester_id' => 1,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'total_amount' => 300000,
            'paid_amount' => 6, 'status' => 'PARTIAL', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            ['invoice_id' => 1, 'fee_type_id' => 1, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['invoice_id' => 1, 'fee_type_id' => 3, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payments')->insert([
            'id' => 1, 'invoice_id' => 1, 'amount' => 1, 'status' => 'VERIFIED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $eligibility = app(KrsEligibilityService::class)->evaluate(
            Student::findOrFail(1),
            Semester::findOrFail(1)
        );

        $this->assertSame(1, $eligibility['finance']['verified_spp_payments']);
        $this->assertFalse($eligibility['finance']['is_clear']);
        $this->assertFalse($eligibility['can_submit']);
        $this->assertCount(1, $eligibility['finance']['blocking_invoices']);
    }

    public function test_krs_requires_herregistrasi_and_spp_but_not_uts(): void
    {
        DB::table('fee_types')->insert([
            'id' => 3, 'code' => 'UTS', 'name' => 'Ujian Tengah Semester', 'is_active' => true,
            'is_mandatory' => true, 'is_recurring' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_structures')->insert([
            ['fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 30, 'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['fee_type_id' => 2, 'study_program_id' => 10, 'academic_year_id' => 30, 'academic_level' => 3, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()],
            ['fee_type_id' => 3, 'study_program_id' => 10, 'academic_year_id' => 30, 'academic_level' => 3, 'amount' => 200000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('invoices')->insert([
            ['id' => 1, 'invoice_number' => 'INV-SPP', 'student_id' => 1, 'semester_id' => 1, 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'total_amount' => 250000, 'paid_amount' => 250000, 'status' => 'PAID', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'invoice_number' => 'INV-HER', 'student_id' => 1, 'semester_id' => 1, 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'total_amount' => 50000, 'paid_amount' => 50000, 'status' => 'PAID', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('invoice_items')->insert([
            ['invoice_id' => 1, 'fee_type_id' => 1, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['invoice_id' => 2, 'fee_type_id' => 2, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('payments')->insert([
            'id' => 1, 'invoice_id' => 1, 'amount' => 250000, 'status' => 'VERIFIED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $eligibility = app(KrsEligibilityService::class)->evaluate(
            Student::findOrFail(1),
            Semester::findOrFail(1)
        );

        $this->assertEqualsCanonicalizing([1, 2], $eligibility['finance']['required_fee_type_ids']);
        $this->assertTrue($eligibility['finance']['is_clear']);
        $this->assertTrue($eligibility['can_submit']);
    }

    public function test_2026_freshmen_can_submit_krs_without_herregistrasi_or_spp_for_first_semester(): void
    {
        DB::table('fee_structures')->insert([
            ['fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 40, 'academic_level' => 1, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['fee_type_id' => 2, 'study_program_id' => 10, 'academic_year_id' => 40, 'academic_level' => 1, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $eligibility = app(KrsEligibilityService::class)->evaluate(
            Student::findOrFail(3),
            Semester::findOrFail(2)
        );

        $this->assertTrue($eligibility['finance']['is_clear']);
        $this->assertTrue($eligibility['can_submit']);
        $this->assertSame([], $eligibility['finance']['required_fee_type_ids']);
        $this->assertSame(['HER', 'SPP'], $eligibility['finance']['exempted_fee_type_codes']);
    }

    public function test_2026_freshmen_can_submit_despite_unpaid_mandatory_fees_during_academic_year_2026_2027(): void
    {
        DB::table('fee_types')->insert([
            'id' => 3, 'code' => 'ADMIN', 'name' => 'Biaya Administrasi', 'is_active' => true,
            'is_mandatory' => true, 'is_recurring' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_structures')->insert([
            ['fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 40, 'academic_level' => null, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['fee_type_id' => 2, 'study_program_id' => 10, 'academic_year_id' => 40, 'academic_level' => null, 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()],
            ['fee_type_id' => 3, 'study_program_id' => 10, 'academic_year_id' => 40, 'academic_level' => null, 'amount' => 10000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $service = app(KrsEligibilityService::class);
        $student = Student::findOrFail(3);
        $firstSemester = $service->evaluate($student, Semester::findOrFail(2));
        $this->assertTrue($firstSemester['finance']['is_clear']);
        $this->assertSame([], $firstSemester['finance']['missing_fee_type_ids']);
        $this->assertTrue($firstSemester['finance']['krs_payment_exempt']);
        $this->assertTrue($firstSemester['can_submit']);
        $this->assertStringContainsString('Tagihan tetap tercatat', $firstSemester['finance']['message']);
        $service->assertFinanciallyClear($student, Semester::findOrFail(2));

        DB::table('semesters')->insert([
            'id' => 3, 'academic_year_id' => 40, 'name' => 'Genap 2026/2027',
            'type' => 'Genap',
            'krs_start' => '2027-02-01', 'krs_end' => '2027-06-30',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Carbon::setTestNow('2027-03-01 09:00:00');
        $secondSemester = $service->evaluate($student, Semester::findOrFail(3));
        $this->assertSame([], $secondSemester['finance']['exempted_fee_type_codes']);
        $this->assertEqualsCanonicalizing([1, 2], $secondSemester['finance']['missing_fee_type_ids']);
        $this->assertFalse($secondSemester['finance']['is_clear']);
        $this->assertTrue($secondSemester['finance']['krs_payment_exempt']);
        $this->assertTrue($secondSemester['can_submit']);
        $service->assertFinanciallyClear($student, Semester::findOrFail(3));

        DB::table('academic_years')->insert([
            'id' => 50, 'name' => '2027/2028', 'start_date' => '2027-07-01',
            'end_date' => '2028-06-30', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('semesters')->insert([
            'id' => 4, 'academic_year_id' => 50, 'name' => 'Ganjil 2027/2028',
            'type' => 'Ganjil',
            'krs_start' => '2027-08-01', 'krs_end' => '2027-08-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Carbon::setTestNow('2027-08-15 09:00:00');
        $nextAcademicYear = $service->evaluate($student, Semester::findOrFail(4));
        $this->assertFalse($nextAcademicYear['finance']['krs_payment_exempt']);
        $this->assertFalse($nextAcademicYear['can_submit']);
    }

    public function test_closed_krs_period_is_reported(): void
    {
        DB::table('semesters')->where('id', 1)->update(['krs_end' => '2026-09-16']);

        $result = app(KrsEligibilityService::class)->evaluate(Student::findOrFail(1), Semester::findOrFail(1));

        $this->assertFalse($result['period']['is_open']);
        $this->assertFalse($result['can_submit']);
    }

    public function test_batch_generation_previews_creates_and_then_skips_complete_invoices(): void
    {
        DB::table('fee_structures')->insert([
            'fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20,
            'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $previewRequest = Request::create('/api/finance/invoices/generate-batch', 'POST', [
            'semester_id' => 1, 'due_date' => '2026-09-20', 'fee_type_ids' => [1], 'preview' => true,
        ]);
        $preview = app(FinanceController::class)->generateBatch($previewRequest)->getData(true);
        $this->assertSame(1, $preview['would_create']);
        $this->assertDatabaseCount('invoices', 0);

        $createRequest = Request::create('/api/finance/invoices/generate-batch', 'POST', [
            'semester_id' => 1, 'due_date' => '2026-09-20', 'fee_type_ids' => [1], 'preview' => false,
        ]);
        $created = app(FinanceController::class)->generateBatch($createRequest)->getData(true);
        $this->assertSame(1, $created['created']);
        $this->assertDatabaseHas('invoice_items', ['fee_type_id' => 1, 'amount' => 250000]);

        $repeated = app(FinanceController::class)->generateBatch($createRequest)->getData(true);
        $this->assertSame(0, $repeated['created']);
        $this->assertSame(1, $repeated['already_complete']);
    }

    public function test_batch_generation_filters_active_students_by_entry_year(): void
    {
        DB::table('students')->insert([
            'id' => 2, 'study_program_id' => 10, 'academic_year_id' => 20, 'current_semester' => 3,
            'class_type' => 'REGULER',
            'nim' => 'MHS-002', 'name' => 'Mahasiswa Angkatan Lain', 'entry_year' => 2023,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_structures')->insert([
            'fee_type_id' => 1, 'study_program_id' => 10, 'academic_year_id' => 20,
            'academic_level' => 3, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = Request::create('/api/finance/invoices/generate-batch', 'POST', [
            'semester_id' => 1,
            'due_date' => '2026-09-20',
            'fee_type_ids' => [1],
            'entry_year' => 2024,
            'preview' => true,
        ]);

        $result = app(FinanceController::class)->generateBatch($request)->getData(true);

        $this->assertSame(1, $result['students']);
        $this->assertSame(1, $result['would_create']);
        $this->assertDatabaseCount('invoices', 0);
    }
}
