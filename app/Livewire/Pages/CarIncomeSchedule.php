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

    #[Url]
    public int $year = 0;

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

        return view('livewire.pages.car-income', [
            'report' => (new CarIncome($year))->report(),
            'year' => $year,
            'mode' => in_array($this->show, self::SHOW, true) ? $this->show : 'all',
            'years' => range((int) $now->year, (int) $now->year - 4),
            'currentMonth' => $year === (int) $now->year ? (int) $now->month : 0,
        ]);
    }

    private function guardOwner(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);
    }
}
