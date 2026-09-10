<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * The Service Order — one trip on one page: who it was for, when, which car and
 * driver, where from and to, and what it cost, with room for the signatures that
 * close it out.
 *
 * Built per LEG, not per booking: a booking can carry several trips on different
 * days, and this is the sheet that rides along with ONE of them. The customer's
 * signature on it is the evidence that the driver arrived and the service was
 * used, so once signed it is rendered onto the document rather than left as a
 * blank line.
 */
final class ServiceOrderPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoLeg $leg): array
    {
        $leg->loadMissing('legable');

        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        // A leg can in principle hang off a quotation rather than a booking, so
        // the customer-facing fields are read explicitly instead of chained —
        // no booking simply means those lines print blank.
        $customerName = '';
        $customerPhone = '';
        $bookingReference = '';
        $flightNumber = '';
        $paxName = '';
        $paxContact = '';
        $bookingNotes = '';

        if ($booking !== null) {
            $booking->loadMissing('customer');
            $customer = $booking->customer;

            if ($customer !== null) {
                $customerName = (string) $customer->name;
                $customerPhone = (string) ($customer->phone ?? '');
            }

            $bookingReference = (string) ($booking->reference ?? '');
            $flightNumber = (string) ($booking->flight_number ?? '');
            $paxName = (string) ($booking->pax_name ?? '');
            $paxContact = (string) ($booking->pax_contact ?? '');
            $bookingNotes = (string) ($booking->notes ?? '');
        }

        $remark = (string) ($leg->notes ?? '');

        return [
            'leg' => $leg,
            'booking' => $booking,
            'customerName' => $customerName,
            'customerPhone' => $customerPhone,
            // The office's own reference for the trip; the booking's number is
            // shown under it so a customer quoting either can be found.
            'confirmationNo' => (string) ($leg->reference ?? ''),
            'bookingReference' => $bookingReference,
            'serviceDate' => $leg->start_at?->isoFormat('DD-MMM-YYYY') ?? '',
            'serviceTime' => $leg->start_at?->isoFormat('hh:mm A') ?? '',
            'vehicle' => (string) ($leg->vehicle ?? ''),
            'driverName' => (string) ($leg->driver ?? ''),
            'flightNumber' => $flightNumber,
            'paxName' => $paxName,
            'paxContact' => $paxContact,
            'pickup' => (string) ($leg->from_location ?? ''),
            'dropoff' => (string) ($leg->to_location ?? ''),
            'amount' => (float) $leg->net_amount,
            'remark' => $remark !== '' ? $remark : $bookingNotes,
            'issuedOn' => now()->isoFormat('DD-MMM-YYYY'),
            // Rendered signature, when the customer has already signed.
            'signatureData' => $this->signatureData($leg),
            'signedAt' => $leg->signed_at?->isoFormat('DD-MMM-YYYY hh:mm A'),
            'signedName' => (string) ($leg->signed_name ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /** Rendered PDF bytes. */
    public function render(LimoLeg $leg): string
    {
        return Pdf::loadView('limousine::service-order-pdf', $this->viewData($leg))
            ->setPaper('a4')
            ->output();
    }

    /** Suggested filename, e.g. `service-order-10001.pdf`. */
    public function filename(LimoLeg $leg): string
    {
        $ref = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($leg->reference ?? (string) $leg->id));

        return 'service-order-' . trim((string) $ref, '-') . '.pdf';
    }

    /**
     * The signature as a base64 data URI. DomPDF cannot fetch a URL, so the
     * bytes are embedded — that also means an archived PDF keeps the signature
     * even if the file is later moved.
     */
    private function signatureData(LimoLeg $leg): ?string
    {
        if (! $leg->isSigned()) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = (string) $leg->signature_path;

        if (! $disk->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) $disk->get($path));
    }

    /** Logo size as a percent of default, clamped to a sane 50–400 %. */
    private function logoScale(): int
    {
        $raw = (int) Setting::get('company.logo_scale', 100);

        return max(50, min(400, $raw > 0 ? $raw : 100));
    }

    /** Filesystem path to the company logo, or null when absent/missing. */
    private function logoPath(): ?string
    {
        $rel = Setting::get('company.logo');
        if (! is_string($rel) || $rel === '') {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($rel) ? $disk->path($rel) : null;
    }
}
