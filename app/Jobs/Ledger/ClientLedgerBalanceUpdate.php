<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Jobs\Ledger;

use App\Libraries\MultiDB;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ClientLedgerBalanceUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    private ?CompanyLedger $next_balance_record;

    public function __construct(public Company $company, public Client $client) {}

    /**
     * Execute the job.
     *
     *
     * @return void
     */
    public function handle(): void
    {

        MultiDB::setDb($this->company->db);

        CompanyLedger::query()
                        ->whereNull('balance')
                        ->where('client_id', $this->client->id)
                        ->orderBy('id', 'ASC')
                        ->get()
                        ->each(function ($company_ledger) {

                            $parent_ledger = CompanyLedger::query()
                                                    ->where('id', '<', $company_ledger->id)
                                                    ->where('client_id', $company_ledger->client_id)
                                                    ->where('company_id', $company_ledger->company_id)
                                                    ->whereNotNull('balance')
                                                    ->orderBy('id', 'DESC')
                                                    ->first();

                            $company_ledger->balance = ($parent_ledger ? $parent_ledger->balance : 0) + $company_ledger->adjustment;
                            $company_ledger->save();

                        });

    }

    public function middleware()
    {
        return [(new WithoutOverlapping($this->client->client_hash))->dontRelease()];
    }

    /** No queue/float math: reconcile only this transaction's newly created operational row. */
    public function handleExactAllocation(CompanyLedger $row, string $expectedBalance): void
    {
        if ((string) $row->company_id !== (string) $this->company->id
            || (string) $row->client_id !== (string) $this->client->id || $row->getRawOriginal('balance') !== null) {
            throw new \InvalidArgumentException('Native ledger allocation scope changed.');
        }
        $pending = CompanyLedger::query()->where('company_id', $this->company->id)
            ->where('client_id', $this->client->id)->whereNull('balance')->lockForUpdate()->get();
        if ($pending->count() !== 1 || $pending->first()->id !== $row->id) {
            throw new \InvalidArgumentException('Earlier native ledger reconciliation is pending.');
        }
        $parent = CompanyLedger::query()->where('company_id', $this->company->id)
            ->where('client_id', $this->client->id)->where('id', '<', $row->id)
            ->orderByDesc('id')->lockForUpdate()->first();
        $money = new \App\Services\Receivables\ExactAllocationAmounts();
        $minor = $money->signed($parent?->getRawOriginal('balance') ?? '0.000000')
            + $money->signed($row->getRawOriginal('adjustment'));
        $actual = $money->decimal($minor);
        if ($actual !== $expectedBalance) throw new \InvalidArgumentException('Native ledger beforeimage changed.');
        $row->balance = $actual;
        $row->save();
    }
}
