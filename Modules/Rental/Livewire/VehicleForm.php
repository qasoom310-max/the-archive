<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;

#[Layout('components.layouts.app')]
#[Title('Car')]
final class VehicleForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    /**
     * Bring a car back from maintenance to the available fleet, and close any
     * still-open replacement that took it off the road. Manager-gated.
     */
    public function returnToService(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        if ($this->id === null) {
            return;
        }

        $car = Vehicle::query()->find($this->id);
        if ($car === null || $car->status !== Vehicle::STATUS_MAINTENANCE) {
            return;
        }

        $car->status = Vehicle::STATUS_AVAILABLE;
        $car->save();

        RentalReplacement::query()
            ->where('original_vehicle_id', $car->id)
            ->where('status', RentalReplacement::STATUS_ACTIVE)
            ->get()
            ->each(fn (RentalReplacement $replacement) => $replacement->close());

        app(ActivityLogger::class)->logFor($car, 'returned', __('Returned to service'));

        session()->flash('toast', __('Car returned to service.'));
    }

    public function render(): View
    {
        $user = Auth::user();
        $vehicle = $this->id !== null ? Vehicle::query()->find($this->id) : null;

        $earnedThisMonth = $vehicle !== null && $vehicle->monthly_target > 0 ? $vehicle->revenueThisMonth() : 0.0;

        return view('rental::vehicle-form', [
            'vehicle' => $vehicle,
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'earnedThisMonth' => $earnedThisMonth,
        ]);
    }
}
