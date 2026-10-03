<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Branding\Logo;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Services\PosReceiptImageRenderer;

/**
 * Renders a browser-printable receipt for a finalised order — the target
 * of the "print" icon on the Orders list. Reuses the exact view-data the
 * WhatsApp PNG receipt is built from, so the printed slip matches the one
 * the customer receives on their phone.
 */
final class PosReceiptPrintController extends Controller
{
    public function __invoke(PosReceiptImageRenderer $renderer, int $id): View
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', Permission::Read);

        $order = PosOrder::query()
            ->with('lines', 'payments.method', 'partner')
            ->findOrFail($id);

        // The shared view data carries the logo as a FILE path (DomPDF reads it
        // off disk); a browser needs its public URL instead.
        return view('pos::receipt-print', [
            ...$renderer->receiptViewData($order),
            'logoUrl' => Logo::url(),
        ]);
    }
}
