<?php

namespace App\Services\Receivables;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Paymentable;
use App\Models\User;
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class NativeReceiptAllocationPreview
{
    use MakesHash;

    public const MAX_CANDIDATES = 1000;

    public function build(User $user, string $paymentId): array
    {
        abort_unless(app()->environment(['local', 'testing'])
            && config('ninja.remittance_preview_enabled') === true, 403);
        $company = $user->company();
        abort_unless(!$company->is_disabled
            && ($user->isAdmin() || $user->hasPermission('view_reports'))
            && (string) $company->id === (string) $user->companyId(), 403);
        $decoded = $this->decodePrimaryKey($paymentId, true);
        abort_unless(is_int($decoded) && $decoded > 0, 422);
        $payment = Payment::query()->where('company_id', $company->id)
            ->where('is_deleted', false)->whereNull('deleted_at')
            ->with(['client', 'currency'])->find($decoded);
        abort_unless($payment && Gate::forUser($user)->allows('view', $payment), 403);
        $client = $payment->client;
        abort_unless($client && (string) $client->company_id === (string) $company->id
            && !$client->is_deleted && $client->deleted_at === null
            && Gate::forUser($user)->allows('view', $client), 403);
        $currency = $payment->currency;
        $clientCurrency = $client->currency();
        if (!$currency || !$clientCurrency || (string) $currency->id !== (string) $clientCurrency->id
            || ($payment->exchange_currency_id !== null && (string) $payment->exchange_currency_id !== (string) $currency->id)
            || (new NativeBalance())->minor($payment->getRawOriginal('exchange_rate'), 6) !== '1000000'
            || $payment->status_id !== Payment::STATUS_COMPLETED) {
            throw new InvalidArgumentException('Native receipt currency or state requires manual review.');
        }
        $scope = [
            'company_id' => (string) $company->id,
            'client_id' => $client->hashed_id,
            'currency' => $currency->code,
            'precision' => $currency->precision,
        ];
        $receipt = $scope + [
            'id' => $payment->hashed_id,
            'amount' => $payment->getRawOriginal('amount'),
            'applied' => $payment->getRawOriginal('applied'),
            'refunded' => $payment->getRawOriginal('refunded'),
            'source_version' => (int) $payment->updated_at,
        ];
        $pivots = Paymentable::query()->where('payment_id', $payment->id)
            ->orderBy('id')->limit(self::MAX_CANDIDATES + 1)->get();
        abort_if($pivots->count() > self::MAX_CANDIDATES, 413);
        $allocations = [];
        foreach ($pivots as $pivot) {
            if ($pivot->paymentable_type !== 'invoices') {
                throw new InvalidArgumentException('Credit or other native allocation requires manual review.');
            }
            $invoice = Invoice::withTrashed()->find($pivot->paymentable_id);
            // Never publish a receipt total with hidden invoice allocations.
            abort_unless($invoice && Gate::forUser($user)->allows('view', $invoice), 403);
            $facts = $this->invoiceFacts($invoice, $scope);
            $invoiceVersion = $facts['source_version'];
            unset($facts['source_version']);
            $allocations[] = $facts + [
                'allocation_id' => (string) $pivot->id,
                'amount' => $pivot->getRawOriginal('amount'),
                'refunded' => $pivot->getRawOriginal('refunded'),
                'source_version' => (int) $pivot->updated_at,
                'invoice_source_version' => $invoiceVersion,
            ];
        }
        $invoices = Invoice::query()->where('company_id', $company->id)
            ->where('client_id', $client->id)->where('is_deleted', false)->whereNull('deleted_at')
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
            ->where('balance', '>', 0)->with('client')->orderBy('id')
            ->limit(self::MAX_CANDIDATES + 1)->get();
        abort_if($invoices->count() > self::MAX_CANDIDATES, 413);
        $unpaid = [];
        foreach ($invoices as $invoice) {
            if (Gate::forUser($user)->allows('view', $invoice)) {
                $unpaid[] = $this->invoiceFacts($invoice, $scope)
                    + ['balance' => $invoice->getRawOriginal('balance')];
            }
        }
        $result = (new ReceiptAllocationProjection())->build($receipt, $allocations, $unpaid);
        $result['scope'] = 'authorized_receipt_all_active_invoice_allocations_and_visible_same_client_sent_partial_unpaid_invoices';
        $result['native_principal_id'] = $user->hashed_id;
        $result['generated_at'] = now()->toIso8601String();
        return $result;
    }

    private function invoiceFacts(Invoice $invoice, array $scope): array
    {
        $client = $invoice->client;
        $currency = $client?->currency();
        if (!$client || (string) $invoice->company_id !== $scope['company_id']
            || (string) $client->company_id !== $scope['company_id']
            || $client->hashed_id !== $scope['client_id'] || !$currency
            || $currency->code !== $scope['currency'] || $currency->precision !== $scope['precision']) {
            throw new InvalidArgumentException('Native related invoice scope requires manual review.');
        }
        return $scope + [
            'invoice_id' => $invoice->hashed_id,
            'invoice_number' => (string) $invoice->number,
            'source_version' => (int) $invoice->updated_at,
            'native_status_id' => $invoice->status_id,
        ];
    }
}
