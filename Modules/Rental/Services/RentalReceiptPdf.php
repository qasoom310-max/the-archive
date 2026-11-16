<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReceipt;

/**
 * The customer's proof of payment — same reference-template design as the
 * Rental invoice/quotation and the Limousine receipt (gold band, title +
 * meta cells, From/Received-from blocks, ruled item table, summary box),
 * with the fields the owner pointed at on the old system's printed receipt:
 * Receipt No. / Received with thanks from (+ CPR) / a sum-of breakdown /
 * Rental Agreement # / payment method / Remarks.
 *
 * The breakdown (rental + VAT + extra) is read from the linked invoice's
 * order and shown ONLY when it actually foots to this receipt's own amount
 * — a partial payment against a bigger invoice would otherwise print a
 * breakdown that doesn't match what was really handed over, which is worse
 * than no breakdown at all.
 */
final class RentalReceiptPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(RentalReceipt $receipt): array
    {
        $receipt->loadMissing(['customer', 'invoice.order.vehicle']);
        $order = $receipt->invoice?->order;
        $amount = round((float) $receipt->amount, 3);

        return [
            'receipt' => $receipt,
            'reference' => (string) ($receipt->reference ?? ''),
            'date' => $receipt->date?->format('j-M-Y') ?? '',
            'agreementReference' => (string) ($order->reference ?? ''),
            'customerName' => (string) ($receipt->customer->name ?? ''),
            'customerCpr' => (string) ($receipt->customer->cpr ?? ''),
            'customerPhone' => (string) ($receipt->customer->phone ?? ''),
            'customerVat' => trim((string) ($receipt->customer->vat_number ?? '')),
            'method' => __(ucfirst((string) $receipt->method)),
            'description' => $this->description($order),
            'period' => $this->period($order),
            'amount' => $amount,
            ...$this->breakdown($order, $amount),
            'notes' => (string) ($receipt->notes ?? ''),
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /**
     * What the money was for: the car, e.g. "Car rental: Ford Taurus (111629)".
     * Empty when the receipt is tied to no order (imported, or a stand-alone invoice).
     */
    private function description(?RentalOrder $order): string
    {
        if ($order === null) {
            return '';
        }

        $vehicle = $order->vehicle;
        $car = trim(trim((string) ($vehicle->make ?? '')) . ' ' . trim((string) ($vehicle->model ?? '')));
        if ($car === '') {
            $car = trim((string) ($vehicle->name ?? ''));
        }
        $plate = trim((string) ($vehicle->plate_no ?? ''));

        if ($car === '' && $plate === '') {
            return __('Car rental');
        }

        return __('Car rental') . ': ' . trim($car . ($plate !== '' ? ' (' . $plate . ')' : ''));
    }

    /** The rental period, e.g. "16-Nov-2026 to 19-Nov-2026 · 3 days"; empty without both dates. */
    private function period(?RentalOrder $order): string
    {
        if ($order?->start_date === null || $order->end_date === null) {
            return '';
        }

        $days = $order->durationDays();

        return __(':from to :to', ['from' => $order->start_date->format('j-M-Y'), 'to' => $order->end_date->format('j-M-Y')])
            . ' · ' . trans_choice(':count day|:count days', $days, ['count' => $days]);
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdown(?RentalOrder $order, float $amount): array
    {
        $none = ['showBreakdown' => false, 'principal' => 0.0, 'vatRate' => 0.0, 'vatAmount' => 0.0, 'extra' => 0.0];
        if ($order === null) {
            return $none;
        }

        $principal = round(max(0.0, (float) $order->subtotal - (float) $order->discount), 3);
        $vatAmount = round((float) $order->vat_amount, 3);
        $extra = round((float) $order->delivery_charges + max(0.0, (float) $order->extra_charge) + $order->fuelChargeTotal(), 3);

        // Only trust the breakdown when it actually foots to what this
        // receipt recorded — otherwise a partial payment would print
        // components that don't add up to the amount handed over.
        if (abs($principal + $vatAmount + $extra - $amount) > 0.005) {
            return $none;
        }

        return [
            'showBreakdown' => true,
            'principal' => $principal,
            'vatRate' => round((float) $order->vat_rate, 2),
            'vatAmount' => $vatAmount,
            'extra' => $extra,
        ];
    }

    public function render(RentalReceipt $receipt): string
    {
        return Pdf::loadView('rental::receipt-pdf', $this->viewData($receipt))
            ->setPaper('a4')
            ->output();
    }

    public function filename(RentalReceipt $receipt): string
    {
        return 'receipt-' . str_replace(['/', '\\', ' '], '-', (string) $receipt->reference) . '.pdf';
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
