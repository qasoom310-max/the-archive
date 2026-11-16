<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

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
use Modules\Limousine\Support\LimoPerformance;

/**
 * Limousine earnings - drivers, routes and the hours the work lands in.
 *
 * Rent A Car gets a page per car because every hire names one. This desk
 * cannot: no car was ever recorded against a trip. So it reports the three
 * units a chauffeur business actually turns on, and says plainly when the
 * records cannot support one of them. See {@see LimoPerformance}.
 *
 * Owner only, like every other page that adds the takings up.
 */
#[Layout('components.layouts.app')]
#[Title('Limousine earnings')]
final class LimoEarnings extends Component
{
    use GuardsModelAccess;

    #[Url]
    public int $year = 0;

    /**
     * Which month the DRIVERS table reads: 1-12, 0 for the whole year, and -1
     * until one is chosen (then this month, or the whole of a past year).
     */
    #[Url(as: 'month', except: -1)]
    public int $driverMonth = -1;

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        $this->guardOwner();

        if ($this->year === 0) {
            $this->year = (int) Carbon::now()->year;
        }
    }

    public function setYear(int $year): void
    {
        // Re-checked on the action: Livewire dispatches straight to a method,
        // so a gate that only runs on mount is not a gate.
        $this->guardOwner();

        if ($year >= 2000 && $year <= (int) Carbon::now()->year + 1) {
            $this->year = $year;
            // Back to the default for that year, not November of 2023.
            $this->driverMonth = -1;
        }
    }

    public function setDriverMonth(int $month): void
    {
        $this->guardOwner();

        if ($month >= 0 && $month <= 12) {
            $this->driverMonth = $month;
        }
    }

    /**
     * The month the drivers table shows. Unchosen, it is the month we are in:
     * a driver is thanked for the month just worked, not for a career. A past
     * year has no "this month", so it opens on the whole year.
     */
    private function driverMonthShown(): int
    {
        if ($this->driverMonth >= 0 && $this->driverMonth <= 12) {
            return $this->driverMonth;
        }

        return $this->year === (int) Carbon::now()->year ? (int) Carbon::now()->month : 0;
    }

    public function render(): View
    {
        $month = $this->driverMonthShown();

        return view('limousine::earnings', [
            'report' => (new LimoPerformance($this->year, $month))->report(),
            // Not named `driverMonth`: a view variable with a public property's
            // name loses to the property, and the page would read -1.
            'shownMonth' => $month,
            'years' => range((int) Carbon::now()->year, (int) Carbon::now()->year - 4),
        ]);
    }

    private function guardOwner(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);
    }
}
