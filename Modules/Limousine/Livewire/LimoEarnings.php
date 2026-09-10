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
        }
    }

    public function render(): View
    {
        return view('limousine::earnings', [
            'report' => (new LimoPerformance($this->year))->report(),
            'years' => range((int) Carbon::now()->year, (int) Carbon::now()->year - 4),
        ]);
    }

    private function guardOwner(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);
    }
}
