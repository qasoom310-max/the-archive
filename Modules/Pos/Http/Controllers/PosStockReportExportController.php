<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Money\Currencies;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Services\PosStockReportData;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the Stock Report as CSV, honouring the same filter / search /
 * include-inactive scope as the on-screen view (passed as query params).
 */
final class PosStockReportExportController extends Controller
{
    public function __invoke(Request $request, PosStockReportData $data): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Read);

        $filter = (string) $request->query('filter', '');
        $search = (string) $request->query('search', '');
        $includeInactive = (string) $request->query('inactive', '0') === '1';

        $labels = ['in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];

        $response = new StreamedResponse(function () use ($data, $filter, $search, $includeInactive, $labels): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['Product', 'Category', 'Unit', 'On hand', 'Cost', 'Value', 'Reorder point', 'Status', 'Active']);

            $data->query($filter, $search, $includeInactive)
                ->chunk(200, function ($products) use ($out, $data, $labels): void {
                    foreach ($products as $product) {
                        /** @var PosProduct $product */
                        fputcsv($out, [
                            $product->name,
                            $product->category_name ?? '',
                            $product->unit ?? 'qty',
                            $this->num((float) $product->stock_on_hand),
                            Currencies::format((float) $product->cost_price),
                            Currencies::format($product->stockValue()),
                            $product->reorder_point !== null ? $this->num((float) $product->reorder_point) : '',
                            $labels[$data->status($product)] ?? '',
                            $product->active ? 'Yes' : 'No',
                        ]);
                    }
                });

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="stock-report.csv"');

        return $response;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
