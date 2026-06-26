<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Rental\Support\SalesReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the Sales booking-revenue matrix for a year as a CSV download. Mounted
 * inside the `auth` group, so only signed-in users reach it.
 */
final class RentalSalesExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $year = $request->integer('year');
        if ($year < 2000 || $year > 2100) {
            $year = (int) Carbon::now()->year;
        }

        $report = new SalesReport($year);
        $rows = $report->carRows();
        $fleet = $report->fleetMonthly();
        $months = SalesReport::MONTHS;

        return response()->streamDownload(function () use ($rows, $fleet, $months): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['Car', 'Plate', 'Expected', ...array_values($months), 'Total']);

            foreach ($rows as $car) {
                $line = [$car['name'], $car['plate'], $car['expected']];
                foreach (array_keys($months) as $m) {
                    $line[] = $car['months'][$m];
                }
                $line[] = $car['total'];
                fputcsv($out, $line);
            }

            $fleetLine = ['Fleet total', '', ''];
            foreach (array_keys($months) as $m) {
                $fleetLine[] = $fleet[$m];
            }
            $fleetLine[] = array_sum($fleet);
            fputcsv($out, $fleetLine);

            fclose($out);
        }, "sales-{$year}.csv", ['Content-Type' => 'text/csv']);
    }
}
