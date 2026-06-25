<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
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
    use WithFileUploads;

    public ?int $id = null;

    public string $order_date = '';

    public ?int $customer_id = null;

    public string $phone = '';

    public ?int $vehicle_id = null;

    public string $pickup_mileage = '';

    public ?int $driver_id = null;

    public string $additional_driver = '';

    public string $additional_driver_license = '';

    public ?int $branch_id = null;

    public string $start_date = '';

    public string $end_date = '';

    public string $hired_time = '';

    public string $rate_type = 'daily';

    public string $rate = '0';

    public string $discount = '0';

    public string $vat_rate = '10';

    /** Delivery option — a fixed flat fee (RentalOrder::DELIVERY_FEE) when on. */
    public bool $delivery = false;

    public string $advance_amount = '0';

    public string $deposit = '0';

    public string $payment_type = 'cash';

    public string $notes = '';

    /** Document uploads (temporary), plus any already-saved paths. */
    public ?TemporaryUploadedFile $cprPhoto = null;

    public ?TemporaryUploadedFile $licensePhoto = null;

    public ?string $existingCprImage = null;

    public ?string $existingLicenseImage = null;

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
                $this->order_date = $order->order_date?->format('Y-m-d') ?? '';
                $this->customer_id = $order->customer_id;
                $this->phone = $order->phone ?? '';
                $this->vehicle_id = $order->vehicle_id;
                $this->pickup_mileage = $order->pickup_mileage !== null ? (string) $order->pickup_mileage : '';
                $this->driver_id = $order->driver_id;
                $this->additional_driver = $order->additional_driver ?? '';
                $this->additional_driver_license = $order->additional_driver_license ?? '';
                $this->branch_id = $order->branch_id;
                $this->start_date = $order->start_date?->format('Y-m-d') ?? '';
                $this->end_date = $order->end_date?->format('Y-m-d') ?? '';
                $this->hired_time = $order->hired_time ?? '';
                $this->rate_type = $order->rate_type;
                $this->rate = (string) $order->rate;
                $this->discount = (string) $order->discount;
                $this->vat_rate = (string) $order->vat_rate;
                $this->delivery = $order->delivery;
                $this->advance_amount = (string) $order->advance_amount;
                $this->deposit = (string) $order->deposit;
                $this->payment_type = $order->payment_type ?? 'cash';
                $this->notes = $order->notes ?? '';
                $this->existingCprImage = $order->cpr_image_path;
                $this->existingLicenseImage = $order->license_image_path;
                $this->reference = $order->reference ?? '';
                $this->state = $order->state;
                $this->payment_status = $order->payment_status;

                return;
            }
        }

        // New order: today's date, and a sensible default range (today → tomorrow).
        $this->order_date = now()->format('Y-m-d');
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->addDay()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'order_date' => ['nullable', 'date'],
            'customer_id' => ['required', 'integer'],
            'phone' => ['nullable', 'string', 'max:50'],
            'vehicle_id' => ['required', 'integer'],
            'pickup_mileage' => ['nullable', 'numeric', 'min:0'],
            'driver_id' => ['nullable', 'integer'],
            'additional_driver' => ['nullable', 'string', 'max:255'],
            'additional_driver_license' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'hired_time' => ['nullable', 'string', 'max:10'],
            'rate_type' => ['required', 'in:daily,weekly,monthly'],
            'rate' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delivery' => ['boolean'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'cprPhoto' => ['nullable', 'image', 'max:4096'],
            'licensePhoto' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /** Picking a vehicle pre-fills its rate + deposit + branch + current mileage. */
    public function updatedVehicleId(mixed $value): void
    {
        $vehicle = $value !== null && $value !== '' ? Vehicle::query()->find((int) $value) : null;
        if ($vehicle === null) {
            return;
        }

        $this->applyVehicleRate($vehicle);
        $this->deposit = (string) $vehicle->deposit;
        if ($this->pickup_mileage === '' && $vehicle->odometer !== null) {
            $this->pickup_mileage = (string) $vehicle->odometer;
        }
        if ($this->branch_id === null) {
            $this->branch_id = $vehicle->branch_id;
        }
    }

    /** Picking a customer pre-fills their phone (a snapshot on the order). */
    public function updatedCustomerId(mixed $value): void
    {
        $customer = $value !== null && $value !== '' ? RentalCustomer::query()->find((int) $value) : null;
        if ($customer !== null) {
            // Auto-fill the phone from the chosen customer (still editable, and
            // refreshes if a different customer is picked).
            $this->phone = (string) ($customer->phone ?? '');
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

        $order->order_date = $this->order_date !== '' ? Carbon::parse($this->order_date) : null;
        $order->customer_id = $this->customer_id;
        $order->phone = $this->trimOrNull($this->phone);
        $order->vehicle_id = $this->vehicle_id;
        $order->pickup_mileage = $this->pickup_mileage !== '' ? (int) $this->pickup_mileage : null;
        $order->driver_id = $this->driver_id;
        $order->additional_driver = $this->trimOrNull($this->additional_driver);
        $order->additional_driver_license = $this->trimOrNull($this->additional_driver_license);
        $order->branch_id = $this->branch_id;
        $order->start_date = Carbon::parse($this->start_date);
        $order->end_date = Carbon::parse($this->end_date);
        $order->hired_time = $this->trimOrNull($this->hired_time);
        $order->rate_type = $this->rate_type;
        $order->rate = (float) $this->rate;
        $order->discount = $this->toFloat($this->discount);
        $order->vat_rate = $this->vat_rate === '' ? RentalOrder::DEFAULT_VAT_RATE : (float) $this->vat_rate;
        $order->delivery = $this->delivery;
        $order->advance_amount = $this->toFloat($this->advance_amount);
        $order->deposit = $this->toFloat($this->deposit);
        $order->payment_type = $this->trimOrNull($this->payment_type);
        $order->notes = $this->trimOrNull($this->notes);

        if ($this->cprPhoto instanceof TemporaryUploadedFile) {
            $stored = $this->cprPhoto->store('rental_orders', 'public');
            if (is_string($stored)) {
                $order->cpr_image_path = $stored;
            }
        }
        if ($this->licensePhoto instanceof TemporaryUploadedFile) {
            $stored = $this->licensePhoto->store('rental_orders', 'public');
            if (is_string($stored)) {
                $order->license_image_path = $stored;
            }
        }

        $order->recalcTotals();
        $order->save();

        session()->flash('toast', __('Order saved.'));
        $this->redirect('/app/rental/order', navigate: true);
    }

    private function toFloat(string $value): float
    {
        return $value === '' ? 0.0 : (float) $value;
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
        // Setting customer_id in PHP doesn't fire updatedCustomerId, so mirror
        // the phone auto-fill here for the inline-created customer.
        $this->phone = (string) ($customer->phone ?? '');
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
        $order->rate = $this->toFloat($this->rate);
        $order->discount = $this->toFloat($this->discount);
        $order->vat_rate = $this->vat_rate === '' ? RentalOrder::DEFAULT_VAT_RATE : (float) $this->vat_rate;
        $order->delivery = $this->delivery;
        $order->advance_amount = $this->toFloat($this->advance_amount);
        $order->recalcTotals();

        return $order;
    }

    public function render(): View
    {
        $preview = $this->previewOrder();

        $selectedVehicle = $this->vehicle_id !== null
            ? Vehicle::query()->find($this->vehicle_id)
            : null;

        return view('rental::order-form', [
            'customers' => RentalCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'status']),
            'drivers' => Driver::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'selectedVehicle' => $selectedVehicle,
            'paymentTypes' => RentalOrder::paymentTypeOptions(),
            'previewDays' => $preview->days,
            'previewUnits' => $preview->billableUnits(),
            'previewSubtotal' => $preview->subtotal,
            'previewVat' => $preview->vat_amount,
            'previewDelivery' => $preview->delivery_charges,
            'previewTotal' => $preview->total,
            'previewBalance' => $preview->balance,
            'isEditing' => $this->id !== null,
        ]);
    }
}
