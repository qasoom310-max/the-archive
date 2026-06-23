<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Money\Currencies;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Services\PosStockReportData;
use Modules\Pos\Support\StockRow;

/**
 * Browser-printable Stock Report (auto-opens the print dialog), honouring
 * the same filter / search / include-inactive scope as the on-screen view.
 */
final class PosStockReportPrintController extends Controller
{
    public function __invoke(Request $request, PosStockReportData $data): View
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Read);

        $filter = (string) $request->query('filter', '');
        $search = (string) $request->query('search', '');
        $includeInactive = (string) $request->query('inactive', '0') === '1';

        $labels = ['in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock'];

        $rows = $data->rows($filter, $search, $includeInactive)
            ->map(function (StockRow $row) use ($labels): array {
                $unit = $row->unit !== '' ? ' ' . $row->unit : '';

                return [
                    'name' => $row->name,
                    'type' => match (true) {
                        $row->isCondiment() => __('Add-on'),
                        $row->isIngredient() => __('Ingredient'),
                        default => __('Product'),
                    },
                    'category' => $row->category ?? '—',
                    'stock' => rtrim(rtrim(number_format($row->stock, 3), '0'), '.') . $unit,
                    'value' => Currencies::format($row->value),
                    'status' => $labels[$row->status] ?? '',
                ];
            })
            ->all();

        $company = Setting::get('company.name', 'OpenERP');

        return view('pos::stock-report-print', [
            'rows' => $rows,
            'summary' => $data->summary($includeInactive),
            'summaryValue' => Currencies::format($data->summary($includeInactive)['value']),
            'company' => is_string($company) && $company !== '' ? $company : 'OpenERP',
            'generatedAt' => Carbon::now()->isoFormat('MMM D, YYYY h:mm A'),
        ]);
    }
}
