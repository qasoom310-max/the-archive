<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Money\Currencies;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Services\PosStockReportData;
use Modules\Pos\Support\StockRow;
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

            fputcsv($out, ['Item', 'Type', 'Category', 'Unit', 'On hand', 'Cost', 'Value', 'Reorder point', 'Status', 'Active']);

            foreach ($data->rows($filter, $search, $includeInactive) as $row) {
                /** @var StockRow $row */
                fputcsv($out, [
                    $row->name,
                    $row->isCondiment() ? 'Add-on' : 'Product',
                    $row->category ?? '',
                    $row->unit !== '' ? $row->unit : 'qty',
                    $this->num($row->stock),
                    Currencies::format($row->cost),
                    Currencies::format($row->value),
                    $row->reorderPoint !== null ? $this->num($row->reorderPoint) : '',
                    $labels[$row->status] ?? '',
                    $row->active ? 'Yes' : 'No',
                ]);
            }

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
