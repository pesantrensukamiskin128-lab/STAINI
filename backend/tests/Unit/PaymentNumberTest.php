<?php

namespace Tests\Unit;

use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_number')->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payments');

        parent::tearDown();
    }

    public function test_next_number_uses_highest_existing_sequence_instead_of_row_count(): void
    {
        $today = now()->format('Ymd');
        DB::table('payments')->insert([
            ['payment_number' => "PAY-{$today}-0001", 'created_at' => now(), 'updated_at' => now()],
            ['payment_number' => "PAY-{$today}-0172", 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame('PAY-'.now()->format('Ymd').'-0173', Payment::generatePaymentNumber());
    }

    public function test_next_number_ignores_payments_from_other_dates(): void
    {
        $today = now()->format('Ymd');
        $yesterday = now()->subDay()->format('Ymd');
        DB::table('payments')->insert([
            ['payment_number' => "PAY-{$yesterday}-0999", 'created_at' => now(), 'updated_at' => now()],
            ['payment_number' => "PAY-{$today}-0001", 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame('PAY-'.now()->format('Ymd').'-0002', Payment::generatePaymentNumber());
    }
}
