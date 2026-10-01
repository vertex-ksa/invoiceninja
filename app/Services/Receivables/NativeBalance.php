<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use InvalidArgumentException;

/** Exact normalization of the raw database decimal using native currency precision. */
final class NativeBalance
{
    public function minor(mixed $decimal, mixed $precision): string
    {
        if (!is_string($decimal) || !is_int($precision) || $precision < 0 || $precision > 6
            || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $decimal, $parts)) {
            throw new InvalidArgumentException('Unrepresentable native balance or currency precision.');
        }
        $fraction = $parts[2] ?? '';
        if (trim(substr($fraction, $precision), '0') !== '') {
            throw new InvalidArgumentException('Native balance exceeds currency precision; review required.');
        }
        $minor = ltrim($parts[1].str_pad(substr($fraction, 0, $precision), $precision, '0'), '0');
        $minor = $minor === '' ? '0' : $minor;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($minor) > strlen($maximum) || (strlen($minor) === strlen($maximum) && strcmp($minor, $maximum) > 0)) {
            throw new InvalidArgumentException('Native balance overflow.');
        }
        return $minor;
    }
}
