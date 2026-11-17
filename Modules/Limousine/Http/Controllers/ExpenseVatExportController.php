<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Livewire\ExpenseVat;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Support\ExpenseVatReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The quarterly VAT report as a spreadsheet file (CSV), every figure in BHD. */
final class ExpenseVatExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.expense', Permission::Read);

        [$year, $quarter] = ExpenseVat::clamp((int) $request->query('year'), (int) $request->query('quarter'));
        $report = (new ExpenseVatReport($year, $quarter))->report();

        $row = static fn (string $kind, LimoExpense $e): array => [
            $kind,
            $e->date->format('Y-m-d'),
            (string) $e->reference,
            (string) $e->payee,
            (string) $e->category,
            number_format($e->net_amount, 3, '.', ''),
            (string) $e->vat_rate,
            number_format($e->vat_amount, 3, '.', ''),
            number_format($e->amount, 3, '.', ''),
            $e->bill_currency !== 'BHD' ? $e->bill_currency : '',
            $e->bill_currency !== 'BHD' && $e->bill_amount !== null ? number_format($e->bill_amount, 3, '.', '') : '',
            $e->bill_currency !== 'BHD' && $e->bill_rate !== null ? (string) $e->bill_rate : '',
            (string) $e->bill_path !== '' ? 'yes' : 'no',
        ];

        return response()->streamDownload(static function () use ($report, $row): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            // So Excel opens Arabic supplier names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['VAT', 'Date', 'Reference', 'Paid to', 'Category', 'Before VAT (BHD)', 'VAT %', 'VAT (BHD)', 'Total (BHD)', 'Bill currency', 'Bill amount before VAT', 'Rate used', 'Bill photo']);
            foreach ($report['bahrain'] as $expense) {
                fputcsv($out, $row('Bahrain', $expense));
            }
            foreach ($report['foreign'] as $expense) {
                fputcsv($out, $row('Foreign (not claimed)', $expense));
            }
            fputcsv($out, []);
            fputcsv($out, ['Bahrain VAT to claim', '', '', '', '', number_format($report['totals']['bahrain_net'], 3, '.', ''), '', number_format($report['totals']['bahrain_vat'], 3, '.', ''), number_format($report['totals']['bahrain_total'], 3, '.', '')]);
            fputcsv($out, ['Foreign VAT (not claimed)', '', '', '', '', number_format($report['totals']['foreign_net'], 3, '.', ''), '', number_format($report['totals']['foreign_vat'], 3, '.', ''), number_format($report['totals']['foreign_total'], 3, '.', '')]);
            fputcsv($out, ['Bills with no VAT (' . $report['totals']['none_count'] . ')', '', '', '', '', '', '', '', number_format($report['totals']['none_total'], 3, '.', '')]);
            fclose($out);
        }, "limousine-expense-vat-{$year}-Q{$quarter}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
