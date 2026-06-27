<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Rental\Models\RentalRevenueHistory;

/**
 * Imports a year of monthly booking revenue from a CSV (e.g. an export from a
 * previous system) into rental_revenue_history, so the Sales matrix and seasonal
 * analysis include history that predates this app.
 *
 * Forgiving format: any column named like a month (Jan / January / …) is read as
 * that month's revenue; a Reg#/Plate column identifies the car, an optional
 * Vehicle column labels it. Other columns (Expected, Total, …) are ignored.
 * Re-importing a year replaces that year's figures.
 */
final class RentalSalesImportController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        // No strict `mimes:csv` — Excel/Windows often mis-report a CSV's MIME.
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:8192'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $year = (int) $validated['year'];

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('toast', __('Could not read the file.'));
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return back()->with('toast', __('The file is empty.'));
        }

        // Strip a UTF-8 BOM Excel often prepends to the first header cell.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

        $monthCols = [];
        $plateCol = null;
        $vehicleCol = null;
        foreach ($header as $i => $name) {
            $key = strtolower(trim((string) $name));
            $month = $this->monthNumber($key);
            if ($month !== null) {
                $monthCols[$i] = $month;
            } elseif (in_array($key, ['reg#', 'reg', 'reg no', 'reg no.', 'plate', 'plate no', 'plate no.', 'plate_no'], true)) {
                $plateCol = $i;
            } elseif (in_array($key, ['vehicle', 'car', 'name', 'model'], true)) {
                $vehicleCol = $i;
            }
        }

        if ($monthCols === []) {
            fclose($handle);

            return back()->with('toast', __('No month columns found (expected Jan…Dec headers).'));
        }

        $insert = [];
        while (($data = fgetcsv($handle)) !== false) {
            $plate = $plateCol !== null ? trim((string) ($data[$plateCol] ?? '')) : '';
            $label = $vehicleCol !== null ? trim((string) ($data[$vehicleCol] ?? '')) : '';

            foreach ($monthCols as $i => $month) {
                $amount = (float) str_replace([',', ' '], '', (string) ($data[$i] ?? ''));
                if ($amount <= 0) {
                    continue;
                }
                $insert[] = [
                    'plate_no' => $plate !== '' ? $plate : null,
                    'vehicle_label' => $label !== '' ? $label : null,
                    'year' => $year,
                    'month' => $month,
                    'amount' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        fclose($handle);

        DB::transaction(function () use ($year, $insert): void {
            RentalRevenueHistory::query()->where('year', $year)->delete();
            foreach (array_chunk($insert, 500) as $chunk) {
                RentalRevenueHistory::query()->insert($chunk);
            }
        });

        return redirect('/app/rental/sales?year=' . $year)
            ->with('toast', __(':count monthly figures imported for :year.', ['count' => count($insert), 'year' => $year]));
    }

    private function monthNumber(string $key): ?int
    {
        $map = [
            'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6,
            'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
        ];

        return $map[$key] ?? null;
    }
}
