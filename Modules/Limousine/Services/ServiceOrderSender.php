<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Modules\Limousine\Mail\ServiceOrderMail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * Emails a customer the link to sign a leg's Service Order.
 *
 * The link is a temporary signed URL: its own signature is the credential, so a
 * customer needs no account, and editing the leg id in the address bar breaks
 * the signature instead of reaching someone else's trip. It expires so an old
 * mail cannot be replayed months later — the office resends if it lapses.
 */
final class ServiceOrderSender
{
    /** How long a signing link stays valid. */
    public const LINK_DAYS = 30;

    /**
     * Send the invitation. Returns the address it went to, or null when the
     * customer has no email on file (the caller tells the user, rather than
     * silently doing nothing).
     */
    public function send(LimoLeg $leg): ?string
    {
        $leg->loadMissing('legable.customer');

        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        if ($booking === null) {
            return null;
        }

        // The booking's own address wins — it is the one captured for this job;
        // the customer record is the fallback.
        $email = $booking->email;
        if (! is_string($email) || trim($email) === '') {
            $customer = $booking->customer;
            $email = $customer !== null ? $customer->email : null;
        }

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        $email = trim($email);

        Mail::to($email)->send(new ServiceOrderMail(
            leg: $leg,
            signUrl: $this->signUrl($leg),
            companyName: (string) Setting::get('company.name', 'OpenERP'),
            details: $this->details($leg, $booking),
        ));

        $leg->forceFill(['service_order_sent_at' => Carbon::now()])->save();

        return $email;
    }

    /** The temporary signed signing URL for this leg. */
    public function signUrl(LimoLeg $leg): string
    {
        return URL::temporarySignedRoute(
            'limousine.service_order.sign',
            Carbon::now()->addDays(self::LINK_DAYS),
            ['leg' => $leg->id],
        );
    }

    /**
     * The handful of facts the customer needs to recognise the trip. Blank
     * fields are dropped rather than shown empty.
     *
     * @return array<string, string>
     */
    private function details(LimoLeg $leg, ?LimoBooking $booking): array
    {
        $rows = [
            (string) __('Service date') => trim(
                ($leg->start_at?->isoFormat('DD-MMM-YYYY') ?? '') . ' ' . ($leg->start_at?->isoFormat('hh:mm A') ?? '')
            ),
            (string) __('Pick up') => (string) ($leg->from_location ?? ''),
            (string) __('Drop off') => (string) ($leg->to_location ?? ''),
            (string) __('Vehicle') => (string) ($leg->vehicle ?? ''),
            (string) __("Driver's Name") => (string) ($leg->driver ?? ''),
            (string) __('Amount') => number_format((float) $leg->net_amount, 3) . ' ' . __('BHD'),
        ];

        return array_filter($rows, static fn (string $v): bool => trim($v) !== '');
    }
}
