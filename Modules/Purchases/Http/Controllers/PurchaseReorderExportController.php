<?php

declare(strict_types=1);

namespace Modules\Purchases\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Purchases\Services\PurchaseReorderData;
use Modules\Purchases\Support\ReorderRow;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the Reorder Report as CSV for the buying team, honouring the same
 * search scope as the on-screen view (passed as a query param).
 */
final class PurchaseReorderExportController extends Controller
{
    public function __invoke(Request $request, PurchaseReorderData $data): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'purchases.purchase', Permission::Read);

        $search = (string) $request->query('search', '');
        $threshold = $data->threshold();

        $statusLabels = ['low' => 'Low stock', 'out' => 'Out of stock'];
        $typeLabels = ['product' => 'Product', 'ingredient' => 'Ingredient', 'condiment' => 'Add-on'];

        $response = new StreamedResponse(function () use ($data, $search, $threshold, $statusLabels, $typeLabels): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['Item', 'Type', 'Category', 'Unit', 'Current stock', 'Minimum', 'Vendor', 'Phone', 'Status']);

            foreach ($data->rows($search) as $row) {
                /** @var ReorderRow $row */
                $item = $row->item;
                $min = $item->reorderPoint ?? $threshold;

                fputcsv($out, [
                    $item->name,
                    $typeLabels[$item->type] ?? 'Product',
                    $item->category ?? '',
                    $item->unit !== '' ? $item->unit : 'qty',
                    $this->num($item->stock),
                    $this->num($min),
                    $row->vendorName ?? '',
                    $row->vendorPhone ?? '',
                    $statusLabels[$item->status] ?? '',
                ]);
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="reorder-report.csv"');

        return $response;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
