<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\Receivables\ExactAllocationAmounts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
final class ExactAllocationAmountsTest extends TestCase
{
    private function facts(): array { return ['payment_amount'=>'10.000000','payment_applied'=>'9.500000','payment_refunded'=>'0.000000','invoice_balance'=>'4.000000','invoice_paid_to_date'=>'0.000000','client_balance'=>'6.250000','ledger_balance'=>'6.250000','allocations_sum_minor'=>'950']; }
    public function testExistingReceiptPartialAllocationUsesOnlyExactDecimalText(): void {
        $p=(new ExactAllocationAmounts())->plan($this->facts(),'50');
        $this->assertSame('10.000000',$p['payment_applied']);$this->assertSame('0',$p['payment_unapplied_minor']);$this->assertSame('3.500000',$p['invoice_balance']);$this->assertSame('0.500000',$p['invoice_paid_to_date']);$this->assertSame('5.750000',$p['client_balance']);$this->assertSame('-0.500000',$p['ledger_adjustment']);$this->assertSame('5.750000',$p['ledger_balance']);$this->assertSame('0.5000',$p['paymentable_amount']);
    }
    public function testAOneCentAllocationDoesNotRoundBinaryFloats(): void {$f=$this->facts();$f['payment_amount']='10.010000';$f['payment_applied']='9.990000';$f['allocations_sum_minor']='999';$this->assertSame('10.000000',(new ExactAllocationAmounts())->plan($f,'1')['payment_applied']);}
    public function testSignedOperationalLedgerIsRepresentedExactly(): void {$f=$this->facts();$f['ledger_balance']='-0.010000';$this->assertSame('-0.510000',(new ExactAllocationAmounts())->plan($f,'50')['ledger_balance']);}
    public static function invalid(): array {return [['amount',0],['amount',0.5],['amount','0'],['amount','01'],['amount','1e2'],['amount','100000000000000'],['payment_amount',10.0],['payment_amount','10.001000'],['payment_refunded','0.010000'],['allocations_sum_minor','949'],['invoice_balance','0.500000'],['client_balance','0.490000'],['invoice_paid_to_date','99999999999999.990000'],['ledger_balance','-99999999999999.990000']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testUnsafeMoneyAndCurrentFactsAreRejected(string $key,mixed $value): void {$f=$this->facts();$amount='50';if($key==='amount')$amount=$value;else $f[$key]=$value;$this->expectException(InvalidArgumentException::class);(new ExactAllocationAmounts())->plan($f,$amount);}
}
