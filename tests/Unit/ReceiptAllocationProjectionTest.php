<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Receivables\ReceiptAllocationProjection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptAllocationProjectionTest extends TestCase
{
    private function facts(): array
    {
        $scope = ['company_id' => '17', 'client_id' => 'clientHash', 'currency' => 'USD', 'precision' => 2];
        $receipt = $scope + ['id' => 'receiptHash', 'amount' => '10.000000', 'applied' => '9.500000', 'refunded' => '0.000000', 'source_version' => 1780000000, 'private_notes' => 'must not appear'];
        $invoice = $scope + ['invoice_id' => 'invoiceHash', 'invoice_number' => 'INV-SYNTHETIC', 'source_version' => 1780000001, 'private_notes' => 'must not appear'];
        $allocations = [$invoice + ['allocation_id' => '1', 'amount' => '6.0000', 'refunded' => '0.0000', 'invoice_source_version' => 1780000002], array_replace($invoice, ['allocation_id' => '2', 'amount' => '3.5000', 'refunded' => '0.0000', 'invoice_source_version' => 1780000002])];
        return [$receipt, $allocations, [$invoice + ['balance' => '2.250000']]];
    }

    public function testExactReconciliationAndResponseProjection(): void
    {
        $result = (new ReceiptAllocationProjection())->build(...$this->facts());
        self::assertSame(['payment_id', 'amount_minor', 'applied_minor', 'refunded_minor', 'unapplied_minor', 'source_version'], array_keys($result['receipt']));
        self::assertSame('1000', $result['receipt']['amount_minor']);
        self::assertSame('950', $result['receipt']['applied_minor']);
        self::assertSame('50', $result['receipt']['unapplied_minor']);
        self::assertSame(['600', '350'], array_column($result['allocations'], 'amount_minor'));
        self::assertSame('225', $result['unpaid_invoices'][0]['balance_minor']);
        self::assertSame(['allocation_id', 'invoice_id', 'invoice_number', 'amount_minor', 'source_version', 'invoice_source_version'], array_keys($result['allocations'][0]));
        self::assertSame(['invoice_id', 'invoice_number', 'balance_minor', 'source_version'], array_keys($result['unpaid_invoices'][0]));
        self::assertStringNotContainsString('private_notes', json_encode($result));
        self::assertFalse($result['is_point_in_time_snapshot']);
        self::assertSame('NONE', $result['write_authority']);
        self::assertSame('content_provenance_only_not_cas_or_approval', $result['source_token_semantics']);
    }

    public static function exactAmounts(): array
    {
        return [[0, '10.000000', '10'], [2, '0.010000', '1'], [4, '1.000100', '10001'], [6, '1.000001', '1000001'], [2, '90071992547409.93', '9007199254740993']];
    }

    #[DataProvider('exactAmounts')]
    public function testCurrencyPrecisionAndAboveJavascriptSafeInteger(int $precision, string $amount, string $minor): void
    {
        [$receipt] = $this->facts();
        $receipt = array_replace($receipt, ['precision' => $precision, 'amount' => $amount, 'applied' => '0', 'refunded' => '0']);
        self::assertSame($minor, (new ReceiptAllocationProjection())->build($receipt, [], [])['receipt']['unapplied_minor']);
    }

    public static function unsafeDecimals(): array
    {
        return [[1.25], [1], ['1e2'], ['01.00'], ['-1'], ['1.001'], ['9223372036854775808.00']];
    }

    #[DataProvider('unsafeDecimals')]
    public function testNeverReconstructsOrRoundsUnsafeAmounts(mixed $amount): void
    {
        [$receipt] = $this->facts();
        $receipt['amount'] = $amount;
        $this->expectException(InvalidArgumentException::class);
        (new ReceiptAllocationProjection())->build($receipt, [], []);
    }

    public static function badVersions(): array { return [[null], [0], [-1], ['1780000000'], [1780000000.0]]; }

    #[DataProvider('badVersions')]
    public function testInvalidReceiptTimestampFailsClosed(mixed $version): void
    {
        [$receipt, $allocations, $unpaid] = $this->facts();
        $receipt['source_version'] = $version;
        $this->expectException(InvalidArgumentException::class);
        (new ReceiptAllocationProjection())->build($receipt, $allocations, $unpaid);
    }

    public static function invalidCases(): array
    {
        return [['refund'], ['applied_over_amount'], ['unreconciled'], ['duplicate_allocation'], ['cross_company'], ['cross_client'], ['cross_currency'], ['cross_precision'], ['duplicate_invoice'], ['zero_unpaid'], ['invalid_invoice_version'], ['invalid_allocation_version'], ['invalid_allocated_invoice_version'], ['sum_overflow']];
    }

    #[DataProvider('invalidCases')]
    public function testInvalidNativeFactsReturnNoPartialProjection(string $case): void
    {
        [$receipt, $allocations, $unpaid] = $this->facts();
        switch ($case) {
            case 'refund': $receipt['refunded'] = '0.01'; break;
            case 'applied_over_amount': $receipt['applied'] = '10.01'; break;
            case 'unreconciled': $allocations[1]['amount'] = '3.49'; break;
            case 'duplicate_allocation': $allocations[1]['allocation_id'] = '1'; break;
            case 'cross_company': $allocations[0]['company_id'] = '18'; break;
            case 'cross_client': $allocations[0]['client_id'] = 'other'; break;
            case 'cross_currency': $allocations[0]['currency'] = 'EUR'; break;
            case 'cross_precision': $allocations[0]['precision'] = 4; break;
            case 'duplicate_invoice': $unpaid[] = $unpaid[0]; break;
            case 'zero_unpaid': $unpaid[0]['balance'] = '0'; break;
            case 'invalid_invoice_version': $unpaid[0]['source_version'] = 0; break;
            case 'invalid_allocation_version': $allocations[0]['source_version'] = '1780000000'; break;
            case 'invalid_allocated_invoice_version': $allocations[0]['invoice_source_version'] = null; break;
            case 'sum_overflow':
                $receipt['precision'] = 0; $receipt['amount'] = (string) PHP_INT_MAX; $receipt['applied'] = (string) PHP_INT_MAX;
                foreach ($allocations as &$allocation) { $allocation['precision'] = 0; $allocation['amount'] = (string) PHP_INT_MAX; $allocation['refunded'] = '0'; }
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        (new ReceiptAllocationProjection())->build($receipt, $allocations, $unpaid);
    }

    public function testProvenanceChangesWithoutClaimingCas(): void
    {
        [$receipt, $allocations, $unpaid] = $this->facts();
        $projection = new ReceiptAllocationProjection();
        $before = $projection->build($receipt, $allocations, $unpaid);
        $allocations[0]['source_version']++;
        self::assertNotSame($before['source_token'], $projection->build($receipt, $allocations, $unpaid)['source_token']);
        $unpaid[0]['balance'] = '2.26';
        self::assertNotSame($before['source_token'], $projection->build($receipt, $this->facts()[1], $unpaid)['source_token']);
    }
}
