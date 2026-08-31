<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Services\CouponVoucherPdf;

/**
 * Download a coupon voucher — the customer's receipt for credit held against a
 * cancelled trip.
 *
 * Staff-only, and deliberately: it is handed over or emailed by the office
 * rather than fetched by the customer, so there is no public link to guess at
 * and no expiry to explain. The same document goes out as the mail attachment.
 */
final class CouponVoucherController
{
    public function __invoke(int $coupon, CouponVoucherPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.booking', Permission::Read);

        $model = LimoCoupon::query()->with(['customer', 'redemptions'])->findOrFail($coupon);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
