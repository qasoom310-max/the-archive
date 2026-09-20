<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Limousine\Events\LimoPaymentLinkPaid;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Support\PortalSignature;

/**
 * The WordPress portal calls this after Tap confirms a payment. Public and
 * server-to-server — no session — so its ONLY credential is the HMAC signature.
 *
 * Tenancy: the body's `ws` says which ERP database raised the link, so we enter
 * that workspace FIRST, read ITS shared secret, and only then verify. Picking a
 * `ws` merely chooses which secret the signature is checked against — without
 * that secret no signature validates for any database — so an attacker can't
 * use it to reach another tenant's data.
 *
 * Idempotent: a link already paid returns 200 without crediting again, so a
 * retried or duplicated callback can never take the money twice.
 */
final class PaymentCallbackController
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return response('bad request', 400);
        }

        $wsRaw = $data['ws'] ?? null;
        $ws = is_numeric($wsRaw) && (int) $wsRaw > 0 ? (int) $wsRaw : null;

        return app(WorkspaceManager::class)->runFor($ws, function () use ($request, $raw, $data): Response {
            $secret = (string) LimoPortalConfiguration::current()->shared_secret;

            $verified = PortalSignature::verify(
                $raw,
                (string) $request->header(PortalSignature::TIMESTAMP_HEADER, ''),
                (string) $request->header(PortalSignature::SIGNATURE_HEADER, ''),
                $secret,
            );

            if (! $verified) {
                return response('invalid signature', 401);
            }

            $paymentId = (int) ($data['erp_payment_id'] ?? 0);
            $link = LimoPaymentLink::query()->find($paymentId);
            if ($link === null) {
                return response('unknown payment', 404);
            }

            $this->settle($link, $data);

            return response('ok', 200);
        });
    }

    /**
     * Credit the booking once, atomically. The amount is OUR billed figure, not
     * the number in the callback body, so a mismatch can never change what the
     * customer owes — it's only logged.
     *
     * @param  array<string, mixed>  $data
     */
    private function settle(LimoPaymentLink $link, array $data): void
    {
        $settled = false;

        DB::transaction(function () use ($link, $data, &$settled): void {
            $fresh = LimoPaymentLink::query()->whereKey($link->id)->lockForUpdate()->first();
            if ($fresh === null || $fresh->isPaid()) {
                return; // already settled — idempotent
            }
            $settled = true;

            $booking = LimoBooking::query()->find($fresh->booking_id);
            if ($booking !== null) {
                $ref = trim((string) ($data['transaction_ref'] ?? ''));
                app(BookingPayments::class)->receive(
                    $booking,
                    (float) $fresh->amount,
                    'online',
                    $ref !== '' ? (string) __('Paid online — :ref', ['ref' => $ref]) : (string) __('Paid online'),
                );
            }

            $fresh->forceFill([
                'status' => LimoPaymentLink::STATUS_PAID,
                'woo_order_id' => isset($data['woo_order_id']) && is_numeric($data['woo_order_id'])
                    ? (int) $data['woo_order_id'] : null,
                'transaction_ref' => ($data['transaction_ref'] ?? null) !== null
                    ? (string) $data['transaction_ref'] : null,
                'paid_at' => Carbon::now(),
            ])->save();

            $paidAmount = isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : null;
            if ($paidAmount !== null && round($paidAmount, 3) !== round((float) $fresh->amount, 3)) {
                Log::warning('Service-order payment amount mismatch', [
                    'payment_link' => $fresh->id,
                    'billed' => (float) $fresh->amount,
                    'callback' => $paidAmount,
                ]);
            }
        });

        // After the commit, and only for the delivery that actually settled it.
        // A listener failing must never turn a recorded payment into a 500.
        if ($settled) {
            try {
                event(new LimoPaymentLinkPaid($link->id));
            } catch (\Throwable $e) {
                Log::warning('Payment-link paid listener failed', ['payment_link' => $link->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
