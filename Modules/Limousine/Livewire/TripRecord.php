<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoTripRun;
use Modules\Limousine\Services\DriverTrip;
use Modules\Limousine\Support\DriverTripLink;

/**
 * What the driver recorded on his trip link, for the office: start and end
 * time, KM, fuel, location (map link) and both dashboard photos, plus what
 * did not add up. Read-only; the record is the driver's.
 */
#[Layout('components.layouts.app')]
#[Title('Trip record')]
final class TripRecord extends Component
{
    use GuardsModelAccess;

    #[Locked]
    public int $legId = 0;

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(int $leg): void
    {
        $this->guardAccess(Permission::Read);
        abort_unless(LimoLeg::query()->whereKey($leg)->exists(), 404);
        $this->legId = $leg;
    }

    public function render(): View
    {
        $leg = LimoLeg::query()->findOrFail($this->legId);
        $runs = DriverTrip::ready()
            ? LimoTripRun::query()->where('leg_id', $leg->id)->orderByDesc('id')->get()
            : collect();
        $drivers = \Modules\Limousine\Models\LimoDriver::query()->whereIn('id', $runs->pluck('driver_id'))->pluck('name', 'id');

        return view('limousine::trip-record', [
            'leg' => $leg,
            'runs' => $runs,
            'drivers' => $drivers,
            'link' => DriverTripLink::for($leg),
        ]);
    }
}
