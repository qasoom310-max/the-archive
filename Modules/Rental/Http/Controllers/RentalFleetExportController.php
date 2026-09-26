<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Export\TabularRenderer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\FleetPerformance;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the Fleet earnings table, in the same four formats as every
 * other list in the app.
 *
 * Gated to the owner exactly as the page is - the download carries the same
 * car-by-car earnings, so leaving the route open would hand out through a URL
 * precisely what the screen refuses to show.
 */
final class RentalFleetExportController
{
    public function __construct(private readonly TabularRenderer $renderer) {}

    public function __invoke(Request $request): StreamedResponse|Response|View
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);

        $year = $request->integer('year');
        if ($year < 2000 || $year > (int) Carbon::now()->year + 1) {
            $year = (int) Carbon::now()->year;
        }

        $month = $request->integer('month');
        $month = $month >= 1 && $month <= 12 ? $month : 0;

        // The download is the same period the screen was showing.
        $report = (new FleetPerformance($year, $month))->report();
        $headings = $this->headings($report['hasLimo']);
        $rows = $this->rows($report);
        $title = $month > 0
            ? __('Fleet earnings :year', ['year' => Carbon::create($year, $month, 1)->isoFormat('MMMM YYYY')])
            : __('Fleet earnings :year', ['year' => $year]);
        $file = $month > 0 ? sprintf('fleet-earnings-%d-%02d', $year, $month) : "fleet-earnings-{$year}";

        return match ($request->string('format')->toString()) {
            'excel' => $this->renderer->excel($headings, $rows, $file),
            'pdf' => $this->renderer->pdf($headings, $rows, $title, $file),
            'print' => $this->renderer->print($headings, $rows, $title),
            default => $this->renderer->csv($headings, $rows, $file),
        };
    }

    /**
     * The renderer takes headings keyed `field => label` and rows keyed by the
     * same fields, so a column can never drift out of step with its heading.
     *
     * @return array<string, string>
     */
    private function headings(bool $hasLimo): array
    {
        $headings = ['plate' => __('Reg#'), 'name' => __('Vehicle')];

        foreach ($this->monthLabels() as $m => $label) {
            $headings['m'.$m] = $label;
        }

        $headings['total'] = __('Total');

        if ($hasLimo) {
            $headings['limo'] = __('Limousine');
        }

        return $headings + [
            'rented' => __('Days on hire'),
            'available' => __('Days owned'),
            'used' => __('Used'),
            'perDay' => __('Per day owned'),
            'maintenance' => __('Service and repairs'),
            'net' => __('After maintenance'),
            'target' => __('Target'),
            'pace' => __('Pace'),
            'idle' => __('Idle days cost'),
            'verdict' => __('Verdict'),
        ];
    }

    /** @return array<int, string> */
    private function monthLabels(): array
    {
        return [
            1 => __('Jan'), 2 => __('Feb'), 3 => __('Mar'), 4 => __('Apr'),
            5 => __('May'), 6 => __('Jun'), 7 => __('Jul'), 8 => __('Aug'),
            9 => __('Sep'), 10 => __('Oct'), 11 => __('Nov'), 12 => __('Dec'),
        ];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, hasLimo: bool}  $report
     * @return list<array<string, string>>
     */
    private function rows(array $report): array
    {
        $labels = [
            'carrying' => __('Carrying its weight'),
            'behind' => __('Renting cheap'),
            'underused' => __('Underused'),
            'losing' => __('Losing money'),
            'notarget' => __('No target'),
        ];

        $out = [];

        foreach ($report['rows'] as $row) {
            $line = ['plate' => (string) $row['plate'], 'name' => (string) $row['name']];

            for ($m = 1; $m <= 12; $m++) {
                $line['m'.$m] = $this->number($row['months'][$m] ?? 0.0);
            }

            $line['total'] = $this->number($row['total']);

            if ($report['hasLimo']) {
                $line['limo'] = $this->number($row['limo']);
            }

            $line['rented'] = (string) $row['rentedDays'];
            $line['available'] = (string) $row['availableDays'];
            $line['used'] = $row['utilisation'] === null ? '' : $row['utilisation'].'%';
            $line['perDay'] = $row['availableDays'] > 0 ? $this->number($row['perAvailableDay']) : '';
            $line['maintenance'] = $this->number($row['maintenance']);
            $line['net'] = $this->number($row['net']);
            $line['target'] = $this->number($row['target']);
            $line['pace'] = $row['pace'] === null ? '' : $row['pace'].'%';
            $line['idle'] = $this->number($row['idleCost']);
            $line['verdict'] = $labels[$row['verdict']] ?? '';

            $out[] = $line;
        }

        return $out;
    }

    /** Plain digits: a spreadsheet must be able to add the column up. */
    private function number(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
