<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Services\RentalQuotationPdf;

/**
 * Download a quotation — the copy the customer is given.
 *
 * Staff-only: the office hands it over, prints it or mails it, so there is
 * no public link to guess at.
 */
final class RentalQuotationController
{
    public function __invoke(int $quotation, RentalQuotationPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.quotation', Permission::Read);

        $model = RentalQuotation::query()
            ->with(['customer', 'vehicle'])
            ->findOrFail($quotation);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
