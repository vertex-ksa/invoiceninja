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

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AbstractService;
use App\Utils\BcMath;

class ApplyPayment extends AbstractService
{
    public function __construct(private Invoice $invoice, private Payment $payment, private float $payment_amount) {}

    /** Explicit exact branch for the separately guarded ordinary, non-deposit partial operation. */
    public static function exactPartial(Invoice $invoice, Payment $payment, array $plan): Invoice
    {
        if (!in_array((int) $invoice->status_id, [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL], true)
            || $invoice->hasPartial() || $invoice->is_proforma) {
            throw new \InvalidArgumentException('Only an ordinary active partial allocation is supported.');
        }
        $invoice->status_id = Invoice::STATUS_PARTIAL;
        $invoice->balance = $plan['invoice_balance'];
        $invoice->paid_to_date = $plan['invoice_paid_to_date'];
        // Preserve the existing updateBalance rule without a decimal-to-float cast.
        if ((new \App\Services\Receivables\ExactAllocationAmounts())->unsigned($plan['invoice_balance']) < 100) {
            $invoice->next_send_date = null;
        }
        // Same existing native persistence semantics; paid/partial-deposit workflows are excluded.
        $invoice->service()->save();
        $invoice->client->service()->reduceBalanceExactPartial($plan['client_balance']);
        $payment->ledger()->updatePaymentBalanceExactPartial($plan['ledger_adjustment'], $plan['ledger_balance']);
        return $invoice->fresh();
    }

    /**
     * Apply a payment to a single invoice.
     *
     */
    public function run()
    {
        $this->invoice = $this->invoice->fresh('client');

        $amount_paid = 0;

        $had_partial = $this->invoice->hasPartial();

        if ($had_partial && BcMath::lessThan($this->payment_amount, $this->invoice->partial)) {
            // --- Stage 1: underpaying the requested deposit ---------------
            $amount_paid = $this->payment_amount * -1;

            $this->invoice
                 ->service()
                 ->updatePartial($amount_paid)
                 ->updateBalance($amount_paid)
                 ->updatePaidToDate($amount_paid * -1)
                 ->save();
        } else {
            // --- Stage 2: Paying the exact invoice balance ------------
            if (BcMath::equal($this->payment_amount, $this->invoice->balance)) {
                $amount_paid = $this->payment_amount * -1;
                $status = Invoice::STATUS_PAID;
            } 
            // --- Stage 3: Paying less than the invoice balance -------------
            elseif (BcMath::lessThan($this->payment_amount, $this->invoice->balance)) {
                $amount_paid = $this->payment_amount * -1;
                $status = Invoice::STATUS_PARTIAL;
            } else {
                // --- Stage 4: Overpayment — cap at invoice balance. The excess stays on
                $amount_paid = $this->invoice->balance * -1;
                $status = Invoice::STATUS_PAID;
            }

            // Preserve legacy behaviour: clearPartial() + setDueDate() are
            // only invoked when a partial was actually in play on entry. For
            // non-partial invoices the old code never touched these, so
            // neither do we — this keeps the refactor strictly minimal.
            $service = $this->invoice->service();

            if ($had_partial) {
                $service = $service->clearPartial()->setDueDate();
            }

            $service->setStatus($status)
                    ->updateBalance($amount_paid)
                    ->updatePaidToDate($amount_paid * -1)
                    ->save();
        }

        $this->invoice = $this->invoice->fresh();

        // Legacy behaviour: reminder state is re-evaluated only when the
        // invoice had a partial on entry (the old hasPartial block always
        // ended with checkReminderStatus; the non-partial block did not).
        if ($had_partial) {
            $this->invoice = $this->invoice->service()->checkReminderStatus()->save();
        }

        $this->payment
             ->ledger()
             ->updatePaymentBalance($amount_paid, "ApplyPaymentInvoice");

        $this->invoice
             ->client
             ->service()
             ->updateBalance($amount_paid)
             ->save();

        $this->invoice =$this->invoice
             ->service()
             ->applyNumber()
             ->workFlow()
             ->unlockDocuments()
             ->save();

        return $this->invoice;
    }
}
