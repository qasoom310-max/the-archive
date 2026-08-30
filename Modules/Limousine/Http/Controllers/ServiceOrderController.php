<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\ServiceOrderPdf;

/**
 * Download a leg's Service Order as a PDF — the sheet that rides along with the
 * trip. Staff-only; the customer's copy goes out as an emailed signing link
 * instead (see {@see ServiceOrderSignController}).
 */
final class ServiceOrderController
{
    public function __invoke(int $leg, ServiceOrderPdf $pdf): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.booking', Permission::Read);

        $model = LimoLeg::query()->with('legable.customer')->findOrFail($leg);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdf->filename($model) . '"',
        ]);
    }
}
