<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Branding\Logo;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Services\PosReceiptImageRenderer;

/**
 * Renders a browser-printable receipt for a finalised order — the target
 * of the "print" icon on the Orders list. Reuses the exact view-data the
 * WhatsApp PNG receipt is built from, so the printed slip matches the one
 * the customer receives on their phone.
 *
 * `?format=json` returns the same receipt as data, which the till draws and
 * sends straight to a network receipt printer ({@see \Modules\Pos\Support\ReceiptPrinter}).
 */
final class PosReceiptPrintController extends Controller
{
    public function __invoke(PosReceiptImageRenderer $renderer, int $id, ?Request $request = null): View|JsonResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', Permission::Read);

        $order = PosOrder::query()
            ->with('lines', 'payments.method', 'partner')
            ->findOrFail($id);

        $data = $renderer->receiptViewData($order);

        if (($request ?? request())->query('format') === 'json') {
            return response()->json([
                // The shared data's logo is a file path for DomPDF; never send it out.
                ...Arr::except($data, ['logoPath']),
                'logoUrl' => Logo::url(),
                'rtl' => app()->getLocale() === 'ar',
                'labels' => [
                    'phone' => __('Phone:'),
                    'deliveryRef' => __('Delivery ref'),
                    'subtotal' => __('Subtotal'),
                    'tax' => __('Tax'),
                    'customerDiscount' => __('Customer discount'),
                    'delivery' => __('Delivery'),
                    'total' => __('Total'),
                    'change' => __('Change'),
                    'thanks' => __('Thank you!'),
                ],
            ]);
        }

        // The shared view data carries the logo as a FILE path (DomPDF reads it
        // off disk); a browser needs its public URL instead.
        return view('pos::receipt-print', [
            ...$data,
            'logoUrl' => Logo::url(),
        ]);
    }
}
