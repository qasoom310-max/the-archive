<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoReceiptPdf;

/**
 * Download a receipt — the customer's proof they paid.
 *
 * Staff-only, like the coupon voucher: it is handed over, printed or mailed by
 * the office rather than fetched by the customer, so there is no public link to
 * guess at. The same document goes out as the mail attachment.
 */
final class LimoReceiptController
{
    public function __invoke(int $receipt, LimoReceiptPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.receipt', Permission::Read);

        $model = LimoReceipt::query()->with(['customer', 'invoice', 'booking'])->findOrFail($receipt);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
