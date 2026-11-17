<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Erp\Targets\CarTargets;
use Carbon\CarbonImmutable;
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

    /**
     * This year's sales target for the car (BHD). The only figure anybody
     * types: next year's and every month's are worked out from it.
     */
    public string $yearlyTargetInput = '';

    /** Cost & documents editor (chiefly for outside / rented-in cars). */
    public string $purchaseInput = '';

    public string $invoicePath = '';

    public string $agreementPath = '';

    /** Whether this car is rented in from outside (not company-owned). */
    public bool $isOutsideInput = false;

    /** The car's category; an outside car must have one (it is reported by it). */
    public string $categoryInput = '';

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->guardAccess(Permission::Read);
        $this->id = $id;

        if ($id !== null) {
            $car = Vehicle::query()->find($id);
            $yearly = $car !== null ? app(CarTargets::class)->yearly($car->id, (int) now()->year) : 0.0;
            $this->yearlyTargetInput = $yearly > 0
                ? rtrim(rtrim(number_format($yearly, 3, '.', ''), '0'), '.')
                : '';
            $this->purchaseInput = $car !== null && $car->purchase_price > 0
                ? rtrim(rtrim(number_format($car->purchase_price, 3, '.', ''), '0'), '.')
                : '';
            $this->isOutsideInput = $car !== null && $car->is_outside;
            $this->categoryInput = $car !== null ? (string) ($car->category ?? '') : '';
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

        // Outside cars are read by category on the Car income page, so one
        // without a category would sit in a "No category" row nobody opens.
        $this->validate([
            'categoryInput' => [$this->isOutsideInput ? 'required' : 'nullable', 'in:' . implode(',', array_keys(Vehicle::categoryLabels()))],
        ], [
            'categoryInput.required' => __('Choose the category of this outside car.'),
        ], [
            'categoryInput' => __('Category'),
        ]);

        $car->purchase_price = $this->purchaseInput === '' ? 0.0 : max(0.0, (float) $this->purchaseInput);
        $car->is_outside = $this->isOutsideInput;
        if ($this->categoryInput !== '') {
            $car->category = $this->categoryInput;
        }
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

    /**
     * Set / clear this car's target for the current year. Typing a figure
     * makes it the new starting point for the yearly +10%. Manager-gated.
     */
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

        $this->validate(
            ['yearlyTargetInput' => ['nullable', 'numeric', 'min:0', 'max:99999999']],
            [],
            ['yearlyTargetInput' => __('Target for the year')],
        );

        $year = (int) now()->year;
        $yearly = $this->yearlyTargetInput === '' ? 0.0 : (float) $this->yearlyTargetInput;

        $targets = app(CarTargets::class);

        // Saving the figure the system worked out changes nothing, and must not
        // turn "raised automatically" into "typed by hand".
        if (abs($targets->yearly($car->id, $year) - $yearly) < 0.0005) {
            session()->flash('toast', __('Target saved.'));

            return;
        }

        $targets->set($car->id, $year, $yearly);

        app(ActivityLogger::class)->logFor($car, 'updated', $yearly > 0.0
            ? __('Target for :year set to :amount', ['year' => $year, 'amount' => \App\Erp\Views\ValueFormat::money($yearly)])
            : __('Target for :year removed', ['year' => $year]));

        session()->flash('toast', __('Target saved.'));
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

        $now = CarbonImmutable::now();
        $year = (int) $now->year;
        $month = (int) $now->month;
        $targets = app(CarTargets::class);
        $target = null;

        if ($vehicle !== null) {
            $resolved = $targets->resolve($vehicle->id, $year);

            if ($resolved['amount'] > 0.0) {
                $target = $resolved + [
                    'year' => $year,
                    'month' => $targets->monthly($vehicle->id, $year, $month),
                    'monthLabel' => $now->isoFormat('MMMM'),
                    'equalMonths' => $targets->shares($year)['equal'],
                    'earnedMonth' => $targets->earnedInMonth($vehicle->id, $year, $month),
                    'earnedYear' => $targets->earnedInYear($vehicle->id, $year),
                ];
            }
        }

        return view('rental::vehicle-form', [
            'vehicle' => $vehicle,
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'canSeeCost' => $user instanceof User && ($user->isAccountant() || $user->canApproveMaintenance()),
            'target' => $target,
        ]);
    }
}
