<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use InvalidArgumentException;

/** First bounded USD2 allocation. Every stored/input/output amount stays decimal text. */
final class ExactAllocationAmounts
{
    private const FIELD_MAX_MINOR = 9999999999999999; // DECIMAL20,6, currency precision2.
    private const PIVOT_MAX_MINOR = 99999999999999; // DECIMAL16,4, currency precision2.

    public function __construct()
    {
        if (PHP_INT_SIZE !== 8) throw new \LogicException('Exact allocation requires 64-bit PHP integers.');
    }

    public function unsigned(mixed $stored): int
    {
        $minor = (new NativeBalance())->minor($stored, 2);
        if (strlen($minor) > 16 || (int) $minor > self::FIELD_MAX_MINOR) {
            throw new InvalidArgumentException('Native stored money exceeds the supported envelope.');
        }
        return (int) $minor;
    }

    public function signed(mixed $stored): int
    {
        if (!is_string($stored)) throw new InvalidArgumentException('Native ledger money is not decimal text.');
        if (str_starts_with($stored, '-')) {
            $minor = $this->unsigned(substr($stored, 1));
            if ($minor === 0) throw new InvalidArgumentException('Noncanonical negative zero.');
            return -$minor;
        }
        return $this->unsigned($stored);
    }

    public function amount(mixed $minor): int
    {
        if (!is_string($minor) || !preg_match('/^[1-9][0-9]{0,13}$/D', $minor)
            || (int) $minor > self::PIVOT_MAX_MINOR) {
            throw new InvalidArgumentException('A positive exact USD minor amount is required.');
        }
        return (int) $minor;
    }

    public function decimal(int $minor, int $scale = 6): string
    {
        if (!in_array($scale, [4, 6], true) || $minor < -self::FIELD_MAX_MINOR || $minor > self::FIELD_MAX_MINOR) {
            throw new InvalidArgumentException('Native decimal output exceeds its envelope.');
        }
        $digits = str_pad((string) ($minor < 0 ? -$minor : $minor), 3, '0', STR_PAD_LEFT);
        return ($minor < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2).str_repeat('0', $scale - 2);
    }

    /** $facts are exact raw decimals; allocations_sum_minor is recomputed from locked active pivots. */
    public function plan(array $facts, mixed $amountMinor): array
    {
        $amount = $this->amount($amountMinor);
        $gross = $this->unsigned($facts['payment_amount'] ?? null);
        $applied = $this->unsigned($facts['payment_applied'] ?? null);
        $refunded = $this->unsigned($facts['payment_refunded'] ?? null);
        $balance = $this->unsigned($facts['invoice_balance'] ?? null);
        $paid = $this->unsigned($facts['invoice_paid_to_date'] ?? null);
        $client = $this->unsigned($facts['client_balance'] ?? null);
        $ledger = $this->signed($facts['ledger_balance'] ?? null);
        $sum = $facts['allocations_sum_minor'] ?? null;
        if (!is_string($sum) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $sum)
            || (int) $sum !== $applied || $refunded !== 0 || $applied > $gross
            || $amount > $gross - $applied || $amount >= $balance || $amount > $client
            || $paid > self::FIELD_MAX_MINOR - $amount || $ledger < -self::FIELD_MAX_MINOR + $amount) {
            throw new InvalidArgumentException('Current native receipt/invoice facts do not permit this partial allocation.');
        }
        return [
            'amount_minor' => (string) $amount,
            'payment_applied' => $this->decimal($applied + $amount),
            'payment_unapplied_minor' => (string) ($gross - $applied - $amount),
            'invoice_balance' => $this->decimal($balance - $amount),
            'invoice_paid_to_date' => $this->decimal($paid + $amount),
            'client_balance' => $this->decimal($client - $amount),
            'ledger_adjustment' => $this->decimal(-$amount),
            'ledger_balance' => $this->decimal($ledger - $amount),
            'paymentable_amount' => $this->decimal($amount, 4),
        ];
    }
}
