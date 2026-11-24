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

    /** The office's end-trip form (the driver forgot to press End). */
    public string $endKm = '';

    public string $endFuel = '';

    public string $endNote = '';

    public function endForDriver(int $runId): void
    {
        $this->guardAccess(Permission::Write);

        $data = $this->validate([
            'endKm' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'endFuel' => ['nullable', 'integer', 'min:0', 'max:8'],
            'endNote' => ['required', 'string', 'max:255'],
        ]);

        $leg = LimoLeg::query()->findOrFail($this->legId);
        $run = LimoTripRun::query()->where('leg_id', $leg->id)->whereKey($runId)->firstOrFail();
        $error = app(DriverTrip::class)->endByOffice(
            $leg, $run,
            $data['endKm'] !== null && $data['endKm'] !== '' ? (int) $data['endKm'] : null,
            $data['endFuel'] !== null && $data['endFuel'] !== '' ? (int) $data['endFuel'] : null,
            (string) (\Illuminate\Support\Facades\Auth::user()->name ?? ''),
            trim((string) $data['endNote']),
        );

        if ($error !== null) {
            $this->addError('endKm', $error);

            return;
        }

        $this->reset('endKm', 'endFuel', 'endNote');
        session()->flash('toast', __('Trip ended for the driver. He can start his next trip.'));
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
            'link' => DriverTripLink::short($leg),
            'mayEnd' => $this->mayAccess(Permission::Write),
        ]);
    }
}
