<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Limousine\Mail\CouponVoucherMail;
use Modules\Limousine\Models\LimoCoupon;

/**
 * Sends a customer their coupon, with the voucher attached.
 *
 * The address is passed IN rather than read from the customer, because the one
 * on file is often not the one that should get this: a booking is placed by
 * whoever happened to call, and the credit belongs to whoever paid. The office
 * is shown the address we hold, and corrects it when it is wrong.
 *
 * Whatever is finally used is written back to the customer, so the correction
 * only has to be made once — the next coupon, and the next, already know it.
 */
final class CouponSender
{
    /**
     * The address to offer for this coupon: the one it last went to, else the
     * customer's own. Blank when we hold none, which the caller reports rather
     * than sending into the dark.
     */
    public function suggestedEmail(LimoCoupon $coupon): string
    {
        $coupon->loadMissing('customer');

        $candidates = [
            $coupon->sent_to,
            $coupon->customer?->email,
            $coupon->customer?->service_email,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * Send it, and remember that we did.
     *
     * @return array{email: string, resent: bool}
     */
    public function send(LimoCoupon $coupon, string $email): array
    {
        $coupon->loadMissing(['customer', 'redemptions']);

        $email = trim($email);
        $resent = $coupon->sent_at !== null;

        $pdf = app(CouponVoucherPdf::class);
        $company = (string) Setting::get('company.name', config('app.name'));

        Mail::to($email)->send(new CouponVoucherMail(
            coupon: $coupon,
            companyName: $company,
            remaining: $coupon->remaining(),
            pdf: $pdf->render($coupon),
            filename: $pdf->filename($coupon),
        ));

        $coupon->forceFill([
            'sent_at' => Carbon::now(),
            'sent_to' => $email,
        ])->save();

        // Learned for next time: the address the office corrected to is the one
        // this customer should be reached at.
        $customer = $coupon->customer;
        if ($customer !== null && trim((string) $customer->email) === '') {
            $customer->forceFill(['email' => $email])->save();
        }

        return ['email' => $email, 'resent' => $resent];
    }
}
