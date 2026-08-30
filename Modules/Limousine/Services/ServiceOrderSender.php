<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Modules\Limousine\Mail\ServiceOrderCompanyMail;
use Modules\Limousine\Mail\ServiceOrderMail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;

/**
 * Tells the customer their trip is done — in the way that suits who they are.
 *
 * An INDIVIDUAL travelled in the car, so they are asked to sign: their
 * signature is the proof the driver reached them. A COMPANY did not travel; it
 * booked on behalf of a guest, and cannot sign for a journey it wasn't on. It is
 * INFORMED instead — "the driver has reached your customer" — at its service
 * address, which is usually a different person from whoever placed the booking.
 *
 * The signing link is a temporary signed URL: its own signature is the
 * credential, so a customer needs no account, editing the leg id breaks it
 * rather than reaching another trip, and it expires so an old mail can't be
 * replayed months later.
 */
final class ServiceOrderSender
{
    /** How long a signing link stays valid. */
    public const LINK_DAYS = 30;

    public const KIND_SIGN = 'sign';

    public const KIND_NOTICE = 'notice';

    /**
     * Send the right message for this leg's customer.
     *
     * Returns what was sent and to whom, or null when there is no address to
     * send to — the caller says so rather than silently doing nothing.
     *
     * @return array{email: string, kind: string}|null
     */
    public function send(LimoLeg $leg): ?array
    {
        $leg->loadMissing('legable.customer');

        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;
        if ($booking === null) {
            return null;
        }

        $customer = $booking->customer;
        $companyName = (string) Setting::get('company.name', 'OpenERP');

        if ($customer !== null && $customer->isCompany()) {
            $email = $customer->serviceEmail();
            if ($email === null) {
                return null;
            }

            Mail::to($email)->send(new ServiceOrderCompanyMail(
                leg: $leg,
                companyName: $companyName,
                customerName: (string) $customer->name,
                details: $this->details($leg),
            ));

            $this->markSent($leg);

            return ['email' => $email, 'kind' => self::KIND_NOTICE];
        }

        $email = $this->individualEmail($booking, $customer);
        if ($email === null) {
            return null;
        }

        Mail::to($email)->send(new ServiceOrderMail(
            leg: $leg,
            signUrl: $this->signUrl($leg),
            companyName: $companyName,
            details: $this->details($leg),
        ));

        $this->markSent($leg);

        return ['email' => $email, 'kind' => self::KIND_SIGN];
    }

    /** Whether this leg's customer signs (individual) or is just told (company). */
    public function isSignable(LimoLeg $leg): bool
    {
        $leg->loadMissing('legable.customer');

        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;
        if ($booking === null) {
            return false;
        }

        $customer = $booking->customer;

        return $customer === null || ! $customer->isCompany();
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

    private function markSent(LimoLeg $leg): void
    {
        $leg->forceFill(['service_order_sent_at' => Carbon::now()])->save();
    }

    /**
     * The booking's own address wins — it is the one captured for this job —
     * with the customer record as the fallback.
     */
    private function individualEmail(LimoBooking $booking, ?LimoCustomer $customer): ?string
    {
        $email = $booking->email;
        if (is_string($email) && trim($email) !== '') {
            return trim($email);
        }

        if ($customer === null) {
            return null;
        }

        $email = $customer->email;

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * The handful of facts that identify the trip. Blank fields are dropped
     * rather than shown empty.
     *
     * @return array<string, string>
     */
    private function details(LimoLeg $leg): array
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
