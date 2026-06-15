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
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Services\PosStockReportData;

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

        $rows = $data->query($filter, $search, $includeInactive)->get()
            ->map(function (PosProduct $product) use ($data, $labels): array {
                $unit = $product->unit && $product->unit !== 'qty' ? ' ' . $product->unit : '';

                return [
                    'name' => (string) $product->name,
                    'category' => $product->category_name ?? '—',
                    'stock' => rtrim(rtrim(number_format((float) $product->stock_on_hand, 3), '0'), '.') . $unit,
                    'value' => Currencies::format($product->stockValue()),
                    'status' => $labels[$data->status($product)] ?? '',
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
