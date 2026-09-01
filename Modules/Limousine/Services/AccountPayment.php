<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;

/**
 * One payment spread across everything a customer owes, oldest bill first.
 *
 * A company settles its account, not a trip: it hands over a round figure that
 * clears some bills entirely and part of the next. Taking that invoice by
 * invoice means the office doing the arithmetic by hand, and getting it wrong
 * quietly.
 *
 * OLDEST FIRST is the rule, and it is not arbitrary — money owed longest is
 * cleared first, so what is left outstanding is always the most recent work.
 * Applying it newest-first would leave an ancient unpaid bill sitting behind a
 * settled recent one, which is how a debt gets forgotten.
 *
 * Each allocation goes through {@see BookingPayments}, the same path the queue
 * uses, so every slice writes its own receipt against its own job. One lump sum
 * with one receipt would leave the customer holding paper that names no trip.
 */
final class AccountPayment
{
    public function __construct(private readonly BookingPayments $payments) {}

    /**
     * Bills this customer can actually be paid against, oldest first.
     *
     * Excluded: a bill raised from a quotation whose trip has not been created
     * yet. A receipt belongs to a job, and that job does not exist. Its balance
     * still counts as owed — it simply cannot be settled until the trip is
     * dispatched. A late-payment charge has no journey to wait for and is
     * payable the moment it is raised.
     *
     * @return \Illuminate\Support\Collection<int, LimoInvoice>
     */
    public function settleable(LimoCustomer $customer): \Illuminate\Support\Collection
    {
        return LimoInvoice::query()
            ->with('booking')
            ->where('customer_id', $customer->id)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (LimoInvoice $invoice): bool => ! $invoice->isAwaitingTrip() && $invoice->balance() > 0.0005)
            ->values();
    }

    /**
     * How a given amount would land, without taking it.
     *
     * Shown before the office presses the button: a payment that silently
     * spreads itself is one nobody can check.
     *
     * @return list<array{invoice: LimoInvoice, amount: float, settles: bool}>
     */
    public function plan(LimoCustomer $customer, float $amount): array
    {
        $remaining = round($amount, 3);
        $plan = [];

        foreach ($this->settleable($customer) as $invoice) {
            if ($remaining <= 0.0005) {
                break;
            }

            $balance = round($invoice->balance(), 3);
            $take = min($remaining, $balance);

            $plan[] = [
                'invoice' => $invoice,
                'amount' => $take,
                'settles' => $take + 0.0005 >= $balance,
            ];

            $remaining = round($remaining - $take, 3);
        }

        return $plan;
    }

    /**
     * Take the money and apply it.
     *
     * One transaction: a payment that stopped half way would leave the customer
     * charged for bills nobody can point at.
     *
     * @return array{allocated: float, receipts: int, unallocated: float}
     */
    public function settle(LimoCustomer $customer, float $amount, string $method = 'cash', ?string $note = null): array
    {
        $plan = $this->plan($customer, $amount);

        return DB::transaction(function () use ($plan, $amount, $method, $note): array {
            $allocated = 0.0;
            $receipts = 0;

            foreach ($plan as $slice) {
                $invoice = $slice['invoice'];
                $booking = $invoice->booking;

                // A trip's bill settles through the booking, so the job reads as
                // paid too; a standalone charge has only the document.
                if ($booking !== null) {
                    $this->payments->receive($booking, $slice['amount'], $method, $note);
                } else {
                    $this->payments->receiveForCharge($invoice, $slice['amount'], $method, $note);
                }

                $allocated = round($allocated + $slice['amount'], 3);
                $receipts++;
            }

            return [
                'allocated' => $allocated,
                'receipts' => $receipts,
                // What the account could not absorb — more than was owed, or
                // owed only on bills whose trip has not been created yet.
                'unallocated' => round(max(0.0, round($amount, 3) - $allocated), 3),
            ];
        });
    }
}
