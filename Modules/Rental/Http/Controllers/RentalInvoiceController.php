<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Services\RentalInvoicePdf;

/**
 * Download an invoice — the copy the customer is given.
 *
 * Staff-only: the office hands it over, prints it or mails it, so there is
 * no public link to guess at.
 */
final class RentalInvoiceController
{
    public function __invoke(int $invoice, RentalInvoicePdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.invoice', Permission::Read);

        $model = RentalInvoice::query()
            ->with(['customer', 'order.vehicle'])
            ->findOrFail($invoice);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
