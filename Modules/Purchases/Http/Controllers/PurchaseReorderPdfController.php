<?php

declare(strict_types=1);

namespace Modules\Purchases\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Purchases\Services\PurchaseReorderData;

/**
 * Renders the Reorder Report as a downloadable PDF (DomPDF) for the buying
 * team — same scope/search as the on-screen view.
 */
final class PurchaseReorderPdfController extends Controller
{
    public function __invoke(Request $request, PurchaseReorderData $data): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Read);

        $search = (string) $request->query('search', '');

        $pdf = Pdf::loadView('purchases::reorder-pdf', [
            'rows' => $data->rows($search),
            'summary' => $data->summary($search),
            'threshold' => $data->threshold(),
            'generatedAt' => Carbon::now(),
        ])->setPaper('a4');

        return $pdf->download('reorder-report.pdf');
    }
}
