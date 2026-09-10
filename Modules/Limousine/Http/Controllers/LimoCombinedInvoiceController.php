<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Services\LimoCombinedInvoicePdf;

/**
 * Download several bills as one document.
 *
 * The ids come off the query string so the link is a plain download the browser
 * can handle, rather than something the page has to stream itself. They are
 * re-read from the database rather than trusted, and refused when they are not
 * all one customer's — a document addressed to two companies is not a document.
 */
final class LimoCombinedInvoiceController
{
    public function __invoke(Request $request, LimoCombinedInvoicePdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.invoice', Permission::Read);

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $invoices = LimoInvoice::query()
            ->with(['booking.legs', 'customer'])
            ->whereIn('id', $ids)
            // Oldest first: a schedule of a month's work reads forwards.
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        abort_if($invoices->isEmpty(), 404);
        abort_if($invoices->pluck('customer_id')->unique()->count() > 1, 422, __('A combined invoice belongs to one customer.'));

        return response($pdf->render($pdf->viewData($invoices)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $pdf->filename($invoices) . '"',
        ]);
    }
}
