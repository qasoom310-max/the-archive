<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCouponRedemption;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoReceipt;

/**
 * Everything that makes up a booking's total and everything paid against it,
 * in one place, so "it is paid but still shows a balance" can be answered by
 * reading the booking page instead of the database.
 *
 * The lists show "Received" as the larger of two records (the booking's
 * advance and what its invoices hold as paid), because a payment taken through
 * the booking is written to both. Money that reached only one of them (a
 * coupon spent on the booking, a receipt written against the invoice alone) can
 * therefore go missing from the figure. `receiptsTotal` and `disagrees` make
 * that visible rather than silent.
 */
final class BookingMoney
{
    /**
     * @return array{
     *     trips: list<array{reference: string, status: string, amount: float, billed: bool, outcome: ?string, refunded: float}>,
     *     meet_greet: float,
     *     total: float,
     *     receipts: list<array{reference: string, date: ?string, method: string, amount: float, invoice: ?string, through_booking: bool}>,
     *     receipts_total: float,
     *     coupons_spent: list<array{code: string, amount: float}>,
     *     coupons_spent_total: float,
     *     coupons_issued: list<array{code: string, amount: float, trip: ?string}>,
     *     links: list<array{amount: float, status: string, created: ?string, paid: ?string}>,
     *     advance: float,
     *     invoices_paid: float,
     *     received: float,
     *     owed: float,
     *     disagrees: bool,
     *     links_waiting: float
     * }
     */
    public static function of(LimoBooking $booking): array
    {
        $trips = [];
        foreach ($booking->legs()->orderBy('sequence')->orderBy('id')->get() as $leg) {
            /** @var LimoLeg $leg */
            $trips[] = [
                'reference' => (string) ($leg->reference ?? ''),
                'status' => (string) ($leg->status ?? LimoLeg::STATUS_QUEUE),
                'amount' => round((float) $leg->net_amount, 3),
                'billed' => $leg->isBillable(),
                'outcome' => $leg->status === LimoLeg::STATUS_CANCELLED ? $leg->refund_outcome : null,
                'refunded' => $leg->refund_outcome === LimoLeg::REFUND_REFUNDED ? round((float) $leg->refund_amount, 3) : 0.0,
            ];
        }

        $invoiceIds = $booking->invoices()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $invoiceRefs = LimoInvoice::query()->whereKey($invoiceIds)->pluck('reference', 'id')->all();

        // A receipt belongs here when it names the booking or is written against
        // one of its invoices. Counted once even when it does both.
        $receiptRows = LimoReceipt::query()
            ->where(function ($q) use ($booking, $invoiceIds): void {
                $q->where('booking_id', $booking->id);
                if ($invoiceIds !== []) {
                    $q->orWhereIn('invoice_id', $invoiceIds);
                }
            })
            ->orderBy('date')->orderBy('id')
            ->get();

        $receipts = [];
        foreach ($receiptRows as $r) {
            /** @var LimoReceipt $r */
            $receipts[] = [
                'reference' => (string) ($r->reference ?? ''),
                'date' => $r->date?->format('d-M-Y'),
                'method' => (string) $r->method,
                'amount' => round((float) $r->amount, 3),
                'invoice' => $r->invoice_id !== null ? ($invoiceRefs[$r->invoice_id] ?? null) : null,
                'through_booking' => $r->booking_id !== null,
            ];
        }
        $receiptsTotal = round(array_sum(array_column($receipts, 'amount')), 3);

        $spent = [];
        foreach (LimoCouponRedemption::query()->where('limo_booking_id', $booking->id)->get() as $use) {
            /** @var LimoCouponRedemption $use */
            $code = LimoCoupon::query()->whereKey($use->limo_coupon_id)->value('code');
            $spent[] = ['code' => (string) ($code ?? ''), 'amount' => round((float) $use->amount, 3)];
        }

        $issued = [];
        foreach (LimoCoupon::query()->where('limo_booking_id', $booking->id)->orderBy('id')->get() as $coupon) {
            /** @var LimoCoupon $coupon */
            $issued[] = ['code' => $coupon->code, 'amount' => round((float) $coupon->amount, 3), 'trip' => $coupon->leg_reference];
        }

        $links = [];
        foreach (LimoPaymentLink::query()->where('booking_id', $booking->id)->orderBy('id')->get() as $link) {
            /** @var LimoPaymentLink $link */
            $links[] = [
                'amount' => round((float) $link->amount, 3),
                'status' => $link->status,
                'created' => $link->created_at?->format('d-M-Y H:i'),
                'paid' => $link->paid_at?->format('d-M-Y H:i'),
            ];
        }
        $waiting = round(array_sum(array_map(
            static fn (array $l): float => $l['status'] === LimoPaymentLink::STATUS_PAID || $l['status'] === LimoPaymentLink::STATUS_CANCELLED ? 0.0 : $l['amount'],
            $links,
        )), 3);

        $invoicesPaid = round((float) $booking->invoices()->sum('amount_paid'), 3);
        $received = $booking->amountReceived();
        $spentTotal = round(array_sum(array_column($spent, 'amount')), 3);
        $refunded = round(array_sum(array_column($trips, 'refunded')), 3);

        // What the paper trail adds up to: every receipt plus coupon credit,
        // less money handed back. More than "Received" means a payment reached
        // only one of the two records the figure is read from.
        $onFile = round($receiptsTotal + $spentTotal - $refunded, 3);

        return [
            'trips' => $trips,
            'meet_greet' => $booking->meetGreetCharge(),
            'total' => $booking->netAmount(),
            'receipts' => $receipts,
            'receipts_total' => $receiptsTotal,
            'coupons_spent' => $spent,
            'coupons_spent_total' => $spentTotal,
            'coupons_issued' => $issued,
            'links' => $links,
            'advance' => round((float) $booking->advance, 3),
            'invoices_paid' => $invoicesPaid,
            'received' => $received,
            'owed' => $booking->amountOwed(),
            'disagrees' => $onFile > $received + 0.0005,
            'links_waiting' => $waiting,
        ];
    }
}
