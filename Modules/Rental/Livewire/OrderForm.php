<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;

/**
 * Bespoke rental-order form: pick a customer + vehicle + dates + rate and the
 * totals compute live; Save persists a draft, then Start / Close / Cancel /
 * Mark-paid drive the state machine (which keeps the vehicle's availability in
 * sync). A generic engine form can't do the live maths or the transitions, so
 * this is hand-built.
 */
#[Layout('components.layouts.app')]
#[Title('Order')]
final class OrderForm extends Component
{
    public ?int $id = null;

    public ?int $customer_id = null;

    public ?int $vehicle_id = null;

    public ?int $driver_id = null;

    public ?int $branch_id = null;

    public string $start_date = '';

    public string $end_date = '';

    public string $rate_type = 'daily';

    public string $rate = '0';

    public string $discount = '0';

    public string $deposit = '0';

    public string $notes = '';

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'cpr' => '', 'license_no' => ''];

    // Read-only state surfaced in the status panel.
    public string $reference = '';

    public string $state = RentalOrder::STATE_DRAFT;

    public string $payment_status = RentalOrder::PAYMENT_UNPAID;

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $order = RentalOrder::query()->find($id);
            if ($order !== null) {
                $this->id = $order->id;
                $this->customer_id = $order->customer_id;
                $this->vehicle_id = $order->vehicle_id;
                $this->driver_id = $order->driver_id;
                $this->branch_id = $order->branch_id;
                $this->start_date = $order->start_date?->format('Y-m-d') ?? '';
                $this->end_date = $order->end_date?->format('Y-m-d') ?? '';
                $this->rate_type = $order->rate_type;
                $this->rate = (string) $order->rate;
                $this->discount = (string) $order->discount;
                $this->deposit = (string) $order->deposit;
                $this->notes = $order->notes ?? '';
                $this->reference = $order->reference ?? '';
                $this->state = $order->state;
                $this->payment_status = $order->payment_status;

                return;
            }
        }

        // New order: sensible default range (today → tomorrow).
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->addDay()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'vehicle_id' => ['required', 'integer'],
            'driver_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'rate_type' => ['required', 'in:daily,weekly,monthly'],
            'rate' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** Picking a vehicle pre-fills its rate + deposit (and branch if unset). */
    public function updatedVehicleId(mixed $value): void
    {
        $vehicle = $value !== null && $value !== '' ? Vehicle::query()->find((int) $value) : null;
        if ($vehicle === null) {
            return;
        }

        $this->applyVehicleRate($vehicle);
        $this->deposit = (string) $vehicle->deposit;
        if ($this->branch_id === null) {
            $this->branch_id = $vehicle->branch_id;
        }
    }

    /** Switching the rate type re-suggests the matching rate for the vehicle. */
    public function updatedRateType(): void
    {
        $vehicle = $this->vehicle_id !== null ? Vehicle::query()->find($this->vehicle_id) : null;
        if ($vehicle !== null) {
            $this->applyVehicleRate($vehicle);
        }
    }

    private function applyVehicleRate(Vehicle $vehicle): void
    {
        $this->rate = (string) match ($this->rate_type) {
            'weekly' => $vehicle->weekly_rate,
            'monthly' => $vehicle->monthly_rate,
            default => $vehicle->daily_rate,
        };
    }

    public function save(): void
    {
        $this->validate();

        $order = $this->id !== null ? RentalOrder::query()->find($this->id) : new RentalOrder();
        if ($order === null) {
            return;
        }

        $order->customer_id = $this->customer_id;
        $order->vehicle_id = $this->vehicle_id;
        $order->driver_id = $this->driver_id;
        $order->branch_id = $this->branch_id;
        $order->start_date = Carbon::parse($this->start_date);
        $order->end_date = Carbon::parse($this->end_date);
        $order->rate_type = $this->rate_type;
        $order->rate = (float) $this->rate;
        $order->discount = (float) ($this->discount === '' ? '0' : $this->discount);
        $order->deposit = (float) ($this->deposit === '' ? '0' : $this->deposit);
        $order->notes = $this->notes !== '' ? $this->notes : null;
        $order->recalcTotals();
        $order->save();

        session()->flash('toast', __('Order saved.'));
        $this->redirect('/app/rental/order', navigate: true);
    }

    /** Open the inline new-customer modal (adds to the shared customer list). */
    public function openCustomerModal(): void
    {
        $this->newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'cpr' => '', 'license_no' => ''];
        $this->resetValidation();
        $this->addingCustomer = true;
    }

    public function closeCustomerModal(): void
    {
        $this->addingCustomer = false;
    }

    /** Persist a shared customer and select it on the order. */
    public function saveCustomer(): void
    {
        $this->validate([
            'newCustomer.name' => ['required', 'string', 'max:255'],
            'newCustomer.phone' => ['nullable', 'string', 'max:50'],
            'newCustomer.email' => ['nullable', 'email', 'max:255'],
            'newCustomer.cpr' => ['nullable', 'string', 'max:50'],
            'newCustomer.license_no' => ['nullable', 'string', 'max:50'],
        ]);

        $customer = RentalCustomer::query()->create([
            'name' => trim($this->newCustomer['name']),
            'phone' => $this->trimOrNull($this->newCustomer['phone']),
            'email' => $this->trimOrNull($this->newCustomer['email']),
            'cpr' => $this->trimOrNull($this->newCustomer['cpr']),
            'license_no' => $this->trimOrNull($this->newCustomer['license_no']),
        ]);

        $this->customer_id = $customer->id;
        $this->addingCustomer = false;
    }

    private function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function startRental(): void
    {
        $this->withOrder(function (RentalOrder $o): void {
            $o->startRental();
        });
    }

    public function closeRental(): void
    {
        $this->withOrder(function (RentalOrder $o): void {
            $o->closeRental();
        });
    }

    public function cancelOrder(): void
    {
        $this->withOrder(function (RentalOrder $o): void {
            $o->cancelOrder();
        });
    }

    /** Raise an invoice from this order and jump to it. */
    public function createInvoice(): void
    {
        if ($this->id === null) {
            return;
        }

        $order = RentalOrder::query()->find($this->id);
        if ($order === null) {
            return;
        }

        $invoice = $order->createInvoice();
        session()->flash('toast', __('Invoice created.'));
        $this->redirect('/app/rental/invoice/' . $invoice->id, navigate: true);
    }

    public function markPaid(): void
    {
        $this->withOrder(function (RentalOrder $o): void {
            $o->payment_status = RentalOrder::PAYMENT_PAID;
            $o->save();
        });
    }

    public function markUnpaid(): void
    {
        $this->withOrder(function (RentalOrder $o): void {
            $o->payment_status = RentalOrder::PAYMENT_UNPAID;
            $o->save();
        });
    }

    /**
     * Run a mutation against the persisted order, then refresh the panel state.
     */
    private function withOrder(Closure $fn): void
    {
        if ($this->id === null) {
            return;
        }

        $order = RentalOrder::query()->find($this->id);
        if ($order === null) {
            return;
        }

        $fn($order);
        $order->refresh();
        $this->state = $order->state;
        $this->payment_status = $order->payment_status;
    }

    /** A transient order used purely to compute the live summary figures. */
    private function previewOrder(): RentalOrder
    {
        $order = new RentalOrder();
        $order->start_date = $this->start_date !== '' ? Carbon::parse($this->start_date) : null;
        $order->end_date = $this->end_date !== '' ? Carbon::parse($this->end_date) : null;
        $order->rate_type = $this->rate_type;
        $order->rate = (float) ($this->rate === '' ? '0' : $this->rate);
        $order->discount = (float) ($this->discount === '' ? '0' : $this->discount);
        $order->recalcTotals();

        return $order;
    }

    public function render(): View
    {
        $preview = $this->previewOrder();

        return view('rental::order-form', [
            'customers' => RentalCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'status']),
            'drivers' => Driver::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'previewDays' => $preview->days,
            'previewUnits' => $preview->billableUnits(),
            'previewSubtotal' => $preview->subtotal,
            'previewTotal' => $preview->total,
            'isEditing' => $this->id !== null,
        ]);
    }
}
