<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Rental\Support\SalesReport;

/**
 * Sales — the per-car month-by-month booking-revenue matrix (Expected vs actual,
 * by year) plus seasonal analysis. All figures come from {@see SalesReport}.
 */
#[Layout('components.layouts.app')]
#[Title('Sales')]
final class Sales extends Component
{
    use GuardsModelAccess;

    /** @var array<int, string> */
    public const MONTHS = SalesReport::MONTHS;

    #[Url]
    public int $year = 0;

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        if ($this->year === 0) {
            $this->year = (int) Carbon::now()->year;
        }
    }

    public function render(): View
    {
        $report = new SalesReport($this->year);

        $cars = $report->carRows();
        $fleetMonthly = $report->fleetMonthly();
        $season = $report->seasonality();

        $withData = array_filter($fleetMonthly, static fn (float $v): bool => $v > 0);
        $bestMonth = $withData !== [] ? (int) array_keys($fleetMonthly, max($withData), true)[0] : null;
        $deadMonth = $withData !== [] ? (int) array_keys($fleetMonthly, min($withData), true)[0] : null;

        $avg = $season['avg'];
        $user = Auth::user();

        return view('rental::sales', [
            'months' => self::MONTHS,
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'cars' => $cars,
            'fleetMonthly' => $fleetMonthly,
            'fleetTotal' => array_sum($fleetMonthly),
            'bestMonth' => $bestMonth,
            'deadMonth' => $deadMonth,
            'availableYears' => $report->availableYears(),
            'seasonalAvg' => $avg,
            'seasonalMax' => $avg !== [] ? max($avg) : 0.0,
            'peakMonths' => $season['peak'],
            'hasHistory' => $season['hasHistory'],
            'seasonBanner' => $season['banner'],
        ]);
    }
}
