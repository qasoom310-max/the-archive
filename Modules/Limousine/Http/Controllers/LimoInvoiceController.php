<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Services\LimoInvoicePdf;

/**
 * Download an invoice — the copy the customer is given.
 *
 * Staff-only, like the receipt: the office hands it over, prints it or mails
 * it, so there is no public link to guess at.
 */
final class LimoInvoiceController
{
    public function __invoke(int $invoice, LimoInvoicePdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.invoice', Permission::Read);

        $model = LimoInvoice::query()
            ->with(['customer', 'booking.legs', 'quotation.legs'])
            ->findOrFail($invoice);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
