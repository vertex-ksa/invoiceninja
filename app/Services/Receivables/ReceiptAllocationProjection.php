<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use InvalidArgumentException;

/** Only projects current, already-authorized native facts; never applies a payment. */
final class ReceiptAllocationProjection
{
    public function build(array $receipt, array $allocations, array $unpaidInvoices): array
    {
        $normalizer = new NativeBalance();
        $precision = $receipt['precision'] ?? null;
        $currency = $receipt['currency'] ?? null;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Native currency unavailable.');
        }
        foreach (['id', 'company_id', 'client_id'] as $field) {
            if (!is_string($receipt[$field] ?? null) || $receipt[$field] === '') {
                throw new InvalidArgumentException('Native receipt scope unavailable.');
            }
        }
        if (!is_int($receipt['source_version'] ?? null) || $receipt['source_version'] < 1) {
            throw new InvalidArgumentException('Native receipt version unavailable.');
        }
        $amount = (int) $normalizer->minor($receipt['amount'] ?? null, $precision);
        $applied = (int) $normalizer->minor($receipt['applied'] ?? null, $precision);
        $refunded = $normalizer->minor($receipt['refunded'] ?? null, $precision);
        if ($refunded !== '0' || $applied > $amount) {
            throw new InvalidArgumentException('Receipt requires manual refund or balance review.');
        }
        $seen = [];
        $total = 0;
        $rows = [];
        foreach ($allocations as $allocation) {
            $this->scope($allocation, $receipt);
            $id = $allocation['allocation_id'] ?? null;
            if (!is_string($id) || $id === '' || isset($seen[$id])) {
                throw new InvalidArgumentException('Duplicate or unavailable native allocation identity.');
            }
            $seen[$id] = true;
            if (!is_int($allocation['invoice_source_version'] ?? null) || $allocation['invoice_source_version'] < 1) {
                throw new InvalidArgumentException('Native allocated invoice version unavailable.');
            }
            $minor = (int) $normalizer->minor($allocation['amount'] ?? null, $precision);
            if ($normalizer->minor($allocation['refunded'] ?? null, $precision) !== '0'
                || $minor > PHP_INT_MAX - $total) {
                throw new InvalidArgumentException('Allocation requires manual review.');
            }
            $total += $minor;
            $rows[] = [
                'allocation_id' => $id,
                'invoice_id' => $allocation['invoice_id'],
                'invoice_number' => $allocation['invoice_number'],
                'amount_minor' => (string) $minor,
                'source_version' => $allocation['source_version'],
                'invoice_source_version' => $allocation['invoice_source_version'],
            ];
        }
        if ($total !== $applied) {
            throw new InvalidArgumentException('Native receipt and invoice applications do not reconcile.');
        }
        $unpaid = [];
        $invoiceIds = [];
        foreach ($unpaidInvoices as $invoice) {
            $this->scope($invoice, $receipt);
            if (isset($invoiceIds[$invoice['invoice_id']])) {
                throw new InvalidArgumentException('Duplicate native unpaid invoice.');
            }
            $invoiceIds[$invoice['invoice_id']] = true;
            $minor = $normalizer->minor($invoice['balance'] ?? null, $precision);
            if ($minor === '0') {
                throw new InvalidArgumentException('Native unpaid invoice is no longer unpaid.');
            }
            $unpaid[] = [
                'invoice_id' => $invoice['invoice_id'],
                'invoice_number' => $invoice['invoice_number'],
                'balance_minor' => $minor,
                'source_version' => $invoice['source_version'],
            ];
        }
        $receiptRow = [
            'payment_id' => $receipt['id'],
            'amount_minor' => (string) $amount,
            'applied_minor' => (string) $applied,
            'refunded_minor' => $refunded,
            'unapplied_minor' => (string) ($amount - $applied),
            'source_version' => $receipt['source_version'],
        ];
        return [
            'preview_only' => true,
            'write_authority' => 'NONE',
            'company_id' => $receipt['company_id'],
            'client_id' => $receipt['client_id'],
            'currency' => $currency,
            'currency_precision' => $precision,
            'receipt' => $receiptRow,
            'allocations' => $rows,
            'unpaid_invoices' => $unpaid,
            'complete_within_scope' => true,
            'is_point_in_time_snapshot' => false,
            'balance_basis' => 'current_stored_native_receipt_and_invoice_facts',
            'unapplied_basis' => 'native_amount_minus_applied_completed_zero_refund_invoice_only_unit_fx',
            'source_version_semantics' => 'native_updated_at_timestamp_not_monotonic_revision',
            'source_token' => hash('sha256', json_encode([$receipt, $allocations, $unpaidInvoices], JSON_THROW_ON_ERROR)),
            'source_token_semantics' => 'content_provenance_only_not_cas_or_approval',
        ];
    }

    private function scope(array $row, array $receipt): void
    {
        foreach (['company_id', 'client_id', 'currency', 'precision'] as $field) {
            if (($row[$field] ?? null) !== $receipt[$field]) {
                throw new InvalidArgumentException('Native invoice or allocation scope mismatch.');
            }
        }
        if (!is_string($row['invoice_id'] ?? null) || $row['invoice_id'] === ''
            || !is_string($row['invoice_number'] ?? null)
            || !is_int($row['source_version'] ?? null) || $row['source_version'] < 1) {
            throw new InvalidArgumentException('Native invoice identity or version unavailable.');
        }
    }
}
