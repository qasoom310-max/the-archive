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
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Rental\Support\FleetPerformance;

/**
 * Fleet earnings - whether each car is worth owning.
 *
 * The month-by-month matrix the office already had is kept, because it is what
 * everyone recognises, but every row opens into the numbers that actually
 * answer the question: how much of the year the car was on hire, what it earned
 * per day it was available, what it cost to keep, and whether it is on pace for
 * its year. See {@see FleetPerformance} for why revenue alone ranks a fleet
 * backwards.
 *
 * Owner only. It is a page of what the business earns, car by car, and it is
 * the same figure the dashboard revenue card is gated on.
 */
#[Layout('components.layouts.app')]
#[Title('Fleet earnings')]
final class FleetEarnings extends Component
{
    use GuardsModelAccess;

    #[Url]
    public int $year = 0;

    /** Which car's scorecard is open. Server-set from a click, never a binding. */
    #[Locked]
    public ?int $openCar = null;

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        $this->guardOwner();

        if ($this->year === 0) {
            $this->year = (int) Carbon::now()->year;
        }
    }

    /** Open or close one car's scorecard. */
    public function toggleCar(int $id): void
    {
        // Re-checked on the action: Livewire dispatches straight to a method,
        // so a gate that only runs on mount is not a gate.
        $this->guardOwner();

        $this->openCar = $this->openCar === $id ? null : $id;
    }

    public function setYear(int $year): void
    {
        $this->guardOwner();

        // A sane range: the business has no records before this, and a wild
        // value would build twelve empty months for nothing.
        if ($year >= 2000 && $year <= (int) Carbon::now()->year + 1) {
            $this->year = $year;
            $this->openCar = null;
        }
    }

    public function render(): View
    {
        $report = (new FleetPerformance($this->year))->report();

        return view('rental::fleet-earnings', [
            'report' => $report,
            'rows' => $report['rows'],
            'summary' => $report['summary'],
            'years' => range((int) Carbon::now()->year, (int) Carbon::now()->year - 4),
        ]);
    }

    /**
     * The whole page is one long answer to "what does this business earn",
     * broken down far enough to name the cars. Same tier as the revenue card.
     */
    private function guardOwner(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);
    }
}
