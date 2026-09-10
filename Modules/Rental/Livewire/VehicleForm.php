<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;

#[Layout('components.layouts.app')]
#[Title('Car')]
final class VehicleForm extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'rental.vehicle';
    }

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    /** Inline monthly-target editor value (BHD). */
    public string $targetInput = '';

    /**
     * Inline YEARLY-target editor value (BHD).
     *
     * Kept separate from twelve monthly ones: a car is off the road for
     * service, and the trade has seasons, so a year is not twelve identical
     * months. Blank means "not set" and the fleet report falls back to 12x the
     * monthly figure, saying on screen that it has done so.
     */
    public string $yearlyTargetInput = '';

    /** Cost & documents editor (chiefly for outside / rented-in cars). */
    public string $purchaseInput = '';

    public string $invoicePath = '';

    public string $agreementPath = '';

    /** Whether this car is rented in from outside (not company-owned). */
    public bool $isOutsideInput = false;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->guardAccess(Permission::Read);
        $this->id = $id;

        if ($id !== null) {
            $car = Vehicle::query()->find($id);
            $this->targetInput = $car !== null && $car->monthly_target > 0
                ? rtrim(rtrim(number_format($car->monthly_target, 3, '.', ''), '0'), '.')
                : '';
            $this->yearlyTargetInput = $car !== null && $car->yearly_target > 0
                ? rtrim(rtrim(number_format($car->yearly_target, 3, '.', ''), '0'), '.')
                : '';
            $this->purchaseInput = $car !== null && $car->purchase_price > 0
                ? rtrim(rtrim(number_format($car->purchase_price, 3, '.', ''), '0'), '.')
                : '';
            $this->isOutsideInput = $car !== null && $car->is_outside;
        }
    }

    /**
     * Save the car's cost (purchase price) and any newly-uploaded vendor invoice
     * / original agreement. For the accountant — accountant or manager only.
     */
    public function saveCost(): void
    {
        $this->guardAccess(Permission::Write);
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->isAccountant() || $user->canApproveMaintenance()), 403);

        if ($this->id === null) {
            return;
        }

        $car = Vehicle::query()->find($this->id);
        if ($car === null) {
            return;
        }

        $car->purchase_price = $this->purchaseInput === '' ? 0.0 : max(0.0, (float) $this->purchaseInput);
        $car->is_outside = $this->isOutsideInput;
        if ($this->invoicePath !== '') {
            $car->purchase_invoice = $this->invoicePath;
        }
        if ($this->agreementPath !== '') {
            $car->agreement_copy = $this->agreementPath;
        }
        $car->save();

        $this->invoicePath = '';
        $this->agreementPath = '';

        app(ActivityLogger::class)->logFor($car, 'updated', __('Cost & documents updated'));
        session()->flash('toast', __('Cost & documents saved.'));
    }

    /** Set / clear this car's monthly sales target. Manager-gated. */
    public function saveTarget(): void
    {
        $this->guardAccess(Permission::Write);
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        if ($this->id === null) {
            return;
        }

        $car = Vehicle::query()->find($this->id);
        if ($car === null) {
            return;
        }

        $value = $this->targetInput === '' ? 0.0 : max(0.0, (float) $this->targetInput);
        $yearly = $this->yearlyTargetInput === '' ? 0.0 : max(0.0, (float) $this->yearlyTargetInput);
        $car->monthly_target = $value;
        $car->yearly_target = $yearly;
        $car->save();

        app(ActivityLogger::class)->logFor($car, 'updated', __('Targets set to :amount a month, :yearly a year', [
            'amount' => \App\Erp\Views\ValueFormat::money($value),
            'yearly' => $yearly > 0.0
                ? \App\Erp\Views\ValueFormat::money($yearly)
                : __('12 × the monthly'),
        ]));

        session()->flash('toast', __('Targets saved.'));
    }

    /**
     * Bring a car back from maintenance to the available fleet, and close any
     * still-open replacement that took it off the road. Manager-gated.
     */
    public function returnToService(): void
    {
        $this->guardAccess(Permission::Write);
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
            'canSeeCost' => $user instanceof User && ($user->isAccountant() || $user->canApproveMaintenance()),
            'earnedThisMonth' => $earnedThisMonth,
        ]);
    }
}
