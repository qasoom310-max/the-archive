<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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

    public string $priority = RentalMaintenance::PRIORITY_NORMAL;

    public string $description = '';

    public string $cost = '0';

    public string $odometer = '';

    public string $status = RentalMaintenance::STATUS_PENDING;

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
                $this->priority = $record->priority;
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

    /** Picking a car loads its current KM from the vehicle record (editable). */
    public function updatedVehicleId(): void
    {
        $vehicle = $this->vehicle_id !== null ? Vehicle::query()->find($this->vehicle_id) : null;
        $this->odometer = $vehicle !== null && $vehicle->odometer !== null ? (string) $vehicle->odometer : '';
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
            'priority' => ['required', 'in:low,normal,high,critical'],
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
     * Pending approval and leaves the car untouched.
     */
    public function save(): void
    {
        $this->validate();

        $record = $this->id !== null ? RentalMaintenance::query()->find($this->id) : new RentalMaintenance();
        if ($record === null) {
            return;
        }

        // Stamp who raised the request (once, on creation).
        $userId = Auth::id();
        if (! $record->exists && $userId !== null) {
            $record->requested_by_user_id = (int) $userId;
        }

        $record->vehicle_id = $this->vehicle_id;
        $record->date = Carbon::parse($this->date);
        $record->type = $this->type;
        $record->priority = $this->priority;
        $record->description = $this->description !== '' ? $this->description : null;
        $record->cost = (float) ($this->cost === '' ? '0' : $this->cost);
        $record->odometer = $this->odometer !== '' ? (int) $this->odometer : null;
        $record->notes = $this->notes !== '' ? $this->notes : null;
        $wasNew = ! $record->exists;
        $record->save();

        app(ActivityLogger::class)->logFor($record, $wasNew ? 'created' : 'updated');
        session()->flash('toast', __('Maintenance record saved.'));
        // Land on the record so its workflow panel (Start / Complete) shows.
        $this->redirect('/app/rental/maintenance/' . $record->id, navigate: true);
    }

    /** Only a fleet manager (admin / super-admin) may approve or decline work. */
    public function canApprove(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canApproveMaintenance();
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

    /** Pending → Approved. Manager authorises the work / spend. */
    public function approveMaintenance(): void
    {
        abort_unless($this->canApprove(), 403);

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $this->withRecord(function (RentalMaintenance $r) use ($user): void {
            $r->approve($user);
            app(ActivityLogger::class)->logFor($r, 'approved');
        });
        session()->flash('toast', __('Work order approved.'));
    }

    /** Pending → Declined. Manager refuses the work. */
    public function declineMaintenance(): void
    {
        abort_unless($this->canApprove(), 403);

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $this->withRecord(function (RentalMaintenance $r) use ($user): void {
            $r->decline($user);
            app(ActivityLogger::class)->logFor($r, 'declined');
        });
        session()->flash('toast', __('Work order declined.'));
    }

    /** Approved → In progress. Blocked unless the car is free at the branch. */
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

        if ($record->start()) { // mutates $record->status in place
            $this->status = $record->status;
            app(ActivityLogger::class)->logFor($record, 'started');
            session()->flash('toast', __('Maintenance started.'));
        }
    }

    /** In progress → Done. Frees the car back to Available. */
    public function completeMaintenance(): void
    {
        $this->withRecord(function (RentalMaintenance $r): void {
            $r->complete();
            app(ActivityLogger::class)->logFor($r, 'completed');
        });
        session()->flash('toast', __('Maintenance completed — car is available again.'));
    }

    /** Pending / Approved → Cancelled. */
    public function cancelMaintenance(): void
    {
        $this->withRecord(function (RentalMaintenance $r): void {
            $r->cancelRecord();
            app(ActivityLogger::class)->logFor($r, 'cancelled');
        });
        session()->flash('toast', __('Maintenance cancelled.'));
    }

    public function render(): View
    {
        return view('rental::maintenance-form', [
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'plate_no', 'color', 'status']),
            'typeOptions' => RentalMaintenance::typeOptions(),
            'priorityOptions' => RentalMaintenance::priorityOptions(),
            'canApprove' => $this->canApprove(),
            'savedRecord' => $this->id !== null ? RentalMaintenance::query()->with('approvedBy', 'requestedBy')->find($this->id) : null,
            'isEditing' => $this->id !== null,
        ]);
    }
}
