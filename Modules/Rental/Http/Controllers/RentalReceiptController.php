<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Services\RentalReceiptPdf;

/**
 * Download a receipt — the customer's proof they paid.
 *
 * Staff-only: the office hands it over, prints it or mails it, so there is
 * no public link to guess at.
 */
final class RentalReceiptController
{
    public function __invoke(int $receipt, RentalReceiptPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.receipt', Permission::Read);

        $model = RentalReceipt::query()
            ->with(['customer', 'invoice.order'])
            ->findOrFail($receipt);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
