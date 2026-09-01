<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\PortalSignature;
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

        $leg = LimoLeg::query()->with('legable.customer')->find($link->leg_id);
        if ($leg === null) {
            return false;
        }

        $payload = $this->payload($link, $leg);
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

        $remark = (string) ($leg->notes ?? '');

        return [
            'erp_payment_id' => $link->id,
            'erp_booking_id' => $link->booking_id,
            'erp_leg_id' => $link->leg_id,
            'ws' => $this->workspaceId(),
            'confirmation_no' => (string) ($leg->reference ?? ''),
            'booking_no' => $bookingNo,
            'date' => now()->format('Y-m-d'),
            'customer_name' => $customerName,
            'telephone' => $telephone,
            'email' => $email,
            'service_date' => $leg->start_at?->format('Y-m-d') ?? '',
            'service_time' => $leg->start_at?->format('H:i') ?? '',
            'vehicle' => (string) ($leg->vehicle ?? ''),
            'flight_number' => $flightNumber,
            'pax_name' => $paxName,
            'pax_contact' => $paxContact,
            'pickup' => (string) ($leg->from_location ?? ''),
            'dropoff' => (string) ($leg->to_location ?? ''),
            // Sent as a fixed-3-decimal string so BHD amounts cross the wire
            // exactly (45.000), never as a lossy float.
            'amount' => number_format((float) $link->amount, 3, '.', ''),
            'currency' => (string) $link->currency,
            'remark' => $remark !== '' ? $remark : $bookingNotes,
        ];
    }

    /**
     * Which database this link was raised in, so the paid-callback re-enters it.
     * Main answers null (the callback's `runFor(null)` is a no-op that stays on
     * Main); a tenant answers its id.
     */
    private function workspaceId(): ?int
    {
        try {
            $workspace = app(WorkspaceManager::class)->current();

            return $workspace->is_main ? null : (int) $workspace->getKey();
        } catch (Throwable) {
            return null;
        }
    }
}
