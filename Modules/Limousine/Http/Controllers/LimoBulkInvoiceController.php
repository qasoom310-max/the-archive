<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoBulkInvoice;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Services\LimoCombinedInvoicePdf;

/**
 * Download a saved bulk invoice.
 *
 * The same document as the combined invoice, with the bulk invoice's own
 * number and date on it, built from the invoices it holds today.
 */
final class LimoBulkInvoiceController
{
    public function __invoke(int $bulk, LimoCombinedInvoicePdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.invoice', Permission::Read);

        $model = LimoBulkInvoice::query()->findOrFail($bulk);

        $invoices = LimoInvoice::query()
            ->with(['booking.legs', 'customer'])
            ->where('bulk_invoice_id', $model->id)
            ->orderByRaw('COALESCE(service_date, issue_date)')
            ->orderBy('id')
            ->get();

        // A cancelled one holds no invoices any more.
        abort_if($invoices->isEmpty(), 404);

        $data = $pdf->viewData($invoices, $model->period_from?->toDateString(), $model->period_to?->toDateString());
        $data['bulkReference'] = (string) $model->reference;
        $data['issuedOn'] = $model->issue_date ?? $model->created_at;

        $name = 'tax-invoice-' . str_replace(['/', '\\', ' '], '-', (string) $model->reference) . '.pdf';

        return response($pdf->render($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }
}
