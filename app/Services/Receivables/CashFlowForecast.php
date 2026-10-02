<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use DateTimeImmutable;
use InvalidArgumentException;

/** Deterministic scenario model; assumptions never become payment or ledger authority. */
final class CashFlowForecast
{
    public function build(array $facts, array $input): array
    {
        $this->keys($input, ['start_date','end_date','scenarios']);
        $start=$this->date($input['start_date']);$end=$this->date($input['end_date']);
        if ($end<$start || $start->diff($end)->days>365 || !is_array($input['scenarios'])
            || !array_is_list($input['scenarios']) || count($input['scenarios'])<1 || count($input['scenarios'])>5) {
            throw new InvalidArgumentException('Bounded forecast horizon and one to five scenarios required.');
        }
        if (count($facts)>1000 || !array_is_list($facts)) {throw new InvalidArgumentException('Bounded native invoice snapshot required.');}
        $seen=[];$currencies=[];
        foreach ($facts as $row) {
            $this->keys($row,['invoice_id','currency','precision','balance_minor','due_date','source_token']);
            if (!is_string($row['invoice_id']) || !preg_match('/^[A-Za-z0-9]{1,128}$/D',$row['invoice_id'])
                || isset($seen[$row['invoice_id']]) || !is_string($row['source_token']) || !preg_match('/^[a-f0-9]{64}$/D',$row['source_token'])) {
                throw new InvalidArgumentException('Unique native invoice and content provenance required.');
            }
            $seen[$row['invoice_id']]=true;$this->currency($row['currency']);$this->amount($row['balance_minor']);
            if (!is_int($row['precision']) || $row['precision']<0 || $row['precision']>6
                || (isset($currencies[$row['currency']]) && $currencies[$row['currency']]!==$row['precision'])) {
                throw new InvalidArgumentException('Native currency precision conflict.');
            }
            $currencies[$row['currency']]=$row['precision'];
            if ($row['due_date']!==null) {$this->date($row['due_date']);}
        }
        if (count($currencies)>16) {throw new InvalidArgumentException('Too many independent currency buckets.');}
        usort($facts,fn($a,$b)=>strcmp($a['invoice_id'],$b['invoice_id']));
        $versions=[];$reports=[];
        foreach ($input['scenarios'] as $scenario) {
            $this->keys($scenario,['version','collection_basis_points','delay_days','opening_minor_by_currency','planned_outflows']);
            if (!is_string($scenario['version']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$scenario['version']) || isset($versions[$scenario['version']])
                || !is_int($scenario['collection_basis_points']) || $scenario['collection_basis_points']<0 || $scenario['collection_basis_points']>10000
                || !is_int($scenario['delay_days']) || $scenario['delay_days']<0 || $scenario['delay_days']>365
                || !is_array($scenario['opening_minor_by_currency']) || ($scenario['opening_minor_by_currency']!==[] && array_is_list($scenario['opening_minor_by_currency']))
                || count($scenario['opening_minor_by_currency'])>16 || !is_array($scenario['planned_outflows'])
                || !array_is_list($scenario['planned_outflows']) || count($scenario['planned_outflows'])>1000) {
                throw new InvalidArgumentException('Invalid or duplicate scenario assumptions.');
            }
            $versions[$scenario['version']]=true;$daily=[];$excluded=[];$included=[];$scopeCurrencies=$currencies;
            foreach ($scenario['opening_minor_by_currency'] as $currency=>$amount) {$this->currency($currency);$this->amount($amount,true);if(!isset($scopeCurrencies[$currency]))throw new InvalidArgumentException('Opening balance requires native currency precision.');}
            foreach ($facts as $row) {
                $amount=$this->amount($row['balance_minor']);
                if ($row['due_date']===null) {$excluded[]=['invoice_id'=>$row['invoice_id'],'currency'=>$row['currency'],'balance_minor'=>$row['balance_minor'],'reason'=>'due_date_required'];continue;}
                $date=max($start,$this->date($row['due_date']))->modify('+'.$scenario['delay_days'].' days');
                if ($date>$end) {$excluded[]=['invoice_id'=>$row['invoice_id'],'currency'=>$row['currency'],'balance_minor'=>$row['balance_minor'],'reason'=>'outside_horizon'];continue;}
                // Quotient/remainder arithmetic avoids overflowing amount * 10000.
                $bps=$scenario['collection_basis_points'];$weighted=intdiv($amount,10000)*$bps+intdiv(($amount%10000)*$bps+5000,10000);
                $included[]=[...$row,'expected_date'=>$date->format('Y-m-d'),'expected_minor'=>(string)$weighted];
                $day=$date->format('Y-m-d');$currency=$row['currency'];
                $daily[$day][$currency]['in']=$this->add($daily[$day][$currency]['in']??0,$weighted);
            }
            foreach ($scenario['planned_outflows'] as $outflow) {
                $this->keys($outflow,['date','currency','amount_minor']);$date=$this->date($outflow['date']);$this->currency($outflow['currency']);
                if ($date<$start || $date>$end || !isset($scopeCurrencies[$outflow['currency']])) {throw new InvalidArgumentException('Outflow requires known native currency and a date inside the horizon.');}
                $day=$date->format('Y-m-d');$currency=$outflow['currency'];
                $daily[$day][$currency]['out']=$this->add($daily[$day][$currency]['out']??0,$this->amount($outflow['amount_minor']));
            }
            ksort($scopeCurrencies);$balances=[];$series=[];$totals=[];
            foreach ($scopeCurrencies as $currency=>$precision) {$balances[$currency]=$this->amount($scenario['opening_minor_by_currency'][$currency]??'0',true);$totals[$currency]=['inflow_minor'=>'0','outflow_minor'=>'0','minimum_balance_minor'=>(string)$balances[$currency]];}
            for ($date=$start;$date<=$end;$date=$date->modify('+1 day')) {
                $day=$date->format('Y-m-d');
                foreach ($scopeCurrencies as $currency=>$precision) {
                    $in=$daily[$day][$currency]['in']??0;$out=$daily[$day][$currency]['out']??0;
                    $balances[$currency]=$this->add($this->add($balances[$currency],$in),-$out);
                    $totals[$currency]['inflow_minor']=(string)$this->add((int)$totals[$currency]['inflow_minor'],$in);
                    $totals[$currency]['outflow_minor']=(string)$this->add((int)$totals[$currency]['outflow_minor'],$out);
                    $totals[$currency]['minimum_balance_minor']=(string)min((int)$totals[$currency]['minimum_balance_minor'],$balances[$currency]);
                    $series[]=['date'=>$day,'currency'=>$currency,'inflow_minor'=>(string)$in,'outflow_minor'=>(string)$out,'closing_minor'=>(string)$balances[$currency]];
                }
            }
            foreach ($totals as $currency=>&$total) {$total['closing_minor']=(string)$balances[$currency];}unset($total);
            $reports[]=['version'=>$scenario['version'],'assumptions_sha256'=>hash('sha256',json_encode($scenario,JSON_THROW_ON_ERROR)),'assumptions'=>$scenario,'series'=>$series,'totals_by_currency'=>$totals,'included'=>$included,'excluded'=>$excluded];
        }
        ksort($currencies);
        return ['preview_only'=>true,'write_authority'=>'NONE','forecast_model'=>'open_receivables_scenarios_v1','start_date'=>$input['start_date'],'end_date'=>$input['end_date'],
            'currency_precisions'=>$currencies,'native_invoice_count'=>count($facts),'source_snapshot_sha256'=>hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR)),
            'assumptions_semantics'=>'user_supplied_opening_cash_collection_probability_delay_and_planned_outflows_not_native_cash_or_payment_predictions',
            'rounding'=>'weighted_minor_units_half_up','fx_conversion'=>false,'scenarios'=>$reports];
    }

    private function keys(mixed $value,array $keys): void {if(!is_array($value)){throw new InvalidArgumentException('Object required.');}$actual=array_keys($value);sort($actual);sort($keys);if($actual!==$keys){throw new InvalidArgumentException('Unexpected or missing forecast fields.');}}
    private function currency(mixed $value): void {if(!is_string($value)||!preg_match('/^[A-Z]{3}$/D',$value)){throw new InvalidArgumentException('Currency required.');}}
    private function date(mixed $value): DateTimeImmutable {$date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;if(!$date||$date->format('Y-m-d')!==$value){throw new InvalidArgumentException('Calendar date required.');}return $date;}
    private function amount(mixed $value,bool $signed=false): int {if(!is_string($value)||!preg_match($signed?'/^(0|-?[1-9][0-9]*)$/D':'/^(0|[1-9][0-9]*)$/D',$value)){throw new InvalidArgumentException('Exact canonical minor string required.');}$absolute=ltrim($value,'-');$max=(string)PHP_INT_MAX;if(strlen($absolute)>strlen($max)||(strlen($absolute)===strlen($max)&&strcmp($absolute,$max)>0)){throw new InvalidArgumentException('Forecast amount overflow.');}return (int)$value;}
    private function add(int $a,int $b): int {if(($b>0&&$a>PHP_INT_MAX-$b)||($b<0&&$a<-PHP_INT_MAX-$b)){throw new InvalidArgumentException('Forecast control total overflow.');}return $a+$b;}
}
