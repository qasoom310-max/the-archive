<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;

/**
 * Download a quotation — the copy the customer is given.
 *
 * Staff-only, like the invoice and receipt: the office hands it over, prints
 * it or mails it, so there is no public link to guess at.
 *
 * `?without_total=1` hands over the copy with no Subtotal/Total box — the one
 * the office sends when the customer is choosing between the journeys quoted
 * rather than buying all of them.
 */
final class LimoQuotationController
{
    public function __invoke(Request $request, int $quotation, QuotationPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.quotation', Permission::Read);

        $model = LimoQuotation::query()
            ->with(['customer', 'legs'])
            ->findOrFail($quotation);

        $withoutTotal = $request->boolean('without_total');

        return response($pdf->render($model, $withoutTotal), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($model, $withoutTotal) . '"',
        ]);
    }
}
