<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\Vehicle;

/**
 * Bespoke maintenance-record form. Saving reflects the record's status onto
 * the vehicle (in progress → under maintenance, done → available).
 */
#[Layout('components.layouts.app')]
#[Title('Maintenance')]
final class MaintenanceForm extends Component
{
    public ?int $id = null;

    public ?int $vehicle_id = null;

    public string $date = '';

    public string $type = 'service';

    public string $description = '';

    public string $cost = '0';

    public string $odometer = '';

    public string $status = RentalMaintenance::STATUS_SCHEDULED;

    public string $notes = '';

    public string $reference = '';

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $record = RentalMaintenance::query()->find($id);
            if ($record !== null) {
                $this->id = $record->id;
                $this->vehicle_id = $record->vehicle_id;
                $this->date = $record->date?->format('Y-m-d') ?? '';
                $this->type = $record->type;
                $this->description = $record->description ?? '';
                $this->cost = (string) $record->cost;
                $this->odometer = $record->odometer !== null ? (string) $record->odometer : '';
                $this->status = $record->status;
                $this->notes = $record->notes ?? '';
                $this->reference = $record->reference ?? '';

                return;
            }
        }

        $this->date = now()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'type' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Save the record's DETAILS only. The status is never set here — it moves
     * through the workflow buttons (Start / Complete / Cancel), so an employee
     * can't free-type a car into "done" or jump states. A new record is born
     * Scheduled and leaves the car untouched.
     */
    public function save(): void
    {
        $this->validate();

        $record = $this->id !== null ? RentalMaintenance::query()->find($this->id) : new RentalMaintenance();
        if ($record === null) {
            return;
        }

        $record->vehicle_id = $this->vehicle_id;
        $record->date = Carbon::parse($this->date);
        $record->type = $this->type;
        $record->description = $this->description !== '' ? $this->description : null;
        $record->cost = (float) ($this->cost === '' ? '0' : $this->cost);
        $record->odometer = $this->odometer !== '' ? (int) $this->odometer : null;
        $record->notes = $this->notes !== '' ? $this->notes : null;
        $record->save();

        session()->flash('toast', __('Maintenance record saved.'));
        // Land on the record so its workflow panel (Start / Complete) shows.
        $this->redirect('/app/rental/maintenance/' . $record->id, navigate: true);
    }

    /** Run a workflow transition against the saved record, then refresh status. */
    private function withRecord(Closure $fn): void
    {
        if ($this->id === null) {
            return;
        }

        $record = RentalMaintenance::query()->with('vehicle')->find($this->id);
        if ($record === null) {
            return;
        }

        $fn($record); // the transition mutates $record->status in place
        $this->status = $record->status;
    }

    /** Scheduled → In progress. Blocked unless the car is free at the branch. */
    public function startMaintenance(): void
    {
        if ($this->id === null) {
            return;
        }

        $record = RentalMaintenance::query()->with('vehicle')->find($this->id);
        if ($record === null) {
            return;
        }

        if (! $record->vehicleIsFree()) {
            $carStatus = (string) (Vehicle::query()->whereKey($record->vehicle_id)->value('status') ?? 'rented');
            session()->flash('toast', __('This car is :status — free it first (do a replacement so it’s back at the branch) before starting maintenance.', ['status' => __(ucfirst($carStatus))]));

            return;
        }

        $record->start(); // mutates $record->status in place
        $this->status = $record->status;
        session()->flash('toast', __('Maintenance started.'));
    }

    /** In progress → Done. Frees the car back to Available. */
    public function completeMaintenance(): void
    {
        $this->withRecord(fn (RentalMaintenance $r) => $r->complete());
        session()->flash('toast', __('Maintenance completed — car is available again.'));
    }

    /** Scheduled → Cancelled. */
    public function cancelMaintenance(): void
    {
        $this->withRecord(fn (RentalMaintenance $r) => $r->cancelRecord());
        session()->flash('toast', __('Maintenance cancelled.'));
    }

    public function render(): View
    {
        return view('rental::maintenance-form', [
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'plate_no', 'color', 'status']),
            'typeOptions' => RentalMaintenance::typeOptions(),
            'isEditing' => $this->id !== null,
        ]);
    }
}
