<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use Illuminate\Support\Facades\DB;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Rental\Support\CustomerImporter;

/**
 * Turns a website booking into a draft rental order.
 *
 * Deliberately fills in ONLY what the website actually knows, and leaves the
 * rest blank for the desk. It never sets a car: the site sells one generic
 * booking product rather than a vehicle from the fleet, so choosing which car
 * goes out is a decision a person makes with the day's availability in front
 * of them. Nor does it price the hire — the customer was quoted on the website,
 * and what we charge comes from our own rates.
 *
 * What the customer already paid is carried across as the advance, because
 * that is a fact about money that has genuinely changed hands, and losing it
 * would have the desk asking for it twice.
 */
final class WebBookingConverter
{
    /**
     * One transaction: a booking that ends up marked complete with no order to
     * show for it is worse than one that is still pending.
     */
    public function convert(RentalWebBooking $booking): RentalOrder
    {
        return DB::transaction(function () use ($booking): RentalOrder {
            $customer = $this->customerFor($booking);

            $order = new RentalOrder();
            $order->fill([
                'order_date' => now()->toDateString(),
                'customer_id' => $customer->id,
                'phone' => $booking->phone,
                'start_date' => $booking->pickup_at?->toDateString(),
                'end_date' => $booking->dropoff_at?->toDateString(),
                'hired_time' => $booking->pickup_at?->format('H:i'),
                'delivery_location' => $booking->pickup_location,

                // Money the customer has already handed over on the website.
                // Not the price of the hire — that is ours to set.
                'advance_amount' => $booking->isPaidOnline() ? ($booking->total ?? 0.0) : 0.0,

                'notes' => $this->notes($booking),
            ]);
            $order->save();

            $booking->status = RentalWebBooking::STATUS_COMPLETE;
            $booking->completed_at = now();
            $booking->rental_order_id = (int) $order->id;
            $booking->save();

            return $order;
        });
    }

    /**
     * The customer this booking is for: the one we already have, or a new one.
     *
     * Matched on the phone number the same way the customer import matches —
     * by a shared ending of at least seven digits — so a number the website
     * sends with its country code still finds the person we store locally,
     * rather than making a second record of them.
     */
    private function customerFor(RentalWebBooking $booking): RentalCustomer
    {
        $phone = trim((string) $booking->phone);

        if ($phone !== '') {
            $existing = $this->byPhone($phone);

            if ($existing !== null) {
                return $existing;
            }
        }

        $customer = new RentalCustomer();
        $customer->fill([
            'name' => $booking->customerName() === '—' ? __('Website customer') : $booking->customerName(),
            'phone' => $phone !== '' ? $phone : null,
            'email' => $booking->email,
            'address' => trim((string) $booking->address . ' ' . (string) $booking->town) ?: null,
        ]);
        $customer->save();

        return $customer;
    }

    /**
     * A customer whose number ends the same way as this one, ignoring the
     * country code and any leading trunk zero.
     */
    private function byPhone(string $phone): ?RentalCustomer
    {
        $digits = ltrim(preg_replace('/\D+/', '', $phone) ?? '', '0');

        if (strlen($digits) < CustomerImporter::PHONE_SUFFIX_MIN) {
            return null;
        }

        $ending = substr($digits, -CustomerImporter::PHONE_SUFFIX_MIN);

        // Only the two columns the comparison needs: a stored number can carry
        // spaces, dashes or a country code, so the digits have to be stripped
        // in PHP rather than matched in SQL — but there is no reason to build
        // a few thousand whole customers to do it.
        $match = RentalCustomer::query()
            ->whereNotNull('phone')
            ->select(['id', 'phone'])
            ->get()
            ->first(function (RentalCustomer $customer) use ($ending): bool {
                $theirs = ltrim(preg_replace('/\D+/', '', (string) $customer->phone) ?? '', '0');

                return strlen($theirs) >= CustomerImporter::PHONE_SUFFIX_MIN
                    && str_ends_with($theirs, $ending);
            });

        return $match === null ? null : RentalCustomer::query()->find($match->id);
    }

    /**
     * Everything the website said that the order form has no field for, put
     * where a person will read it rather than dropped.
     */
    private function notes(RentalWebBooking $booking): string
    {
        $lines = [
            __('From the website') . ' — ' . $booking->source_reference,
            __('Product') . ': ' . ($booking->product ?? '—'),
        ];

        if ($booking->dropoff_location !== null) {
            $lines[] = __('Drop-off') . ': ' . $booking->dropoff_location;
        }

        if ($booking->total !== null) {
            $lines[] = __('Paid on the website') . ': ' . number_format($booking->total, 3)
                . ' (' . ($booking->payment_mode ?? '—') . ', ' . ($booking->payment_status ?? '—') . ')';
        }

        if ($booking->notes !== null) {
            $lines[] = $booking->notes;
        }

        return implode("\n", $lines);
    }
}
