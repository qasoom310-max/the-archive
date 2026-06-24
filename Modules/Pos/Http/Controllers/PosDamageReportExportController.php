<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Money\Currencies;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Models\PosDamage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the Damage Report as CSV, honouring the same date range + reason
 * filter as the on-screen view (passed as query params), with a trailing
 * total-loss row.
 */
final class PosDamageReportExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.damage', Permission::Read);

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $reason = (string) $request->query('reason', '');

        $entries = PosDamage::query()
            ->when($from !== '', fn ($q) => $q->whereDate('damaged_on', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('damaged_on', '<=', $to))
            ->when($reason !== '', fn ($q) => $q->where('reason', $reason))
            ->orderByDesc('damaged_on')
            ->orderByDesc('id')
            ->get();

        $response = new StreamedResponse(function () use ($entries): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['Reference', 'Date', 'Item', 'Type', 'Quantity', 'Unit cost', 'Loss value', 'Reason', 'Recorded by', 'Note']);

            foreach ($entries as $entry) {
                fputcsv($out, [
                    $entry->reference,
                    $entry->damaged_on->format('Y-m-d'),
                    $entry->item_label,
                    ucfirst($entry->item_type),
                    $this->num((float) $entry->quantity),
                    Currencies::format((float) $entry->unit_cost),
                    Currencies::format((float) $entry->loss_value),
                    $entry->reason_label,
                    $entry->recorded_by ?? '',
                    $entry->note ?? '',
                ]);
            }

            fputcsv($out, ['', '', '', '', '', '', Currencies::format((float) $entries->sum('loss_value')), 'TOTAL', '', '']);

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="damage-report.csv"');

        return $response;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
