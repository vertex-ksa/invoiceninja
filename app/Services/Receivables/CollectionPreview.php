<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Side-effect-free collection preview over an already authorized native snapshot.
 * The caller must enforce native invoice ACLs before passing rows. This class never
 * queries, posts, sends, or authorizes a reminder. Minor units and currency scale
 * must be normalized from the elected native balance; credits are already applied.
 */
final class CollectionPreview
{
    public function build(array $invoices, string $companyId, string $asOfDate, int $minimumOverdueDays): array
    {
        $asOf = $this->date($asOfDate);
        if ($companyId === '' || $minimumOverdueDays < 1) {
            throw new InvalidArgumentException('Company and positive overdue threshold required.');
        }
        $seen = [];
        $totals = [];
        $openTotals = [];
        $rows = [];
        foreach ($invoices as $invoice) {
            foreach (['id', 'company_id', 'currency', 'balance_minor', 'due_date', 'version', 'reminders_enabled', 'disputed', 'canceled', 'consent_revoked', 'promise_until'] as $field) {
                if (!array_key_exists($field, $invoice)) {
                    throw new InvalidArgumentException('Incomplete snapshot: '.$field);
                }
            }
            if (!is_string($invoice['id']) || $invoice['id'] === '' || $invoice['company_id'] !== $companyId) {
                throw new InvalidArgumentException('Invalid invoice scope.');
            }
            if (isset($seen[$invoice['id']])) {
                throw new InvalidArgumentException('Duplicate invoice snapshot.');
            }
            $seen[$invoice['id']] = true;
            if (!is_string($invoice['currency']) || !preg_match('/^[A-Z]{3}$/D', $invoice['currency']) || !is_int($invoice['version']) || $invoice['version'] < 1) {
                throw new InvalidArgumentException('Invalid currency or source version.');
            }
            foreach (['reminders_enabled', 'disputed', 'canceled', 'consent_revoked'] as $flag) {
                if (!is_bool($invoice[$flag])) {
                    throw new InvalidArgumentException('Explicit boolean state required.');
                }
            }
            $balance = $this->minor($invoice['balance_minor']);
            $currency = $invoice['currency'];
            $total = $totals[$currency] ?? 0;
            if ($balance > PHP_INT_MAX - $total) {
                throw new InvalidArgumentException('Control total overflow.');
            }
            $totals[$currency] = $total + $balance;
            if (!$invoice['canceled']) {
                $openTotals[$currency] = ($openTotals[$currency] ?? 0) + $balance;
            }
            $due = $invoice['due_date'] === null ? null : $this->date($invoice['due_date']);
            $overdue = $due === null ? null : (int) $due->diff($asOf)->format('%r%a');
            $promise = $invoice['promise_until'] === null ? null : $this->date($invoice['promise_until']);
            $reason = match (true) {
                $invoice['canceled'] => 'canceled',
                $balance === 0 => 'settled',
                $invoice['consent_revoked'] => 'consent_revoked',
                !$invoice['reminders_enabled'] => 'reminders_disabled',
                $invoice['disputed'] => 'dispute_hold',
                $promise !== null && $promise >= $asOf => 'promise_hold',
                $due === null => 'due_date_required',
                $overdue < $minimumOverdueDays => 'not_eligible',
                default => 'manual_review_required',
            };
            $rows[] = [
                'invoice_id' => $invoice['id'],
                'source_version' => $invoice['version'],
                'currency' => $currency,
                'balance_minor' => (string) $balance,
                'overdue_days' => $overdue === null ? null : max(0, $overdue),
                'state' => $reason,
                'dispatch_allowed' => false,
            ];
        }
        usort($rows, fn (array $a, array $b): int => ($b['overdue_days'] ?? -1) <=> ($a['overdue_days'] ?? -1) ?: strcmp($a['invoice_id'], $b['invoice_id']));
        ksort($totals);
        ksort($openTotals);
        return [
            'as_of_date' => $asOfDate,
            'source' => 'authorized_native_balance_snapshot',
            'preview_only' => true,
            'input_control_totals_minor_by_currency' => array_map(fn (int $amount): string => (string) $amount, $totals),
            'open_receivables_minor_by_currency' => array_map(fn (int $amount): string => (string) $amount, $openTotals),
            'rows' => $rows,
        ];
    }

    private function minor(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            throw new InvalidArgumentException('Minor units require a canonical nonnegative integer string.');
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new InvalidArgumentException('Minor units overflow.');
        }
        return (int) $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Calendar date required.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Canonical calendar date required.');
        }
        return $date;
    }
}


