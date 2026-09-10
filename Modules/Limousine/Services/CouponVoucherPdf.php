<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoCoupon;

/**
 * The customer's copy of a refund coupon — their receipt for money paid on a
 * trip called off too late to be refunded.
 *
 * It exists because the credit is otherwise only a row in our system: the
 * customer paid, got no journey and no money back, and holds nothing to show
 * for it. This is that thing. It carries the code they quote, what the credit
 * was worth, what is LEFT of it, and the date it runs out.
 *
 * The balance, not the face value — a coupon is spent in pieces, and a voucher
 * still claiming 100 BD after 40 was used would be worse than no voucher.
 */
final class CouponVoucherPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(LimoCoupon $coupon): array
    {
        $coupon->loadMissing(['customer', 'redemptions']);

        $issued = round((float) $coupon->amount, 3);
        $remaining = $coupon->remaining();

        return [
            'coupon' => $coupon,
            'code' => (string) $coupon->code,
            'customerName' => (string) ($coupon->customer->name ?? ''),
            'customerPhone' => (string) ($coupon->customer->phone ?? ''),
            'fromTrip' => (string) ($coupon->leg_reference ?? ''),
            'issuedOn' => $coupon->created_at?->isoFormat('DD-MMM-YYYY') ?? '',
            'expiresOn' => $coupon->expires_at?->isoFormat('DD-MMM-YYYY') ?? '',
            'issued' => $issued,
            'used' => round($issued - $remaining, 3),
            'remaining' => $remaining,
            'expired' => $coupon->isExpired(),
            'redemptions' => $coupon->redemptions,
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'companyPhone' => (string) Setting::get('company.phone', ''),
            'companyEmail' => (string) Setting::get('company.email', ''),
            'logoPath' => $this->logoPath(),
            'logoScale' => $this->logoScale(),
        ];
    }

    /** Rendered PDF bytes. */
    public function render(LimoCoupon $coupon): string
    {
        return Pdf::loadView('limousine::coupon-voucher-pdf', $this->viewData($coupon))
            ->setPaper('a4')
            ->output();
    }

    public function filename(LimoCoupon $coupon): string
    {
        return 'coupon-' . str_replace(['/', '\\', ' '], '-', (string) $coupon->code) . '.pdf';
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
