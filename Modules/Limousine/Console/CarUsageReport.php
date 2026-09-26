<?php

declare(strict_types=1);

namespace Modules\Limousine\Console;

use App\Erp\Tenancy\WorkspaceManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Throwable;

/**
 * Read-only. Explains where a fleet car's money on Fleet earnings comes from,
 * so "this car works all the time, why does it show so little?" can be
 * answered from live data instead of guessed at. Writes nothing, ever.
 *
 * Fleet earnings credits a car with (a) Rent A Car orders on that car, by
 * start date, and (b) limousine trip legs that have the car assigned. A leg
 * with no car assigned - every imported trip, and any trip that was never
 * dispatched from the queue - reaches no car at all. This prints, per month,
 * how much limousine money is on legs with and without a car, the vehicle
 * labels the unassigned legs carry, and one car's own month-by-month lines.
 */
final class CarUsageReport extends Command
{
    protected $signature = 'limo:car-usage
        {--workspace= : Only this workspace id}
        {--plate= : Also print this car\'s own lines (plate number or part of the name)}
        {--year= : Year to read (default: this year)}';

    protected $description = 'Report how limousine and rental money reaches each fleet car. Read-only — changes nothing.';

    public function handle(WorkspaceManager $workspaces): int
    {
        $only = $this->option('workspace');
        $year = (int) ($this->option('year') ?: CarbonImmutable::now()->year);
        $plate = trim((string) $this->option('plate'));

        foreach ($workspaces->all() as $workspace) {
            if ($only !== null && (string) $workspace->id !== (string) $only) {
                continue;
            }

            $this->line('');
            $this->info($workspace->name . ':');

            try {
                if ($workspace->is_main) {
                    $this->reportHere($year, $plate);

                    continue;
                }

                $path = $workspace->databasePath();

                if ($path === null || ! is_file($path)) {
                    $this->warn('  skipped: its database file is missing.');

                    continue;
                }

                $workspaces->withTenant($path, function () use ($year, $plate): void {
                    $this->reportHere($year, $plate);
                });
            } catch (Throwable $e) {
                // One workspace failing must never stop the rest.
                $this->warn('  skipped: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function reportHere(int $year, string $plate): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('rental_vehicles')) {
            $this->line('  Needs both Limousine and Rent A Car here - skipped.');

            return;
        }

        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $start->endOfYear();

        $legs = DB::table('limo_legs as l')
            ->join('limo_bookings as b', 'b.id', '=', 'l.legable_id')
            ->where('l.legable_type', LimoBooking::class)
            ->where('b.status', '!=', 'cancelled')
            ->whereNotNull('l.start_at')
            ->whereBetween('l.start_at', [$start->toDateString(), $end->toDateTimeString()])
            ->get(['l.car_id', 'l.vehicle', 'l.start_at', 'l.net_amount', 'l.status']);

        $this->line("  Limousine trips in {$year} (booked, not cancelled), by month:");
        $this->line('    month | legs with car | amount | legs WITHOUT car | amount');

        $byMonth = [];
        $unassignedLabels = [];

        foreach ($legs as $leg) {
            $m = (int) CarbonImmutable::parse((string) $leg->start_at)->format('n');
            $key = $leg->car_id === null ? 'without' : 'with';
            $byMonth[$m][$key]['n'] = ($byMonth[$m][$key]['n'] ?? 0) + 1;
            $byMonth[$m][$key]['amt'] = ($byMonth[$m][$key]['amt'] ?? 0.0) + (float) $leg->net_amount;

            if ($leg->car_id === null) {
                $label = trim((string) ($leg->vehicle ?? '')) ?: '(blank)';
                $unassignedLabels[$label] = ($unassignedLabels[$label] ?? 0) + 1;
            }
        }

        ksort($byMonth);

        foreach ($byMonth as $m => $row) {
            $this->line(sprintf(
                '    %5s | %13d | %9.3f | %16d | %9.3f',
                CarbonImmutable::create($year, $m, 1)->format('M'),
                $row['with']['n'] ?? 0,
                $row['with']['amt'] ?? 0.0,
                $row['without']['n'] ?? 0,
                $row['without']['amt'] ?? 0.0,
            ));
        }

        arsort($unassignedLabels);
        $this->line('  Vehicle text on legs with no car assigned (top 25):');

        foreach (array_slice($unassignedLabels, 0, 25, true) as $label => $n) {
            $this->line("    {$n} × {$label}");
        }

        if ($plate === '') {
            return;
        }

        $cars = DB::table('rental_vehicles')
            ->where(function ($q) use ($plate): void {
                $q->where('plate_no', 'like', "%{$plate}%")->orWhere('name', 'like', "%{$plate}%");
            })
            ->get(['id', 'name', 'plate_no', 'status', 'active', 'is_outside', 'monthly_target']);

        foreach ($cars as $car) {
            $this->line('');
            $this->line(sprintf(
                '  Car #%d %s (%s) status=%s active=%s outside=%s monthly target=%s',
                $car->id, $car->name, $car->plate_no, $car->status,
                $car->active ? 'yes' : 'no', $car->is_outside ? 'yes' : 'no', $car->monthly_target ?? '-',
            ));

            $orders = DB::table('rental_orders')
                ->where('vehicle_id', $car->id)
                ->where('start_date', '>=', $start->subYear()->toDateString())
                ->orderBy('start_date')
                ->get(['id', 'state', 'start_date', 'end_date', 'total', 'outside_cost']);

            $this->line('    Rent A Car orders (from ' . $start->subYear()->year . '): ' . $orders->count());

            foreach ($orders as $o) {
                $this->line(sprintf(
                    '      #%d %s %s → %s total %.3f outside %.3f',
                    $o->id, $o->state, $o->start_date, $o->end_date ?? '-', (float) $o->total, (float) $o->outside_cost,
                ));
            }

            $carLegs = $legs->where('car_id', $car->id);
            $this->line('    Limousine legs with this car in ' . $year . ': ' . $carLegs->count()
                . ', amount ' . number_format((float) $carLegs->sum('net_amount'), 3));

            $named = $legs->whereNull('car_id')->filter(
                static fn (object $l): bool => $car->plate_no !== null && $car->plate_no !== ''
                    && str_contains((string) $l->vehicle, (string) $car->plate_no),
            );
            $this->line('    Legs with NO car but whose text mentions this plate: ' . $named->count()
                . ', amount ' . number_format((float) $named->sum('net_amount'), 3));
        }
    }
}
