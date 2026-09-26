<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Limousine\Support\PortalSignature;
use Modules\Rental\Models\RentalPortalConfiguration;
use Modules\Rental\Models\RentalWebBooking;

/**
 * The website posts a booking here the moment a customer's order is placed.
 *
 * Public and server-to-server — no session — so the HMAC signature is its ONLY
 * credential. The old integration used HTTP Basic auth with the username and
 * password written into the plugin file, which meant anybody holding a copy of
 * the plugin could post whatever they liked; this carries no secret in the
 * plugin at all.
 *
 * Tenancy works exactly as the limousine payment callback's does: the body's
 * `ws` says which database the site belongs to, so we enter that workspace
 * first, read ITS secret, and only then verify. Naming a `ws` only chooses
 * which secret the signature is checked against, and without that secret no
 * signature validates for any database.
 *
 * Idempotent, and that is not a nicety here. The old plugin sent on
 * `woocommerce_thankyou`, which fires again every time the customer reloads
 * the order-received page — one live order reached the old system NINE times.
 * A repeat delivery updates the row it already made.
 */
final class WebBookingController
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return response()->json(['error' => 'bad request'], 400);
        }

        $wsRaw = $data['ws'] ?? null;
        $ws = is_numeric($wsRaw) && (int) $wsRaw > 0 ? (int) $wsRaw : null;

        return app(WorkspaceManager::class)->runFor($ws, function () use ($request, $raw, $data): JsonResponse {
            $config = RentalPortalConfiguration::current();

            // Shut until somebody turns it on. Checked BEFORE the signature so
            // a database that never meant to accept bookings cannot be probed
            // for whether a given secret happens to be right.
            if (! $config->isConfigured()) {
                return response()->json(['error' => 'not accepting bookings'], 403);
            }

            $verified = PortalSignature::verify(
                $raw,
                (string) $request->header(PortalSignature::TIMESTAMP_HEADER, ''),
                (string) $request->header(PortalSignature::SIGNATURE_HEADER, ''),
                (string) $config->shared_secret,
            );

            if (! $verified) {
                return response()->json(['error' => 'invalid signature'], 401);
            }

            $reference = trim((string) ($data['source_reference'] ?? ''));
            if ($reference === '') {
                return response()->json(['error' => 'source_reference is required'], 422);
            }

            $booking = $this->store($reference, $data);

            return response()->json([
                'ok' => true,
                'id' => $booking->id,
                'status' => $booking->status,
            ], 200);
        });
    }

    /**
     * Record the booking, or update the one this reference already made.
     *
     * A booking a member of staff has already dealt with is NOT reopened: its
     * details are refreshed so the record stays true to what the customer
     * asked for, but `status` and the order raised from it are left alone. A
     * reloaded thank-you page must not put a finished job back on the queue.
     *
     * @param  array<string, mixed>  $data
     */
    private function store(string $reference, array $data): RentalWebBooking
    {
        $booking = RentalWebBooking::query()->firstOrNew(['source_reference' => $reference]);

        $booking->fill([
            'first_name' => $this->text($data, 'first_name'),
            'last_name' => $this->text($data, 'last_name'),
            'phone' => $this->text($data, 'phone'),
            'email' => $this->text($data, 'email'),
            'address' => $this->text($data, 'street_address'),
            'town' => $this->text($data, 'town'),
            'product' => $this->text($data, 'product'),
            'quantity' => max(1, (int) ($data['product_quantity'] ?? 1)),
            'pickup_location' => $this->text($data, 'pickup_location'),
            'dropoff_location' => $this->text($data, 'dropoff_location'),
            'pickup_at' => $this->moment($data, 'pickup_datetime'),
            'dropoff_at' => $this->moment($data, 'dropoff_datetime'),
            'subtotal' => isset($data['sub_total']) ? (float) $data['sub_total'] : null,
            'total' => isset($data['total']) ? (float) $data['total'] : null,
            'payment_mode' => $this->text($data, 'payment_mode'),
            'payment_status' => $this->text($data, 'payment_status'),
            'notes' => $this->text($data, 'booking_notes'),

            // Verbatim, every time. The columns above are what we read today;
            // this is what the customer actually sent, and it outlives our
            // guesses about which fields matter.
            'payload' => $data,
        ]);

        if (! $booking->exists) {
            $booking->status = RentalWebBooking::STATUS_PENDING;
        }

        $booking->save();

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The site sends "Y-m-d H:i:s", but only when its booking product is set
     * up to collect a date at all — so anything unparseable is simply absent
     * rather than an error worth refusing a real booking over.
     *
     * @param  array<string, mixed>  $data
     */
    private function moment(array $data, string $key): ?Carbon
    {
        $value = $this->text($data, $key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
