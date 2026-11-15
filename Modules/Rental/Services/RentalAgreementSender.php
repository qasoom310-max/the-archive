<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Rental\Mail\RentalAgreementMail;
use Modules\Rental\Models\RentalOrder;

/**
 * Email an order's agreement PDF to its customer, once. Shared by the order
 * page and the orders list's row menu so the two can't drift.
 */
final class RentalAgreementSender
{
    /** The message to show the person who pressed the button. */
    public function send(RentalOrder $order): string
    {
        // Send once only.
        if ($order->agreement_emailed_at !== null) {
            return __('The agreement was already emailed to this customer.');
        }

        $order->loadMissing('customer', 'vehicle', 'branch');
        $email = $order->customer?->email;
        if (! is_string($email) || trim($email) === '') {
            return __('This customer has no email — add one to send the agreement.');
        }

        $pdf = app(RentalAgreementPdf::class)->render($order);
        Mail::to(trim($email))->send(new RentalAgreementMail($pdf, $order, (string) Setting::get('company.name', 'OpenERP')));

        $order->agreement_emailed_at = Carbon::now();
        $order->save();

        return __('Agreement emailed to :email.', ['email' => trim($email)]);
    }
}
