<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Reports\CarIncome;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Rental\Models\Vehicle;

/**
 * Car income — one schedule of what every car brought in each month, from
 * Rent A Car and Limousine together, against the car's yearly target.
 *
 * Sits with the owner's reports on the home page rather than inside either
 * app, because it belongs to neither: the point is to stop reading a car's
 * year off two screens. See {@see CarIncome} for how the money is counted.
 *
 * Owner only, like every page that adds the takings up.
 */
#[Layout('components.layouts.app')]
#[Title('Car income')]
final class CarIncomeSchedule extends Component
{
    public const SHOW = ['all', 'rent', 'limo'];

    public const FLEETS = [CarIncome::OWN, CarIncome::OUTSIDE];

    #[Url]
    public int $year = 0;

    /** Which schedule: our own cars, or cars rented in from other companies. */
    #[Url(except: CarIncome::OWN)]
    public string $fleet = CarIncome::OWN;

    /** Which money the month columns print: both desks, or one. */
    #[Url(except: 'all')]
    public string $show = 'all';

    public function mount(): void
    {
        $this->guardOwner();

        if ($this->year === 0) {
            $this->year = (int) Carbon::now()->year;
        }

        request()->attributes->set('breadcrumb_terminal_label', __('Car income'));
    }

    public function setYear(int $year): void
    {
        // Re-checked on every action: Livewire dispatches straight to a
        // method, so a gate that only runs on mount is not a gate.
        $this->guardOwner();

        if ($year >= 2000 && $year <= (int) Carbon::now()->year + 1) {
            $this->year = $year;
        }
    }

    public function setFleet(string $fleet): void
    {
        $this->guardOwner();

        $this->fleet = in_array($fleet, self::FLEETS, true) ? $fleet : CarIncome::OWN;
    }

    public function setShow(string $show): void
    {
        $this->guardOwner();

        $this->show = in_array($show, self::SHOW, true) ? $show : 'all';
    }

    public function render(): View
    {
        $this->guardOwner();

        $now = Carbon::now();
        // The address bar can carry anything.
        $year = $this->year >= 2000 && $this->year <= (int) $now->year + 1 ? $this->year : (int) $now->year;

        $fleet = in_array($this->fleet, self::FLEETS, true) ? $this->fleet : CarIncome::OWN;
        // The category names belong to the car model. The class is always
        // loadable (it is on the autoload path whether or not Rent A Car is
        // installed), and without its table there are no cars to name anyway.
        $categories = Vehicle::categoryLabels();

        return view('livewire.pages.car-income', [
            'report' => (new CarIncome($year, $fleet, array_keys($categories)))->report(),
            'fleetShown' => $fleet,
            'categories' => $categories,
            'year' => $year,
            'mode' => in_array($this->show, self::SHOW, true) ? $this->show : 'all',
            'years' => range((int) $now->year, (int) $now->year - 4),
            'currentMonth' => $year === (int) $now->year ? (int) $now->month : 0,
        ]);
    }

    private function guardOwner(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->seesCarIncome(), 403);
    }
}
