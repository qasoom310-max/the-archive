<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Limousine\Models\LimoBulkInvoice;
use Modules\Limousine\Models\LimoInvoice;

/**
 * Making, paying and cancelling a bulk invoice.
 *
 * The one rule that matters: an invoice is in at most one bulk invoice. It is
 * checked here before anything is written and again by the write itself (only
 * rows still free are claimed, and the count must match), so two people
 * pressing the button at the same moment cannot both get the same invoice.
 */
final class BulkInvoices
{
    public function __construct(private readonly AccountPayment $payments) {}

    /**
     * @param  list<int>  $invoiceIds
     */
    public function create(array $invoiceIds, ?string $from = null, ?string $to = null, ?int $userId = null): LimoBulkInvoice
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));

        $invoices = LimoInvoice::query()->whereIn('id', $ids)->get();

        $first = $invoices->first();
        if ($first === null) {
            $this->refuse(__('Tick the invoices to put in the bulk invoice first.'));
        }

        $customerId = $first->customer_id;
        if ($invoices->pluck('customer_id')->unique()->count() > 1 || $customerId === null) {
            $this->refuse(__('A bulk invoice is for one customer. Tick invoices of one customer only.'));
        }

        $taken = $invoices->filter(static fn (LimoInvoice $i): bool => $i->bulk_invoice_id !== null);
        if ($taken->isNotEmpty()) {
            $this->refuse(__('Already in another bulk invoice: :list', [
                'list' => $taken->pluck('reference')->take(10)->implode(', '),
            ]));
        }

        return DB::transaction(function () use ($invoices, $customerId, $from, $to, $userId): LimoBulkInvoice {
            $bulk = LimoBulkInvoice::query()->create([
                'customer_id' => $customerId,
                'issue_date' => Carbon::now()->toDateString(),
                'period_from' => $this->day($from),
                'period_to' => $this->day($to),
                'created_by_user_id' => $userId,
            ]);

            // Claim only invoices that are still free. Fewer rows than asked
            // for means someone else took one in the meantime: undo it all.
            $claimed = LimoInvoice::query()
                ->whereIn('id', $invoices->modelKeys())
                ->whereNull('bulk_invoice_id')
                ->update(['bulk_invoice_id' => $bulk->id]);

            if ($claimed !== $invoices->count()) {
                $this->refuse(__('One of these invoices was just put in another bulk invoice. Refresh and try again.'));
            }

            return $bulk->refresh();
        });
    }

    /**
     * The invoices of a bulk invoice that money can be taken against, the
     * oldest work first.
     *
     * @return Collection<int, LimoInvoice>
     */
    public function payable(LimoBulkInvoice $bulk): Collection
    {
        return $bulk->invoices()
            ->with('booking')
            ->orderByRaw('COALESCE(service_date, issue_date)')
            ->orderBy('id')
            ->get()
            ->filter(static fn (LimoInvoice $i): bool => ! $i->isAwaitingTrip() && $i->balance() > 0.0005)
            ->values();
    }

    /** The most that can be received against it right now. */
    public function payableBalance(LimoBulkInvoice $bulk): float
    {
        return round((float) $this->payable($bulk)->sum(static fn (LimoInvoice $i): float => $i->balance()), 3);
    }

    /**
     * One payment for the whole bulk invoice, shared over its invoices oldest
     * first. Each invoice still gets its own receipt, as with any payment.
     *
     * @return array{allocated: float, receipts: int, unallocated: float}
     */
    public function pay(LimoBulkInvoice $bulk, float $amount, string $method = 'cash', ?string $note = null): array
    {
        if ($bulk->isCancelled()) {
            $this->refuse(__('This bulk invoice was cancelled.'));
        }

        $amount = round($amount, 3);
        if ($amount > $this->payableBalance($bulk) + 0.0005) {
            $this->refuse(__('That is more than what is left to pay on this bulk invoice.'));
        }

        $label = (string) $bulk->reference;
        $note = $note !== null && trim($note) !== '' ? $label . ' · ' . trim($note) : $label;

        return $this->payments->apply($this->payments->planOver($this->payable($bulk), $amount), $amount, $method, $note);
    }

    /**
     * Cancel it and free its invoices, so they can go in a new one. The
     * invoices and any money already received on them are not touched.
     */
    public function cancel(LimoBulkInvoice $bulk, ?int $userId = null): void
    {
        if ($bulk->isCancelled()) {
            return;
        }

        DB::transaction(function () use ($bulk, $userId): void {
            LimoInvoice::query()->where('bulk_invoice_id', $bulk->id)->update(['bulk_invoice_id' => null]);

            $bulk->cancelled_at = Carbon::now();
            $bulk->cancelled_by_user_id = $userId;
            $bulk->save();
        });
    }

    private function day(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['bulk' => $message]);
    }
}
