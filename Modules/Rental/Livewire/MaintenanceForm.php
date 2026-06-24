<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

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
            'status' => ['required', 'in:scheduled,in_progress,done'],
            'notes' => ['nullable', 'string'],
        ];
    }

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
        $record->status = $this->status;
        $record->notes = $this->notes !== '' ? $this->notes : null;
        $record->save();

        // Keep the vehicle's availability in step with the record.
        $record->syncVehicleStatus();

        session()->flash('toast', __('Maintenance record saved.'));
        $this->redirect('/app/rental/maintenance', navigate: true);
    }

    public function render(): View
    {
        return view('rental::maintenance-form', [
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'status']),
            'typeOptions' => RentalMaintenance::typeOptions(),
            'statusOptions' => RentalMaintenance::statusOptions(),
            'isEditing' => $this->id !== null,
        ]);
    }
}
