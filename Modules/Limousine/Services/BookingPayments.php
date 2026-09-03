<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;

/**
 * Taking money on a booking — and issuing the receipt for it.
 *
 * Every way money arrives goes through here: the whole fare at the counter,
 * half now and half on return, something handed to the driver afterwards. The
 * office does not raise the receipt separately, because a receipt that depends
 * on somebody remembering is one the customer sometimes never gets.
 *
 * The receipt records what was taken AND what was still owed at that moment, so
 * a half payment prints as exactly that: 25.000 received, 20.000 still to pay.
 */
final class BookingPayments
{
    /**
     * Receive money against a booking and issue its receipt.
     *
     * Returns null when there was nothing to receive — asking to take zero is
     * not an error, it just leaves no paper.
     */
    public function receive(
        LimoBooking $booking,
        float $amount,
        string $method = 'cash',
        ?string $note = null,
        ?string $batchId = null,
    ): ?LimoReceipt {
        $amount = round($amount, 3);

        if ($amount <= 0.0) {
            return null;
        }

        return DB::transaction(function () use ($booking, $amount, $method, $note, $batchId): LimoReceipt {
            // Money lands on the advance — the same field a coupon and the
            // booking form use — so "paid" settles through one path.
            $booking->advance = round((float) $booking->advance + $amount, 3);
            $booking->payment_method = $method;
            $booking->save();
            $booking->syncPaymentFromAdvance();

            return $this->issueFor($booking->refresh(), $amount, $method, $note, $batchId);
        });
    }

    /**
     * Take money against a charge that has no journey behind it.
     *
     * A late-payment fee is owed by the account, not by a trip, so there is no
     * booking to put the money on — the receipt is written against the invoice
     * alone. Everything else is the same shape as {@see issueFor}, including
     * the balance stored as it stood at the moment.
     */
    public function receiveForCharge(
        LimoInvoice $invoice,
        float $amount,
        string $method = 'cash',
        ?string $note = null,
        ?string $batchId = null,
    ): ?LimoReceipt {
        $amount = round($amount, 3);

        if ($amount <= 0.0) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $note, $batchId): LimoReceipt {
            $receipt = LimoReceipt::query()->create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'date' => Carbon::today(),
                'amount' => $amount,
                'balance_after' => round(max(0.0, $invoice->balance() - $amount), 3),
                'method' => $method,
                'auto' => true,
                'notes' => $note,
                'batch_id' => $batchId,
            ]);

            $invoice->refresh()->recomputePaid();

            return $receipt;
        });
    }

    /**
     * Write the receipt for money that has ALREADY been applied.
     *
     * The booking form sets the advance itself as part of saving the job, so by
     * the time this is reached the money is on the record: it needs the
     * paperwork, not the arithmetic done a second time.
     */
    public function issueFor(
        LimoBooking $booking,
        float $amount,
        string $method = 'cash',
        ?string $note = null,
        ?string $batchId = null,
    ): ?LimoReceipt {
        $amount = round($amount, 3);

        if ($amount <= 0.0) {
            return null;
        }

        // Every trip is invoiced, so the receipt is written against BOTH: the
        // booking it belongs to and the invoice it settles. Without the invoice
        // side the document would sit there unpaid while the money was already
        // in the drawer.
        $invoice = $booking->syncInvoice();

        $receipt = LimoReceipt::query()->create([
            'booking_id' => $booking->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $booking->customer_id,
            'date' => Carbon::today(),
            'amount' => $amount,
            // What was still owed once this was taken. Stored, because a receipt
            // is a record of a moment rather than a live figure — recomputing it
            // later would make an old receipt change its mind.
            'balance_after' => $booking->balanceDue(),
            'method' => $method,
            'auto' => true,
            'notes' => $this->note($booking, $amount, $note),
            // The bulk payment that wrote this, so the accountant confirms the
            // lump once instead of each slice.
            'batch_id' => $batchId,
        ]);

        // Recomputed from its receipts, which also flips the invoice to
        // partial/paid and settles the booking when it is fully covered.
        $invoice->refresh()->recomputePaid();

        return $receipt;
    }

    /**
     * What the receipt says, in the words the customer reads.
     *
     * The balance is spelled out rather than left as a column, because "paid 25"
     * and "paid 25, 20 still to pay" are different pieces of news — and the
     * second is the one that prevents an argument later.
     */
    private function note(LimoBooking $booking, float $amount, ?string $note): string
    {
        $lines = [];

        if (($booking->reference ?? '') !== '') {
            $lines[] = (string) __('Booking :reference', ['reference' => $booking->reference]);
        }

        $balance = $booking->balanceDue();
        $lines[] = $balance > 0
            ? (string) __('Received :amount. Balance :balance still to pay.', [
                'amount' => ValueFormat::money($amount),
                'balance' => ValueFormat::money($balance),
            ])
            : (string) __('Received :amount. Paid in full.', [
                'amount' => ValueFormat::money($amount),
            ]);

        $extra = trim((string) $note);
        if ($extra !== '') {
            $lines[] = $extra;
        }

        return implode("\n", $lines);
    }
}
