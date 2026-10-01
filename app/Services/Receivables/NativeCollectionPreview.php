<?php

namespace App\Services\Receivables;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Read-only adapter. Never reuse ReminderJob or invoice store: both have effects. */
final class NativeCollectionPreview
{
    public const MAX_CANDIDATES = 1000;

    public function build(User $user, string $asOfDate, int $minimumOverdueDays): array
    {
        abort_unless(app()->environment(['local', 'testing'])
            && config('ninja.receivables_preview_enabled') === true, 403);
        $company = $user->company();
        abort_unless(!$company->is_disabled
            && ($user->isAdmin() || $user->hasPermission('view_reports')), 403);
        $companyId = (string) $user->companyId();
        abort_unless((string) $company->id === $companyId, 403);

        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL])
            ->where('balance', '>', 0)
            ->whereHas('client', function ($query) use ($company) {
                $query->where('company_id', $company->id)
                    ->where('is_deleted', false)->whereNull('deleted_at');
            })
            ->with('client')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES + 1)
            ->get();

        // No partial rows or totals are returned if the bounded query cannot cover its scope.
        abort_if($invoices->count() > self::MAX_CANDIDATES, 413, 'Preview scope exceeds bounded capacity; no totals returned.');
        $rows = [];
        $provenance = [];
        $precisions = [];
        $balance = new NativeBalance();
        $calendar = new NativeInvoiceDate();
        foreach ($invoices as $invoice) {
            if (!Gate::forUser($user)->allows('view', $invoice)) {
                continue;
            }
            if ((string) $invoice->company_id !== $companyId
                || (string) $invoice->client->company_id !== $companyId) {
                throw new InvalidArgumentException('Native scope mismatch.');
            }
            $currency = $invoice->client->currency();
            if (!$currency) {
                throw new InvalidArgumentException('Native currency unavailable.');
            }
            if (isset($precisions[$currency->code]) && $precisions[$currency->code] !== $currency->precision) {
                throw new InvalidArgumentException('Conflicting native precision for currency.');
            }
            $precisions[$currency->code] = $currency->precision;
            $id = $invoice->hashed_id;
            $reminders = $invoice->client->getSetting('send_reminders');
            if (!is_bool($reminders)) {
                throw new InvalidArgumentException('Native reminder setting is not an explicit boolean.');
            }
            $row = [
                'id' => $id,
                'company_id' => $companyId,
                'currency' => $currency->code,
                'balance_minor' => $balance->minor($invoice->getRawOriginal('balance'), $currency->precision),
                'due_date' => $calendar->calendar($invoice->getRawOriginal('due_date')),
                'version' => (int) $invoice->updated_at,
                'reminders_enabled' => $reminders,
                // No elected native source for these controls: do not imply verified absence.
                'disputed' => false,
                'canceled' => false, // query admits SENT/PARTIAL only
                'consent_revoked' => false,
                'promise_until' => null,
                'hold_context_verified' => false,
            ];
            $rows[] = $row;
            $provenance[$id] = [
                'currency_precision' => $currency->precision,
                'native_status_id' => $invoice->status_id,
                // Invoice identity is covered by the per-invoice Gate above; no client/contact PII.
                'invoice_number' => (string) $invoice->number,
                'source_token' => hash('sha256', json_encode([$row, (string) $invoice->number, $invoice->getRawOriginal('updated_at')], JSON_THROW_ON_ERROR)),
            ];
        }
        $result = (new CollectionPreview())->build($rows, $companyId, $asOfDate, $minimumOverdueDays);
        // The requested date classifies overdue days; balances are read NOW, never reconstructed.
        $result['balance_basis'] = 'current_native_balance_at_read_time';
        $result['as_of_date_semantics'] = 'overdue_classification_date_only_not_historical_receivables';
        $result['source_version_semantics'] = 'native_updated_at_timestamp_not_monotonic_revision';
        $result['source_token_semantics'] = 'content_provenance_only_not_cas_approval_or_dispatch_authority';
        $result['scope'] = 'current_actor_visible_active_sent_partial_positive_balance_invoices';
        $result['complete_within_scope'] = true;
        $result['is_point_in_time_snapshot'] = false;
        $result['generated_at'] = now()->toIso8601String();
        $result['hold_context'] = 'unverified_dispute_promise_recipient_consent';
        $result['native_reminder_schedule_evaluated'] = false;
        $result['due_date_semantics'] = 'native_business_calendar_date_ignoring_intraday_reminder_schedule';
        $result['provenance'] = $provenance;
        $result['currency_precisions'] = $precisions;
        return $result;
    }
}
