<?php

declare(strict_types=1);
require __DIR__.'/../../app/Services/Receivables/NativeBalance.php';
require __DIR__.'/../../app/Services/Receivables/CollectionPreview.php';
require __DIR__.'/../../app/Services/Receivables/NativeInvoiceDate.php';
use App\Services\Receivables\NativeInvoiceDate;
use App\Services\Receivables\NativeBalance;
use App\Services\Receivables\CollectionPreview;

$normalizer = new NativeBalance();
$checks = 0;
function check(mixed $actual, mixed $expected): void {
    global $checks;
    ++$checks;
    if ($actual !== $expected) { throw new RuntimeException('Unexpected normalization.'); }
}
function refuses(callable $run): void {
    global $checks;
    ++$checks;
    try { $run(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Unsafe native representation accepted.');
}
foreach ([['123.450000',2,'12345'],['0.000000',2,'0'],['1.230000',6,'1230000'],['1.000000',0,'1'],['0.000001',6,'1'],['9223372036854.775807',6,(string)PHP_INT_MAX]] as [$amount,$precision,$expected]) {
    check($normalizer->minor($amount,$precision),$expected);
}
foreach (['1.001000','-1.00','01.00','1e2',' 1.00','1.',1.23,null,'9223372036854775808.00'] as $amount) {
    refuses(fn()=>$normalizer->minor($amount,2));
}
foreach ([-1,7,'2',null] as $precision) { refuses(fn()=>$normalizer->minor('1.00',$precision)); }
$calendar = new NativeInvoiceDate();
foreach ([null,'2026-10-01','2026-10-01 23:59:59','2026-10-01 00:00:00.000001'] as $date) { check($calendar->calendar($date),$date === null ? null : '2026-10-01'); }
foreach (['2026-02-30 00:00:00','2026-10-01 24:00:00','2026-10-01T00:00:00Z',0,''] as $date) { refuses(fn()=>$calendar->calendar($date)); }
$invoice=['id'=>'a','company_id'=>'company-a','currency'=>'SAR','balance_minor'=>'100','due_date'=>'2026-09-01','version'=>1,'reminders_enabled'=>true,'disputed'=>false,'canceled'=>false,'consent_revoked'=>false,'promise_until'=>null,'hold_context_verified'=>false];
$preview=new CollectionPreview();
$result=$preview->build([$invoice],'company-a','2026-10-01',1);
check($result['rows'][0]['state'],'hold_context_unverified');
check($result['rows'][0]['dispatch_allowed'],false);
check($preview->build([$invoice],'company-a','2020-01-01',1)['open_receivables_minor_by_currency'], $preview->build([$invoice],'company-a','2030-01-01',1)['open_receivables_minor_by_currency']);
$invoice['reminders_enabled']=false;
check($preview->build([$invoice],'company-a','2026-10-01',1)['rows'][0]['state'],'reminders_disabled');
$invoice['hold_context_verified']='false';
refuses(fn()=>$preview->build([$invoice],'company-a','2026-10-01',1));
echo "PASS $checks exact native-decimal/unknown-hold fixture checks; no framework/DB acceptance.\n";
