<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;

/**
 * Download a quotation — the copy the customer is given.
 *
 * Staff-only, like the invoice and receipt: the office hands it over, prints
 * it or mails it, so there is no public link to guess at.
 */
final class LimoQuotationController
{
    public function __invoke(int $quotation, QuotationPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.quotation', Permission::Read);

        $model = LimoQuotation::query()
            ->with(['customer', 'legs'])
            ->findOrFail($quotation);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
