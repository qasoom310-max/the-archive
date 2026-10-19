<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Money\Currencies;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Rental\Support\CustomerImporter;

/**
 * Turns a limousine trip booked on the website into a limousine booking, with
 * its one trip waiting in the queue for dispatch.
 *
 * Fills in what the website knows: who, where from and to, when, which car
 * TYPE, and the fare the fare finder quoted (which comes from our own fare
 * tables, so it is our price). It never assigns a car or a driver; that is
 * dispatch's job, the same as a booking made on the WhatsApp assistant.
 *
 * Money the customer already paid on the website is taken through
 * {@see BookingPayments::receive()}, so it gets a receipt and settles the
 * booking the same way a payment at the desk would.
 */
final class WebBookingToLimousine
{
    public function __construct(private readonly BookingPayments $payments)
    {
    }

    public function convert(RentalWebBooking $web, ?User $by = null): LimoBooking
    {
        return DB::transaction(function () use ($web, $by): LimoBooking {
            $customer = $this->customerFor($web);
            $name = $web->customerName() === '—' ? (string) $customer->name : $web->customerName();
            $trip = $web->productName() === '—' ? null : $web->productName();
            $hours = $this->hours($trip);
            $chauffeur = $hours !== null || str_contains(strtolower((string) $trip), 'chauffeur')
                || str_contains(strtolower((string) $trip), 'hourly');

            $booking = new LimoBooking();
            $booking->customer_id = (int) $customer->id;
            $booking->pax_name = $name;
            $booking->pax_contact = $web->phone;
            $booking->requested_by = $name;
            $booking->prepared_by = $by !== null ? (string) $by->name : null;
            $booking->payment_method = 'online';
            $booking->advance = 0;
            $booking->pickup_at = $web->pickup_at ?? Carbon::now();
            $booking->notes = $this->notes($web);
            $booking->save();

            $rate = round((float) ($web->total ?? 0.0), 3);

            $booking->legs()->create([
                'sequence' => 0,
                'status' => LimoLeg::STATUS_QUEUE,
                'service_type' => $chauffeur ? LimoLeg::TYPE_CHAUFFEUR : LimoLeg::TYPE_TRANSFER,
                'from_location' => $web->pickup_location,
                'to_location' => $chauffeur ? null : $web->dropoff_location,
                'start_at' => $web->pickup_at,
                'hours' => $hours,
                'days' => 1,
                // The car TYPE the customer chose; the car itself is dispatch's.
                'vehicle_details' => $trip,
                'rate' => $rate,
                'currency' => LimoLeg::DEFAULT_CURRENCY,
                'rate_basis' => LimoLeg::BASIS_TRIP,
                'discount' => 0,
                'vat' => 0,
                'line_total' => LimoLeg::grossFor(LimoLeg::BASIS_TRIP, $rate, null, 1),
                'net_amount' => LimoLeg::netFor(LimoLeg::BASIS_TRIP, $rate, null, 1, 0.0, 0.0),
            ]);

            $booking->recalcTotal();
            $booking->save();
            $booking->syncPaymentFromAdvance();
            $booking->syncInvoice();

            if ($web->isPaidOnline() && $rate > 0.0) {
                $this->payments->receive($booking->refresh(), $rate, 'online', __('Paid on the website') . ' — ' . $web->source_reference);
            }

            $web->status = RentalWebBooking::STATUS_COMPLETE;
            $web->completed_at = now();
            $web->limo_booking_id = (int) $booking->id;
            $web->save();

            return $booking->refresh();
        });
    }

    /** "Chauffeur — SUV (4 hours)" → 4. */
    private function hours(?string $trip): ?float
    {
        if ($trip !== null && preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)\b/i', $trip, $m) === 1) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * The customer this trip is for: the one we already have, matched on a
     * shared phone ending of at least seven digits (so a number sent with its
     * country code finds the person we store locally), or a new one.
     */
    private function customerFor(RentalWebBooking $web): LimoCustomer
    {
        $digits = ltrim(preg_replace('/\D+/', '', (string) $web->phone) ?? '', '0');

        if (strlen($digits) >= CustomerImporter::PHONE_SUFFIX_MIN) {
            $ending = substr($digits, -CustomerImporter::PHONE_SUFFIX_MIN);

            $match = LimoCustomer::query()
                ->whereNotNull('phone')
                ->where('phone', 'like', '%' . substr($ending, -4) . '%')
                ->get(['id', 'phone'])
                ->first(static function (LimoCustomer $c) use ($ending): bool {
                    $theirs = ltrim(preg_replace('/\D+/', '', (string) $c->phone) ?? '', '0');

                    return strlen($theirs) >= CustomerImporter::PHONE_SUFFIX_MIN && str_ends_with($theirs, $ending);
                });

            if ($match instanceof LimoCustomer && ($found = LimoCustomer::query()->find($match->id)) instanceof LimoCustomer) {
                return $found;
            }
        }

        return LimoCustomer::query()->create([
            'name' => $web->customerName() === '—' ? __('Website customer') : $web->customerName(),
            'type' => 'individual',
            'phone' => trim((string) $web->phone) !== '' ? trim((string) $web->phone) : null,
            'email' => $web->email,
            'active' => true,
        ]);
    }

    /**
     * Everything the website said that the booking has no field for, where a
     * person will read it: the reference, the payment, and every detail the
     * customer filled in (flight number, passengers, and so on).
     */
    private function notes(RentalWebBooking $web): string
    {
        $lines = [__('From the website') . ' — ' . $web->source_reference];

        if ($web->total !== null) {
            $lines[] = __('Website total') . ': ' . Currencies::format($web->total)
                . ' (' . ($web->payment_mode ?? '—') . ', ' . ($web->isPaidOnline() ? __('Paid') : __('Not paid')) . ')';
        }

        foreach ($web->details() as $line) {
            $lines[] = $line['label'] . ': ' . $line['value'];
        }

        if ($web->notes !== null) {
            $lines[] = $web->notes;
        }

        return implode("\n", $lines);
    }
}
