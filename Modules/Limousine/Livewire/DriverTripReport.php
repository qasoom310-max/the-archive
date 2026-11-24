<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoTripRun;
use Modules\Limousine\Services\AbdullahTripStarts;
use Modules\Limousine\Services\DriverTrip;

/**
 * Each driver's month on the trip link (the owner's option 4): how many of his
 * trips he recorded properly (photo + start + end), how many the office had to
 * end for him, how many he never started, and how many carry something to
 * check. Facts for a warning or a bonus, instead of an argument.
 */
#[Layout('components.layouts.app')]
#[Title('Driver trip records')]
final class DriverTripReport extends Component
{
    use GuardsModelAccess;

    #[Url(as: 'month', except: '')]
    public string $month = '';

    #[Url(as: 'driver', except: 0)]
    public int $openDriver = 0;

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = Carbon::now()->format('Y-m');
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = Carbon::createFromFormat('!Y-m', $this->month)?->addMonthsNoOverflow($by)->format('Y-m') ?? Carbon::now()->format('Y-m');
        $this->openDriver = 0;
    }

    public function toggleDriver(int $id): void
    {
        $this->openDriver = $this->openDriver === $id ? 0 : $id;
    }

    public function render(): View
    {
        $start = Carbon::createFromFormat('!Y-m', $this->month) ?? Carbon::now()->startOfMonth();
        $from = $start->copy()->startOfMonth();
        $to = $start->copy()->endOfMonth();
        $since = Carbon::parse(AbdullahTripStarts::SINCE);
        if ($from->lt($since)) {
            $from = $since->copy();
        }

        $legs = DriverTrip::ready() && $to->gte($from)
            ? LimoLeg::query()
                ->whereMorphedTo('legable', LimoBooking::class)
                ->whereNotNull('driver_id')
                ->where('status', '!=', LimoLeg::STATUS_CANCELLED)
                ->whereBetween('start_at', [$from, $to])
                ->orderBy('start_at')
                ->get()
            : collect();

        $runs = $legs->isEmpty() ? collect() : LimoTripRun::query()->whereIn('leg_id', $legs->pluck('id'))->get()
            ->keyBy(static fn (LimoTripRun $r): string => $r->leg_id . ':' . $r->driver_id);

        $now = Carbon::now();
        $rows = [];
        foreach ($legs as $leg) {
            $id = (int) $leg->driver_id;
            $run = $runs->get($leg->id . ':' . $id);
            $state = match (true) {
                $run?->isEnded() && $run->ended_by === null => 'done',
                $run?->isEnded() => 'office',
                $run?->isStarted() => 'running',
                $leg->start_at !== null && $leg->start_at->copy()->addMinutes(AbdullahTripStarts::LATE_MINUTES)->lt($now) => 'missed',
                default => 'upcoming',
            };

            $rows[$id] ??= ['id' => $id, 'name' => (string) $leg->driver, 'trips' => 0, 'done' => 0, 'office' => 0, 'running' => 0, 'missed' => 0, 'upcoming' => 0, 'checks' => 0, 'km' => 0, 'list' => []];
            $rows[$id]['trips']++;
            $rows[$id][$state]++;
            $flagged = $run !== null && ! empty($run->flags);
            if ($flagged) {
                $rows[$id]['checks']++;
            }
            $rows[$id]['km'] += max(0, (int) ($run?->distance() ?? 0));
            $rows[$id]['list'][] = ['leg' => $leg, 'run' => $run, 'state' => $state, 'flagged' => $flagged];
        }

        // Worst first: the trips that should have been recorded and were not.
        usort($rows, static fn (array $a, array $b): int => [$b['missed'] + $b['office'], $b['checks'], $a['name']] <=> [$a['missed'] + $a['office'], $a['checks'], $b['name']]);

        return view('limousine::driver-trip-report', [
            'rows' => $rows,
            'monthLabel' => $start->isoFormat('MMMM YYYY'),
        ]);
    }
}
