<?php

declare(strict_types=1);
require __DIR__.'/../../app/Services/Receivables/CollectionPreview.php';
use App\Services\Receivables\CollectionPreview;

$preview = new CollectionPreview();
$checks = 0;
function equal(mixed $actual, mixed $expected): void {
    global $checks;
    ++$checks;
    if ($actual !== $expected) { throw new RuntimeException('Fixture mismatch: '.var_export([$actual,$expected], true)); }
}
function rejects(callable $run): void {
    global $checks;
    ++$checks;
    try { $run(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid snapshot accepted.');
}
function invoice(string $id, string $balance, ?string $due, array $override = []): array {
    return array_replace(['id'=>$id,'company_id'=>'company-a','currency'=>'SAR','balance_minor'=>$balance,'due_date'=>$due,'version'=>1,'reminders_enabled'=>true,'disputed'=>false,'canceled'=>false,'consent_revoked'=>false,'promise_until'=>null], $override);
}
$rows = [invoice('partial','7500','2026-08-01'), invoice('paid','0','2026-07-01'), invoice('dispute','2500','2026-09-01',['disputed'=>true]),invoice('promise','1000','2026-09-01',['promise_until'=>'2026-10-01']), invoice('optout','2000','2026-09-01',['reminders_enabled'=>false]),invoice('future','3000','2026-10-02'),invoice('usd','99','2026-09-01',['currency'=>'USD']),invoice('unknown','500',null)];
$r=$preview->build($rows,'company-a','2026-10-01',1);
equal($r['totals_minor_by_currency'],['SAR'=>'16500','USD'=>'99']);
$states=array_column($r['rows'],'state','invoice_id');
equal($states,['paid'=>'settled','partial'=>'manual_review_required','dispute'=>'dispute_hold','optout'=>'reminders_disabled','promise'=>'promise_hold','usd'=>'manual_review_required','future'=>'not_eligible','unknown'=>'due_date_required']);
equal(array_unique(array_column($r['rows'],'dispatch_allowed')),[false]);
// Payment before the next preview suppresses collection, without mutating facts.
$changed=[invoice('partial','0','2026-08-01',['version'=>2])];
equal($preview->build($changed,'company-a','2026-10-01',1)['rows'][0]['state'],'settled');
equal($preview->build($rows,'company-a','2026-10-01',1),$r);
equal($preview->build([],'company-a','2026-10-01',1)['totals_minor_by_currency'],[]);
rejects(fn()=>$preview->build([invoice('wrong','1','2026-09-01',['company_id'=>'company-b'])],'company-a','2026-10-01',1));
rejects(fn()=>$preview->build([invoice('repeat','1','2026-09-01'),invoice('repeat','1','2026-09-01')],'company-a','2026-10-01',1));
foreach (['-1','1.5','01','1e2',1.1,'9223372036854775808'] as $bad) { rejects(fn()=>$preview->build([invoice('bad','1','2026-09-01',['balance_minor'=>$bad])],'company-a','2026-10-01',1)); }
rejects(fn()=>$preview->build([invoice('a',(string)PHP_INT_MAX,'2026-09-01'),invoice('b','1','2026-09-01')],'company-a','2026-10-01',1));
rejects(fn()=>$preview->build([invoice('bad','1','2026-02-30')],'company-a','2026-10-01',1));
rejects(fn()=>$preview->build([invoice('bad','1','2026-09-01',['disputed'=>'false'])],'company-a','2026-10-01',1));
rejects(fn()=>$preview->build([invoice('bad','1','2026-09-01',['version'=>0])],'company-a','2026-10-01',1));
rejects(fn()=>$preview->build([],'company-a','2026-10-01',0));
rejects(fn()=>$preview->build([],'company-a','2026-02-30',1));
equal($preview->build([invoice('day','1','2026-09-30')],'company-a','2026-10-01',1)['rows'][0]['overdue_days'],1);
equal($preview->build([invoice('canceled','100','2026-09-01',['canceled'=>true])],'company-a','2026-10-01',1)['rows'][0]['state'],'canceled');
equal($preview->build([invoice('revoked','100','2026-09-01',['consent_revoked'=>true])],'company-a','2026-10-01',1)['rows'][0]['state'],'consent_revoked');
echo "PASS $checks deterministic finance fixture checks; no dispatch or persistence.\n";

