<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Services\RentalAgreementPdf;

/**
 * Downloads the self-contained Car Hire Agreement PDF — the same document that
 * gets emailed, for when the customer has no email on file or you want a copy.
 */
final class RentalAgreementPdfController extends Controller
{
    public function __invoke(int $id, RentalAgreementPdf $builder): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.order', Permission::Read);

        $order = RentalOrder::query()->findOrFail($id);

        return Pdf::loadView('rental::agreement-pdf', $builder->viewData($order))
            ->setPaper('a4')
            ->download('agreement-' . Str::slug((string) $order->reference) . '.pdf');
    }
}
