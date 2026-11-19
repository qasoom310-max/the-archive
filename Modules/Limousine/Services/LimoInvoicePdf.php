<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\ImportedLegCopies;

/**
 * The customer's bill: what is owed, for which journeys, and what is left.
 *
 * The office needs to hand this over, so it is a document rather than a screen.
 * It lists the LEGS behind the total — a bill that states one figure and no
 * journeys is one the customer has to ring up to understand.
 *
 * Where the legs come from depends on where the invoice came from: a trip
 * carries its own, and an invoice raised from a quote borrows the quote's,
 * because between billing and dispatch there is no trip yet. Neither is the
 * source of the TOTAL — that is the invoice's own, which stops following the
 * job once money lands.
 */
final class LimoInvoicePdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoInvoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'booking.legs', 'quotation.legs']);

        // ?? already suppresses the null property access, so no nullsafe on
        // the left: a bill with no trip falls through to the quote's legs.
        $legs = $invoice->booking->legs ?? $invoice->quotation?->legs;
        // Each line reads its parent's car type when the leg names none.
        $parent = $invoice->booking ?? $invoice->quotation;
        $legs?->each(static fn (LimoLeg $leg): LimoLeg => $leg->setRelation('legable', $parent));
        $paid = round((float) $invoice->amount_paid, 3);
        $total = round((float) $invoice->total, 3);
        // The parent's fare is the sum of its trips (plus meet & greet, taken
        // off here), so it is what tells a row the import copied from a car
        // that really went out twice.
        $lines = $this->lines(
            $legs,
            (string) ($invoice->booking->reference ?? $invoice->quotation->reference ?? ''),
            $parent instanceof LimoBooking ? $parent->legsFare() : round((float) ($parent->fare ?? 0), 3),
        );

        return [
            'invoice' => $invoice,
            'reference' => (string) ($invoice->reference ?? ''),
            // "1-Sep-2026" for the header dates, "1-9-2026" for the per-line
            // dates below — two different formats, per the owner's request.
            'issueDate' => $invoice->issue_date?->format('j-M-Y') ?? '',
            'dueDate' => $invoice->due_date?->format('j-M-Y') ?? '',
            'customerName' => (string) ($invoice->customer->name ?? ''),
            'customerPhone' => (string) ($invoice->customer->phone ?? ''),
            'customerVat' => trim((string) ($invoice->customer->vat_number ?? '')),
            // What the bill is against, so the customer can match it to a job.
            'bookingReference' => (string) ($invoice->booking->reference ?? ''),
            'quotationReference' => (string) ($invoice->quotation->reference ?? ''),
            // All of $legs comes from the SAME booking or quote (never mixed —
            // see the ??  above), so one reference covers every line.
            'lines' => [...$lines, ...$this->meetGreetLines($parent, $lines)],
            'subtotal' => round((float) $invoice->subtotal, 3),
            'discount' => round((float) $invoice->discount, 3),
            'total' => $total,
            'paid' => $paid,
            'balance' => round($total - $paid, 3),
            'status' => (string) $invoice->status,
            'notes' => (string) ($invoice->notes ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /**
     * One printable row per journey — the same fields the old system's tax
     * invoice broke out into columns (Booking #, Service, Vehicle, From, To),
     * rather than one combined description string.
     *
     * @param  \Illuminate\Support\Collection<int, LimoLeg>|null  $legs
     * @return list<array{booking: string, trip: string, service: string, vehicle: string, from: string, to: string, when: string, amount: float, extras: list<array{name: string, price: float, qty: int, amount: float}>}>
     */
    private function lines(?\Illuminate\Support\Collection $legs, string $bookingReference, float $fare): array
    {
        if ($legs === null) {
            return [];
        }

        // A cancelled trip is not on the bill (unless its money was kept), so
        // it is not printed either — the fare it is summed into leaves it out.
        $legs = $legs->filter(static fn (LimoLeg $leg): bool => $leg->isBillable());

        return ImportedLegCopies::without($legs, $fare)
            ->map(fn (LimoLeg $leg): array => $this->legRow($leg, $bookingReference))
            ->values()
            ->all();
    }

    /**
     * @return array{booking: string, trip: string, service: string, vehicle: string, from: string, to: string, when: string, amount: float, extras: list<array{name: string, price: float, qty: int, amount: float}>}
     */
    private function legRow(LimoLeg $leg, string $bookingReference): array
    {
        return [
            'booking' => $bookingReference,
            // The trip's own number, the one the bookings list shows and searches.
            'trip' => (string) ($leg->reference ?? ''),
            'service' => __(ucfirst(str_replace('_', ' ', $leg->service_type))),
            // The car TYPE the customer agreed to at booking, not whichever
            // plate the queue later assigned — same choice LimoCombinedInvoicePdf
            // makes, for the same reason: that's what they're being billed for.
            'vehicle' => $leg->billedVehicle($leg->relationLoaded('legable') ? $leg->legable : null),
            'from' => (string) ($leg->from_location ?? ''),
            'to' => (string) ($leg->to_location ?? ''),
            'when' => $leg->start_at?->format('j-n-Y') ?? '',
            // The trip itself; its extras print as their own lines under it.
            'amount' => round((float) $leg->net_amount - (float) $leg->extras_total, 3),
            'extras' => $leg->extraLines(),
        ];
    }

    /**
     * Meet & greet, once per booking, as its own line after the trips. None
     * when the booking has no trip on the bill (it is not charged then).
     *
     * @param  list<array<string, mixed>>  $tripLines
     * @return list<array{booking: string, trip: string, service: string, vehicle: string, from: string, to: string, when: string, amount: float, extras: list<array{name: string, price: float, qty: int, amount: float}>}>
     */
    private function meetGreetLines(mixed $parent, array $tripLines): array
    {
        if (! $parent instanceof LimoBooking || $tripLines === [] || $parent->meetGreetCharge() <= 0.0) {
            return [];
        }

        return [[
            'booking' => (string) ($parent->reference ?? ''),
            'trip' => '',
            'service' => (string) __('Meet & greet'),
            'vehicle' => '',
            'from' => trim((string) $parent->meet_greet_name),
            'to' => '',
            'when' => '',
            'amount' => $parent->meetGreetCharge(),
            'extras' => [],
        ]];
    }

    /** Rendered PDF bytes. */
    public function render(LimoInvoice $invoice): string
    {
        return Pdf::loadView('limousine::invoice-pdf', $this->viewData($invoice))
            ->setPaper('a4')
            ->output();
    }

    /**
     * Several invoices as one PDF — one full page per invoice, in the same
     * design {@see render()} produces. Used when more than one row is
     * ticked on the invoices list and "PDF" is pressed.
     *
     * @param  \Illuminate\Support\Collection<int, LimoInvoice>  $invoices
     */
    public function renderMany(\Illuminate\Support\Collection $invoices): string
    {
        return Pdf::loadView('limousine::invoices-batch-pdf', [
            'invoices' => $invoices->map(fn (LimoInvoice $invoice): array => $this->viewData($invoice))->all(),
        ])
            ->setPaper('a4')
            ->output();
    }

    public function filename(LimoInvoice $invoice): string
    {
        return 'invoice-' . str_replace(['/', '\\', ' '], '-', (string) $invoice->reference) . '.pdf';
    }

    /** Filename for a batch download of several ticked invoices. */
    public function filenameForMany(int $count): string
    {
        return 'invoices-' . $count . '-' . now()->format('Ymd-His') . '.pdf';
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
