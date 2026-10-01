<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use DateTimeImmutable;
use InvalidArgumentException;

/** Native due_date is a nullable business-calendar datetime, not an instant to shift across zones. */
final class NativeInvoiceDate
{
    public function calendar(mixed $value): ?string
    {
        if ($value === null) { return null; }
        if (!is_string($value)) { throw new InvalidArgumentException('Invalid native due date.'); }
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i:s.u'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) { return $date->format('Y-m-d'); }
        }
        throw new InvalidArgumentException('Invalid native due date.');
    }
}
