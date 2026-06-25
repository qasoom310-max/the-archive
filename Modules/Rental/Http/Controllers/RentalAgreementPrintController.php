<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Models\RentalOrder;

/**
 * Browser-printable Car Hire Agreement — an OVERLAY that drops the order's
 * values at fixed positions so they land in the boxes of the pre-printed
 * WANAAN continuous form. Opened in a new tab by the "Print agreement" button;
 * append `?grid=1` to show a mm ruler and field outlines while calibrating.
 */
final class RentalAgreementPrintController extends Controller
{
    public function __invoke(int $id): View
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.order', Permission::Read);

        $order = RentalOrder::query()
            ->with(['customer', 'vehicle', 'branch'])
            ->findOrFail($id);

        return view('rental::agreement-print', [
            'order' => $order,
            'calibrate' => request()->boolean('grid'),
        ]);
    }
}
