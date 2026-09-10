<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;

/**
 * The customer's bill for a rental — same visual family as the Limousine
 * invoice/receipt/quotation reference template, with the item-table columns
 * the owner pointed at on the old system's tax invoice: Service / Vehicle /
 * Period (From/To) / Days-Trips / Rate per Unit / Amount, and a Subtotal/
 * Discount/VAT/Total/Received/Balance box.
 *
 * A rental invoice bills exactly ONE order (a customer takes ONE vehicle for
 * ONE period) — unlike Limousine's multi-leg trips — so there is at most a
 * single item line, built from the order. An invoice with no linked order
 * (a legacy row imported with no order behind it) falls back to a single
 * "Rental services" line naming the invoice's own subtotal, the same honest
 * fallback the Limousine invoice uses for a booking/quotation-less invoice.
 */
final class RentalInvoicePdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(RentalInvoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'order.vehicle']);
        $order = $invoice->order;

        $subtotal = round((float) $invoice->subtotal, 3);
        $discount = round((float) $invoice->discount, 3);
        $total = round((float) $invoice->total, 3);
        $paid = round((float) $invoice->amount_paid, 3);

        return [
            'invoice' => $invoice,
            'reference' => (string) ($invoice->reference ?? ''),
            'issueDate' => $invoice->issue_date?->format('j-M-Y') ?? '',
            'dueDate' => $invoice->due_date?->format('j-M-Y') ?? '',
            'vatRegistrationNo' => trim((string) Setting::get('company.vat_number', '')),
            'customerName' => (string) ($invoice->customer->name ?? ''),
            'customerPhone' => (string) ($invoice->customer->phone ?? ''),
            // Plain "->" throughout, not "?->": PHP's ?? already walks the
            // chain via isset() semantics, safely short-circuiting even when
            // $order (or a further step) is null — a nullsafe operator here
            // would be redundant (PHPStan: nullsafe.neverNull). The one
            // exception is a METHOD call (fuelChargeTotal() below), which
            // isset()-style chaining does NOT protect — that one keeps "?->".
            'orderReference' => (string) ($order->reference ?? ''),
            'lines' => $this->lines($order),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'deliveryCharges' => round((float) ($order->delivery_charges ?? 0.0), 3),
            'extraCharge' => round((float) ($order->extra_charge ?? 0.0), 3),
            'fuelCharge' => round((float) ($order?->fuelChargeTotal() ?? 0.0), 3),
            'vatRate' => round((float) ($order->vat_rate ?? RentalOrder::DEFAULT_VAT_RATE), 2),
            'vatAmount' => round((float) ($order->vat_amount ?? 0.0), 3),
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
     * @return list<array<string, mixed>>
     */
    private function lines(?RentalOrder $order): array
    {
        if ($order === null) {
            return [];
        }

        return [$this->orderRow($order)];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(RentalOrder $order): array
    {
        $vehicle = $order->vehicle;
        $carType = trim(trim((string) ($vehicle->make ?? '')) . ' ' . trim((string) ($vehicle->model ?? '')));

        return [
            'service' => __('Rental'),
            'vehicle' => trim(trim((string) ($vehicle->plate_no ?? '')) . ' ' . $carType),
            'from' => $order->start_date?->format('j-n-Y') ?? '',
            'to' => $order->end_date?->format('j-n-Y') ?? '',
            'units' => $order->billableUnits(),
            'unitLabel' => $this->periodUnitLabel($order->rate_type),
            'rate' => round((float) $order->rate, 3),
            'amount' => round((float) $order->subtotal, 3),
        ];
    }

    /** "days" / "weeks" / "months", matching the order's billing period. */
    private function periodUnitLabel(string $rateType): string
    {
        return match ($rateType) {
            'weekly' => __('weeks'),
            'monthly' => __('months'),
            default => __('days'),
        };
    }

    public function render(RentalInvoice $invoice): string
    {
        return Pdf::loadView('rental::invoice-pdf', $this->viewData($invoice))
            ->setPaper('a4')
            ->output();
    }

    /**
     * Several invoices as one PDF — one full page per invoice, in the same
     * design {@see render()} produces. Used when more than one row is ticked
     * on the invoices list and "PDF" is pressed.
     *
     * @param  Collection<int, RentalInvoice>  $invoices
     */
    public function renderMany(Collection $invoices): string
    {
        return Pdf::loadView('rental::invoices-batch-pdf', [
            'invoices' => $invoices->map(fn (RentalInvoice $invoice): array => $this->viewData($invoice))->all(),
        ])
            ->setPaper('a4')
            ->output();
    }

    public function filename(RentalInvoice $invoice): string
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
