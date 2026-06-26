<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;

/**
 * Car replacement, started from a live rental order. The customer and the
 * original car are taken from the order and locked; you pick an available
 * replacement, record both cars' KM / fuel / condition, and confirming swaps
 * the order onto the new car for the rest of the agreement.
 */
#[Layout('components.layouts.app')]
#[Title('Car replacement')]
final class ReplacementForm extends Component
{
    public ?int $id = null;

    public ?int $order_id = null;

    public ?int $customer_id = null;

    public ?int $original_vehicle_id = null;

    public ?int $replacement_vehicle_id = null;

    public string $date = '';

    public string $reason_type = '';

    public string $reason = '';

    public string $notes = '';

    public ?string $original_return_km = null;

    public string $original_return_fuel = '';

    public string $original_condition_notes = '';

    public ?string $replacement_handover_km = null;

    public string $replacement_handover_fuel = '';

    public string $replacement_condition_notes = '';

    public string $reference = '';

    public string $status = RentalReplacement::STATUS_ACTIVE;

    /** Set when a new replacement is opened without a live, on-road order. */
    public bool $blocked = false;

    public function mount(?int $id = null, ?int $order = null): void
    {
        if ($id !== null) {
            $this->loadExisting($id);

            return;
        }

        $this->date = now()->format('Y-m-d');

        $orderId = $order ?? (int) request()->integer('order');
        $liveOrder = $orderId > 0 ? RentalOrder::query()->find($orderId) : null;

        if ($liveOrder === null || ! $this->orderIsOnRoad($liveOrder)) {
            $this->blocked = true;

            return;
        }

        $this->order_id = $liveOrder->id;
        $this->customer_id = $liveOrder->customer_id;
        $this->original_vehicle_id = $liveOrder->vehicle_id;
        $this->reason_type = RentalReplacement::REASON_BREAKDOWN;

        // Seed the original car's "in" reading from its current odometer.
        $original = $liveOrder->vehicle_id !== null ? Vehicle::query()->find($liveOrder->vehicle_id) : null;
        if ($original?->odometer !== null) {
            $this->original_return_km = (string) $original->odometer;
        }
    }

    private function loadExisting(int $id): void
    {
        $replacement = RentalReplacement::query()->find($id);
        if ($replacement === null) {
            $this->blocked = true;

            return;
        }

        $this->id = $replacement->id;
        $this->order_id = $replacement->order_id;
        $this->customer_id = $replacement->customer_id;
        $this->original_vehicle_id = $replacement->original_vehicle_id;
        $this->replacement_vehicle_id = $replacement->replacement_vehicle_id;
        $this->date = $replacement->date?->format('Y-m-d') ?? '';
        $this->reason_type = $replacement->reason_type ?? '';
        $this->reason = $replacement->reason ?? '';
        $this->notes = $replacement->notes ?? '';
        $this->original_return_km = $replacement->original_return_km !== null ? (string) $replacement->original_return_km : null;
        $this->original_return_fuel = $replacement->original_return_fuel ?? '';
        $this->original_condition_notes = $replacement->original_condition_notes ?? '';
        $this->replacement_handover_km = $replacement->replacement_handover_km !== null ? (string) $replacement->replacement_handover_km : null;
        $this->replacement_handover_fuel = $replacement->replacement_handover_fuel ?? '';
        $this->replacement_condition_notes = $replacement->replacement_condition_notes ?? '';
        $this->reference = $replacement->reference ?? '';
        $this->status = $replacement->status;
    }

    /** An order with a car physically out: active, handed over, not yet returned. */
    private function orderIsOnRoad(RentalOrder $order): bool
    {
        return $order->state === RentalOrder::STATE_ACTIVE
            && $order->started_at !== null
            && $order->returned_at === null
            && $order->vehicle_id !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        $reasons = array_column(RentalReplacement::reasonTypeOptions(), 'value');
        $fuels = array_column(RentalOrder::fuelLevelOptions(), 'value');

        return [
            'reason_type' => ['required', 'string', 'in:' . implode(',', $reasons)],
            'replacement_vehicle_id' => ['required', 'integer', 'different:original_vehicle_id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'original_return_km' => ['nullable', 'integer', 'min:0'],
            'replacement_handover_km' => ['nullable', 'integer', 'min:0'],
            'original_return_fuel' => ['nullable', 'in:' . implode(',', $fuels)],
            'replacement_handover_fuel' => ['nullable', 'in:' . implode(',', $fuels)],
            'original_condition_notes' => ['nullable', 'string'],
            'replacement_condition_notes' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        if ($this->id !== null || $this->blocked) {
            return; // existing replacements are read-only; the swap already happened
        }

        $this->validate();

        // The replacement car must be free (available). Valid papers are required
        // too — except a super-admin may override for an urgent swap, matching
        // the booking rule.
        $replacementQuery = Vehicle::query()
            ->where('active', true)
            ->where('status', Vehicle::STATUS_AVAILABLE)
            ->whereKey($this->replacement_vehicle_id);

        if (! $this->isSuperAdmin()) {
            $replacementQuery->bookable();
        }

        $replacementCar = $replacementQuery->first();

        if ($replacementCar === null) {
            $this->addError('replacement_vehicle_id', __('Pick an available car with valid papers.'));

            return;
        }

        $replacement = new RentalReplacement();
        $replacement->order_id = $this->order_id;
        $replacement->customer_id = $this->customer_id;
        $replacement->original_vehicle_id = $this->original_vehicle_id;
        $replacement->replacement_vehicle_id = $this->replacement_vehicle_id;
        $replacement->date = Carbon::parse($this->date);
        $replacement->reason_type = $this->reason_type;
        $replacement->reason = $this->reason !== '' ? $this->reason : null;
        $replacement->notes = $this->notes !== '' ? $this->notes : null;
        $replacement->original_return_km = $this->original_return_km !== null && $this->original_return_km !== '' ? (int) $this->original_return_km : null;
        $replacement->original_return_fuel = $this->original_return_fuel !== '' ? $this->original_return_fuel : null;
        $replacement->original_condition_notes = $this->original_condition_notes !== '' ? $this->original_condition_notes : null;
        $replacement->replacement_handover_km = $this->replacement_handover_km !== null && $this->replacement_handover_km !== '' ? (int) $this->replacement_handover_km : null;
        $replacement->replacement_handover_fuel = $this->replacement_handover_fuel !== '' ? $this->replacement_handover_fuel : null;
        $replacement->replacement_condition_notes = $this->replacement_condition_notes !== '' ? $this->replacement_condition_notes : null;
        $replacement->created_by_user_id = Auth::id();
        $replacement->save();

        $replacement->activate();

        $logger = app(ActivityLogger::class);
        $logger->logFor($replacement, 'created');
        if ($this->order_id !== null) {
            $order = RentalOrder::query()->find($this->order_id);
            if ($order !== null) {
                $logger->logFor($order, 'car_replaced', __('Swapped to :car', ['car' => $replacementCar->displayName()]));
            }
        }

        session()->flash('toast', __('Car replaced. The order now runs on the new car.'));
        $this->redirect('/app/rental/order/' . $this->order_id, navigate: true);
    }

    public function close(): void
    {
        if ($this->id === null) {
            return;
        }

        $replacement = RentalReplacement::query()->find($this->id);
        if ($replacement === null) {
            return;
        }

        $replacement->close();
        app(ActivityLogger::class)->logFor($replacement, 'closed');
        $this->status = $replacement->status;
    }

    private function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function render(): View
    {
        $original = $this->original_vehicle_id !== null ? Vehicle::query()->find($this->original_vehicle_id) : null;
        $order = $this->order_id !== null ? RentalOrder::query()->with('customer:id,name,phone')->find($this->order_id) : null;

        // Free (available) cars — same branch first. Valid papers are required,
        // except a super-admin sees lapsed-paper cars too (urgent override), the
        // same rule the order's car picker uses.
        $isSuperAdmin = $this->isSuperAdmin();
        $branchId = $original?->branch_id;
        $availableQuery = Vehicle::query()
            ->where('active', true)
            ->where('status', Vehicle::STATUS_AVAILABLE)
            ->when($this->original_vehicle_id !== null, fn (Builder $q) => $q->where('id', '!=', $this->original_vehicle_id));

        if (! $isSuperAdmin) {
            $availableQuery->bookable();
        }

        $available = $availableQuery
            ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$branchId ?? 0])
            ->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'color', 'branch_id', 'registration_expiry', 'insurance_expiry']);

        return view('rental::replacement-form', [
            'isEditing' => $this->id !== null,
            'blocked' => $this->blocked,
            'isSuperAdmin' => $isSuperAdmin,
            'order' => $order,
            'originalVehicle' => $original,
            'availableVehicles' => $available,
            'reasonOptions' => RentalReplacement::reasonTypeOptions(),
            'fuelOptions' => RentalOrder::fuelLevelOptions(),
            'replacementVehicle' => $this->replacement_vehicle_id !== null ? Vehicle::query()->find($this->replacement_vehicle_id) : null,
        ]);
    }
}
