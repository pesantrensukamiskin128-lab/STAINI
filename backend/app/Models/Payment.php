<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id', 'student_id', 'payment_number', 'amount',
        'payment_method', 'payment_date', 'reference_number',
        'bank_name', 'account_number', 'account_name',
        'receipt_path', 'status', 'verified_by', 'verified_at',
        'note', 'rejection_reason',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public static function generatePaymentNumber(): string
    {
        $prefix = 'PAY';
        $date = now()->format('Ymd');
        $lastNumber = self::query()
            ->where('payment_number', 'like', "{$prefix}-{$date}-%")
            ->pluck('payment_number')
            ->map(function (string $paymentNumber) use ($prefix, $date): int {
                if (! preg_match('/^'.preg_quote($prefix, '/').'-(\d{8})-(\d+)$/', $paymentNumber, $matches)) {
                    return 0;
                }

                return $matches[1] === $date ? (int) $matches[2] : 0;
            })
            ->max() ?? 0;

        return "{$prefix}-{$date}-".str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithGeneratedPaymentNumber(array $attributes): static
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $attributes['payment_number'] = self::generatePaymentNumber();

                return static::create($attributes);
            } catch (QueryException $exception) {
                if (! self::isPaymentNumberUniqueViolation($exception) || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Unable to generate a unique payment number.');
    }

    private static function isPaymentNumberUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return ($sqlState === '23000' || $sqlState === '23505')
            && str_contains($message, 'payment_number')
            && ($driverCode === 0 || $driverCode === 1062);
    }
}
