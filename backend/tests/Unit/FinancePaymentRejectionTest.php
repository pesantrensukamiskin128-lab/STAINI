<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FinanceController;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancePaymentRejectionTest extends TestCase
{
    public function test_rejecting_a_payment_requires_a_reason(): void
    {
        $this->expectException(ValidationException::class);

        app(FinanceController::class)->verifyPayment(
            Request::create('/api/finance/payments/1/verify', 'POST', [
                'action' => 'reject',
                'reason' => '   ',
            ]),
            new Payment(['status' => 'PENDING']),
        );
    }
}
