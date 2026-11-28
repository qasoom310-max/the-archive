<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Models\RentalOrder;
use Throwable;

/**
 * Pushes a payment link ("partition") to the Wanaan WordPress portal and reads
 * back the public URL the customer opens.
 *
 * Two hard rules live here:
 *  - It NEVER throws. A booking must save even if WordPress is down, so every
 *    failure is caught, logged, and returned as `false` — the caller tells the
 *    agent the link couldn't be made rather than losing the booking.
 *  - It only sends when the master switch is on ({@see LimoPortalConfiguration::isConfigured()}),
 *    so an unconfigured database is a silent no-op.
 *
 * The request is signed with {@see PortalSignature}; the raw JSON body is what
 * gets signed, so it is built once as a string and sent verbatim.
 */
final class ServiceOrderPortalClient
{
    /** Seconds to wait — short, because the agent is watching the screen. */
    private const TIMEOUT = 5;

    /**
     * Send the link to the portal and fill its `url` + `token` from the reply.
     * Returns true only when the portal answered with a usable URL.
     */
    public function push(LimoPaymentLink $link): bool
    {
        $config = LimoPortalConfiguration::current();
        if (! $config->isConfigured()) {
            return false;
        }

        if ($link->rental_order_id !== null) {
            $order = Schema::hasTable('rental_orders')
                ? RentalOrder::query()->with(['customer', 'vehicle'])->find($link->rental_order_id)
                : null;
            if ($order === null) {
                return false;
            }
            $payload = $this->rentalPayload($link, $order);
        } else {
            $leg = LimoLeg::query()->with('legable.customer')->find($link->leg_id);
            if ($leg === null) {
                return false;
            }
            $payload = $this->payload($link, $leg);
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $secret = (string) $config->shared_secret;

        try {
            $response = Http::connectTimeout(self::TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withHeaders([
                    PortalSignature::TIMESTAMP_HEADER => $timestamp,
                    PortalSignature::SIGNATURE_HEADER => PortalSignature::sign($json, $timestamp, $secret),
                    'Accept' => 'application/json',
                ])
                ->withBody($json, 'application/json')
                ->post($config->bookingEndpoint());
        } catch (Throwable $e) {
            Log::warning('Service-order portal push failed', [
                'payment_link' => $link->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Service-order portal push rejected', [
                'payment_link' => $link->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        $url = (string) $response->json('url', '');
        $token = (string) $response->json('token', '');
        if ($url === '' || $token === '') {
            return false;
        }

        $link->forceFill(['url' => $url, 'token' => $token])->save();

        return true;
    }

    /**
     * The Service Order, as the portal page needs it — the same fields the PDF
     * shows, sourced identically (trip facts from the leg, customer/PAX from the
     * parent booking). `erp_payment_id` is the idempotency key; `ws` tells the
     * paid-callback which ERP database to settle back into.
     *
     * @return array<string, mixed>
     */
    private function payload(LimoPaymentLink $link, LimoLeg $leg): array
    {
        // Resolve the booking/customer fields into locals under explicit null
        // guards — the same shape ServiceOrderPdf uses, so the union types
        // narrow cleanly for the analyser instead of nullsafe chains.
        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        $bookingNo = '';
        $flightNumber = '';
        $paxName = '';
        $paxContact = '';
        $bookingNotes = '';
        $customerName = '';
        $telephone = '';
        $email = '';

        if ($booking !== null) {
            $bookingNo = (string) ($booking->reference ?? '');
            $flightNumber = (string) ($booking->flight_number ?? '');
            $paxName = (string) ($booking->pax_name ?? '');
            $paxContact = (string) ($booking->pax_contact ?? '');
            $bookingNotes = (string) ($booking->notes ?? '');

            $customer = $booking->customer;
            if ($customer !== null) {
                $customerName = (string) $customer->name;
                $telephone = (string) ($customer->phone ?? '');
                $email = (string) ($customer->serviceEmail() ?? '');
            }
        }

        // The link takes money for the whole booking, so the customer sees every
        // trip in it, not only the one the agent opened the dialog from.
        $trips = $booking !== null ? self::tripsOf($booking, $leg) : collect([$leg]);
        $first = $trips->first() ?? $leg;
        $remark = (string) ($first->notes ?? '');

        return [
            'service' => 'limousine',
            'erp_payment_id' => $link->id,
            'erp_booking_id' => $link->booking_id,
            'erp_leg_id' => $link->leg_id,
            'ws' => $this->workspaceId(),
            'confirmation_no' => self::tripNumbers($trips),
            // Each trip, for the pay page and the store's order email (plugin
            // 1.2.0+). The single fields below stay the first trip's, for older
            // plugins.
            'trips' => $trips->map(static fn (LimoLeg $trip): array => [
                'ref' => (string) ($trip->reference ?? ''),
                'date' => $trip->start_at?->format('Y-m-d') ?? '',
                'time' => $trip->start_at?->format('H:i') ?? '',
                'pickup' => (string) ($trip->from_location ?? ''),
                'dropoff' => (string) ($trip->to_location ?? ''),
                'vehicle' => $trip->billedVehicle($booking),
            ])->values()->all(),
            'booking_no' => $bookingNo,
            'date' => now()->format('Y-m-d'),
            'customer_name' => $customerName,
            'telephone' => $telephone,
            'email' => $email,
            'service_date' => $first->start_at?->format('Y-m-d') ?? '',
            'service_time' => $first->start_at?->format('H:i') ?? '',
            // The car the customer booked (type, never the assigned plate).
            'vehicle' => $first->billedVehicle($booking),
            'flight_number' => $flightNumber,
            'pax_name' => $paxName,
            'pax_contact' => $paxContact,
            'pickup' => (string) ($first->from_location ?? ''),
            'dropoff' => (string) ($first->to_location ?? ''),
            // Sent as a fixed-3-decimal string so BHD amounts cross the wire
            // exactly (45.000), never as a lossy float.
            'amount' => number_format((float) $link->amount, 3, '.', ''),
            'currency' => (string) $link->currency,
            'remark' => $remark !== '' ? $remark : $bookingNotes,
        ];
    }

    /**
     * The trips a payment link on this booking pays for: every trip still
     * charged (a cancelled one only when it is kept on the bill), in order.
     * Falls back to the trip the dialog was opened from.
     *
     * @return Collection<int, LimoLeg>
     */
    public static function tripsOf(LimoBooking $booking, LimoLeg $fallback): Collection
    {
        $trips = $booking->legs()->orderBy('sequence')->orderBy('id')->get()
            ->filter(static fn (LimoLeg $trip): bool => $trip->isBillable())
            ->values();

        return $trips->isEmpty() ? collect([$fallback]) : $trips;
    }

    /**
     * "26382, 26383" — the trip numbers, kept inside the portal's 64-character
     * column ("26382, 26383 +4" when there are too many to list).
     *
     * @param  Collection<int, LimoLeg>  $trips
     */
    public static function tripNumbers(Collection $trips): string
    {
        $refs = $trips->map(static fn (LimoLeg $trip): string => trim((string) ($trip->reference ?? '')))
            ->filter(static fn (string $ref): bool => $ref !== '')
            ->values();

        $shown = [];
        foreach ($refs as $i => $ref) {
            $rest = $refs->count() - $i - 1;
            $candidate = implode(', ', [...$shown, $ref]) . ($rest > 0 ? ' +' . $rest : '');
            if (mb_strlen($candidate) > 64) {
                break;
            }
            $shown[] = $ref;
        }
        $left = $refs->count() - count($shown);

        return implode(', ', $shown) . ($left > 0 ? ' +' . $left : '');
    }

    /**
     * A Rent A Car order, in the same fields the portal stores. `service` tells
     * the portal to show it as a car hire ("Agreement no.", pick-up and return
     * dates) rather than a limousine trip. The return date rides in `dropoff`,
     * the delivery address (when the car is brought to the customer) in
     * `pickup`. The office's own notes stay in the ERP.
     *
     * @return array<string, mixed>
     */
    private function rentalPayload(LimoPaymentLink $link, RentalOrder $order): array
    {
        $customer = $order->customer;
        $phone = $customer !== null ? (string) ($customer->phone ?? '') : '';

        return [
            'service' => 'rental',
            'erp_payment_id' => $link->id,
            'erp_booking_id' => null,
            'erp_leg_id' => null,
            'ws' => $this->workspaceId(),
            'confirmation_no' => (string) ($order->reference ?? ''),
            'booking_no' => '',
            'date' => now()->format('Y-m-d'),
            'customer_name' => $customer !== null ? (string) $customer->name : '',
            'telephone' => $phone !== '' ? $phone : (string) ($order->phone ?? ''),
            'email' => $customer !== null ? (string) ($customer->email ?? '') : '',
            'service_date' => $order->start_date?->format('Y-m-d') ?? '',
            'service_time' => (string) ($order->hired_time ?? ''),
            'vehicle' => $order->vehicle !== null ? (string) $order->vehicle->name : '',
            'flight_number' => '',
            'pax_name' => '',
            'pax_contact' => '',
            'pickup' => $order->delivery ? (string) ($order->delivery_location ?? '') : '',
            'dropoff' => $order->end_date?->format('Y-m-d') ?? '',
            'amount' => number_format((float) $link->amount, 3, '.', ''),
            'currency' => (string) $link->currency,
            'remark' => '',
        ];
    }

    /**
     * Which database this link was raised in, so the paid-callback re-enters it.
     * Main answers null (the callback's `runFor(null)` is a no-op that stays on
     * Main); a tenant answers its id.
     *
     * The database IN USE, not the browser's cookie: the WhatsApp assistant
     * raises links inside the business's database (the webhook's runFor) with
     * no cookie at all, so the cookie said Main — and the customer's payment
     * came back to Main, where the booking is not.
     */
    private function workspaceId(): ?int
    {
        try {
            return app(WorkspaceManager::class)->activeTenantId();
        } catch (Throwable) {
            return null;
        }
    }
}
