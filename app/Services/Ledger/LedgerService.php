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

namespace App\Services\Ledger;

use App\Factory\CompanyLedgerFactory;
use App\Jobs\Ledger\ClientLedgerBalanceUpdate;
use App\Models\Activity;
use App\Models\CompanyLedger;

class LedgerService
{
    private $entity;

    public function __construct($entity)
    {
        $this->entity = $entity;
    }

    public function insertInvoiceBalance($adjustment, $balance, $notes)
    {
        $company_ledger = CompanyLedgerFactory::create($this->entity->company_id, $this->entity->user_id);
        $company_ledger->client_id = $this->entity->client_id;
        $company_ledger->adjustment = $adjustment;
        $company_ledger->notes = $notes;
        $company_ledger->balance = $balance;
        $company_ledger->activity_id = Activity::UPDATE_INVOICE;

        $this->entity->company_ledger()->save($company_ledger);

        return $this;

    }

    public function updateInvoiceBalance($adjustment, $notes = '')
    {

        if ($adjustment == 0) {
            return $this;
        }

        $company_ledger = CompanyLedgerFactory::create($this->entity->company_id, $this->entity->user_id);
        $company_ledger->client_id = $this->entity->client_id;
        $company_ledger->adjustment = $adjustment;
        $company_ledger->notes = $notes;
        $company_ledger->activity_id = Activity::UPDATE_INVOICE;

        $this->entity->company_ledger()->save($company_ledger);

        ClientLedgerBalanceUpdate::dispatch($this->entity->company, $this->entity->client);

        return $this;
    }

    public function updatePaymentBalance($adjustment, $notes = '')
    {
        $company_ledger = CompanyLedgerFactory::create($this->entity->company_id, $this->entity->user_id);
        $company_ledger->client_id = $this->entity->client_id;
        $company_ledger->adjustment = $adjustment;
        $company_ledger->activity_id = Activity::UPDATE_PAYMENT;
        $company_ledger->notes = $notes;

        $this->entity->company_ledger()->save($company_ledger);

        ClientLedgerBalanceUpdate::dispatch($this->entity->company, $this->entity->client);

        return $this;
    }

    /** Native operational ledger effect, reconciled exactly in the allocation's DB transaction. */
    public function updatePaymentBalanceExactPartial(string $adjustment, string $expectedBalance): self
    {
        $money = new \App\Services\Receivables\ExactAllocationAmounts();
        if ($money->signed($adjustment) >= 0) throw new \InvalidArgumentException('Allocation ledger adjustment must be negative.');
        $row = CompanyLedgerFactory::create($this->entity->company_id, $this->entity->user_id);
        $row->client_id = $this->entity->client_id;
        $row->adjustment = $adjustment;
        $row->activity_id = Activity::UPDATE_PAYMENT;
        $row->notes = 'ExactPartialReceiptAllocation';
        $this->entity->company_ledger()->save($row);
        (new ClientLedgerBalanceUpdate($this->entity->company, $this->entity->client))
            ->handleExactAllocation($row, $expectedBalance);
        return $this;
    }

    public function updateCreditBalance($adjustment, $notes = '')
    {
        $company_ledger = CompanyLedgerFactory::create($this->entity->company_id, $this->entity->user_id);
        $company_ledger->client_id = $this->entity->client_id;
        $company_ledger->adjustment = $adjustment;
        $company_ledger->notes = $notes;
        $company_ledger->activity_id = Activity::UPDATE_CREDIT;

        $this->entity->company_ledger()->save($company_ledger);

        ClientLedgerBalanceUpdate::dispatch($this->entity->company, $this->entity->client);

        return $this;
    }

    public function save()
    {
        $this->entity->save();

        return $this->entity;
    }
}
