<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\FinanceController;
use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinancePaymentDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('scholarship_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->string('status');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('student_id');
            $table->string('payment_number');
            $table->unsignedBigInteger('amount');
            $table->string('payment_method')->default('TRANSFER');
            $table->dateTime('payment_date');
            $table->string('reference_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        DB::table('students')->insert(['id' => 1, 'user_id' => null]);

        DB::table('invoices')->insert([
            'id' => 1,
            'invoice_number' => 'INV-001',
            'student_id' => 1,
            'invoice_date' => '2026-10-01',
            'due_date' => '2099-12-31',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'PAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_verified_payment_recalculates_invoice_balance_and_status(): void
    {
        $this->createPayment(1, 'VERIFIED', 600, 'receipts/verified.jpg');
        $this->createPayment(2, 'VERIFIED', 400);
        Storage::fake('public');
        Storage::disk('public')->put('receipts/verified.jpg', 'receipt');

        $response = app(FinanceController::class)->destroyPayment(Payment::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseMissing('payments', ['id' => 1]);
        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'paid_amount' => 400,
            'status' => 'PARTIAL',
        ]);
        Storage::disk('public')->assertMissing('receipts/verified.jpg');
    }

    public function test_deleting_pending_payment_does_not_change_invoice_balance(): void
    {
        $this->createPayment(1, 'PENDING', 300);

        $response = app(FinanceController::class)->destroyPayment(Payment::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseMissing('payments', ['id' => 1]);
        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'paid_amount' => 1000,
            'status' => 'PAID',
        ]);
    }

    public function test_deleting_rejected_payment_remains_supported(): void
    {
        $this->createPayment(1, 'REJECTED', 300);

        $response = app(FinanceController::class)->destroyPayment(Payment::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseMissing('payments', ['id' => 1]);
    }

    public function test_deleting_last_verified_payment_restores_unpaid_invoice_status(): void
    {
        $this->createPayment(1, 'VERIFIED', 1000);

        $response = app(FinanceController::class)->destroyPayment(Payment::findOrFail(1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'paid_amount' => 0,
            'status' => 'UNPAID',
        ]);
    }

    public function test_refunded_payment_cannot_be_deleted(): void
    {
        $this->createPayment(1, 'REFUNDED', 1000);

        $response = app(FinanceController::class)->destroyPayment(Payment::findOrFail(1));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('payments', ['id' => 1, 'status' => 'REFUNDED']);
    }

    private function createPayment(int $id, string $status, int $amount, ?string $receiptPath = null): void
    {
        DB::table('payments')->insert([
            'id' => $id,
            'invoice_id' => 1,
            'student_id' => 1,
            'payment_number' => 'PAY-'.$id,
            'amount' => $amount,
            'payment_method' => 'TRANSFER',
            'payment_date' => '2026-10-02 10:00:00',
            'receipt_path' => $receiptPath,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
