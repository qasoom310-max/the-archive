<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\ScrollsToFirstError;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\Branch;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalDocumentEmails;

/**
 * Bespoke quotation form: same live maths as the order form, plus status
 * actions (sent / accepted / declined) and "Convert to order", which spawns a
 * draft rental order and jumps to it.
 */
#[Layout('components.layouts.app')]
#[Title('Quotation')]
final class QuotationForm extends Component
{
    use GuardsModelAccess;
    use ScrollsToFirstError;

    protected function accessModelKey(): string
    {
        return 'rental.quotation';
    }

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    public ?int $customer_id = null;

    public ?int $vehicle_id = null;

    /**
     * The cars after the first one, each with its own rate.
     *
     * @var list<array{vehicle_id: int|string|null, rate: string}>
     */
    public array $extraCars = [];

    public ?int $driver_id = null;

    public ?int $branch_id = null;

    public string $start_date = '';

    public string $end_date = '';

    public string $valid_until = '';

    public string $rate_type = 'daily';

    public string $rate = '0';

    public string $discount = '0';

    public string $deposit = '0';

    public string $notes = '';

    public string $reference = '';

    public string $status = RentalQuotation::STATUS_DRAFT;

    #[Locked]
    public ?int $order_id = null;

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->guardAccess(Permission::Read);
        if ($id !== null) {
            $quote = RentalQuotation::query()->find($id);
            if ($quote !== null) {
                $this->id = $quote->id;
                $this->customer_id = $quote->customer_id;
                $this->vehicle_id = $quote->vehicle_id;
                $this->extraCars = array_map(
                    static fn (array $car): array => ['vehicle_id' => $car['vehicle_id'], 'rate' => (string) $car['rate']],
                    $quote->extraCars(),
                );
                $this->driver_id = $quote->driver_id;
                $this->branch_id = $quote->branch_id;
                $this->start_date = $quote->start_date?->format('Y-m-d') ?? '';
                $this->end_date = $quote->end_date?->format('Y-m-d') ?? '';
                $this->valid_until = $quote->valid_until?->format('Y-m-d') ?? '';
                $this->rate_type = $quote->rate_type;
                $this->rate = (string) $quote->rate;
                $this->discount = (string) $quote->discount;
                $this->deposit = (string) $quote->deposit;
                $this->notes = $quote->notes ?? '';
                $this->reference = $quote->reference ?? '';
                $this->status = $quote->status;
                $this->order_id = $quote->order_id;

                return;
            }
        }

        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->addDay()->format('Y-m-d');
        $this->valid_until = now()->addWeek()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'vehicle_id' => ['required', 'integer'],
            'extraCars' => ['array', 'max:' . (RentalQuotation::MAX_CARS - 1)],
            'extraCars.*.vehicle_id' => ['required', 'integer', 'distinct', 'different:vehicle_id'],
            'extraCars.*.rate' => ['required', 'numeric', 'min:0'],
            'driver_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'valid_until' => ['nullable', 'date'],
            'rate_type' => ['required', 'in:daily,weekly,monthly'],
            'rate' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function updatedVehicleId(mixed $value): void
    {
        $vehicle = $value !== null && $value !== '' ? Vehicle::query()->find((int) $value) : null;
        if ($vehicle === null) {
            return;
        }

        $this->rate = $this->vehicleRate($vehicle);
        $this->refreshDeposit();
        if ($this->branch_id === null) {
            $this->branch_id = $vehicle->branch_id;
        }
    }

    public function updatedRateType(): void
    {
        $vehicle = $this->vehicle_id !== null ? Vehicle::query()->find($this->vehicle_id) : null;
        if ($vehicle !== null) {
            $this->rate = $this->vehicleRate($vehicle);
        }

        foreach ($this->extraCars as $i => $car) {
            $extra = is_numeric($car['vehicle_id'] ?? null) ? Vehicle::query()->find((int) $car['vehicle_id']) : null;
            if ($extra !== null) {
                $this->extraCars[$i]['rate'] = $this->vehicleRate($extra);
            }
        }
    }

    /** "extraCars.2.vehicle_id" picked: that car's own rate, and the deposit follows. */
    public function updatedExtraCars(mixed $value, string $key): void
    {
        if (! preg_match('/^(\d+)\.vehicle_id$/', $key, $m)) {
            return;
        }

        $i = (int) $m[1];
        $vehicle = is_numeric($value) ? Vehicle::query()->find((int) $value) : null;
        if ($vehicle === null || ! isset($this->extraCars[$i])) {
            return;
        }

        $this->extraCars[$i]['rate'] = $this->vehicleRate($vehicle);
        $this->refreshDeposit();
    }

    /** One more car under the first, up to ten in all. */
    public function addCar(): void
    {
        if (count($this->extraCars) >= RentalQuotation::MAX_CARS - 1) {
            return;
        }

        $this->extraCars[] = ['vehicle_id' => null, 'rate' => '0'];
    }

    public function removeCar(int $index): void
    {
        if (! isset($this->extraCars[$index])) {
            return;
        }

        unset($this->extraCars[$index]);
        $this->extraCars = array_values($this->extraCars);
        $this->resetValidation();
        $this->refreshDeposit();
    }

    private function vehicleRate(Vehicle $vehicle): string
    {
        return (string) match ($this->rate_type) {
            'weekly' => $vehicle->weekly_rate,
            'monthly' => $vehicle->monthly_rate,
            default => $vehicle->daily_rate,
        };
    }

    /** The deposit is every chosen car's own deposit added together. */
    private function refreshDeposit(): void
    {
        $ids = array_values(array_filter(
            [$this->vehicle_id, ...array_column($this->extraCars, 'vehicle_id')],
            static fn (mixed $id): bool => is_numeric($id),
        ));

        if ($ids === []) {
            return;
        }

        $this->deposit = (string) round((float) Vehicle::query()->whereKey(array_map('intval', $ids))->sum('deposit'), 3);
    }

    /**
     * @return list<array{vehicle_id: int, rate: float}>
     */
    private function cleanExtraCars(): array
    {
        $out = [];
        foreach (array_slice($this->extraCars, 0, RentalQuotation::MAX_CARS - 1) as $car) {
            if (! is_numeric($car['vehicle_id'] ?? null)) {
                continue;
            }
            $out[] = ['vehicle_id' => (int) $car['vehicle_id'], 'rate' => is_numeric($car['rate']) ? round((float) $car['rate'], 3) : 0.0];
        }

        return $out;
    }

    public function save(): void
    {
        $this->guardSave($this->id === null);
        $this->validateFocusing();

        $isNew = $this->id === null;
        $quote = $this->id !== null ? RentalQuotation::query()->find($this->id) : new RentalQuotation();
        if ($quote === null) {
            return;
        }

        $quote->customer_id = $this->customer_id;
        $quote->vehicle_id = $this->vehicle_id;
        $extra = $this->cleanExtraCars();
        $quote->extra_cars = $extra === [] ? null : $extra;
        $quote->driver_id = $this->driver_id;
        $quote->branch_id = $this->branch_id;
        $quote->start_date = Carbon::parse($this->start_date);
        $quote->end_date = Carbon::parse($this->end_date);
        $quote->valid_until = $this->valid_until !== '' ? Carbon::parse($this->valid_until) : null;
        $quote->rate_type = $this->rate_type;
        $quote->rate = (float) $this->rate;
        $quote->discount = (float) ($this->discount === '' ? '0' : $this->discount);
        $quote->deposit = (float) ($this->deposit === '' ? '0' : $this->deposit);
        $quote->notes = $this->notes !== '' ? $this->notes : null;
        $quote->recalcTotals();
        $quote->save();

        if ($isNew) {
            app(RentalDocumentEmails::class)->quotationCreated((int) $quote->id);
        }

        session()->flash('toast', __('Quotation saved.'));
        $this->redirect('/app/rental/quotation', navigate: true);
    }

    public function markSent(): void
    {
        $this->guardAccess(Permission::Write);
        $this->setStatus(RentalQuotation::STATUS_SENT);
    }

    public function markAccepted(): void
    {
        $this->guardAccess(Permission::Write);
        $this->setStatus(RentalQuotation::STATUS_ACCEPTED);
    }

    public function markDeclined(): void
    {
        $this->guardAccess(Permission::Write);
        $this->setStatus(RentalQuotation::STATUS_DECLINED);
    }

    private function setStatus(string $status): void
    {
        $this->withQuote(function (RentalQuotation $q) use ($status): void {
            $q->status = $status;
            $q->save();
        });
    }

    /** Convert to a draft order and jump straight to it. */
    public function convert(): void
    {
        $this->guardAccess(Permission::Write);
        if ($this->id === null) {
            return;
        }

        $quote = RentalQuotation::query()->find($this->id);
        if ($quote === null) {
            return;
        }

        $order = $quote->convertToOrder();

        session()->flash('toast', $quote->carCount() > 1
            ? __('Converted to :count orders, one per car.', ['count' => $quote->carCount()])
            : __('Converted to order.'));
        $this->redirect('/app/rental/order/' . $order->id, navigate: true);
    }

    private function withQuote(Closure $fn): void
    {
        if ($this->id === null) {
            return;
        }

        $quote = RentalQuotation::query()->find($this->id);
        if ($quote === null) {
            return;
        }

        $fn($quote);
        $quote->refresh();
        $this->status = $quote->status;
        $this->order_id = $quote->order_id;
    }

    private function previewQuote(): RentalQuotation
    {
        $quote = new RentalQuotation();
        $quote->start_date = $this->start_date !== '' ? Carbon::parse($this->start_date) : null;
        $quote->end_date = $this->end_date !== '' ? Carbon::parse($this->end_date) : null;
        $quote->rate_type = $this->rate_type;
        $quote->rate = is_numeric($this->rate) ? (float) $this->rate : 0.0;
        $quote->extra_cars = $this->cleanExtraCars();
        $quote->discount = is_numeric($this->discount) ? (float) $this->discount : 0.0;
        $quote->recalcTotals();

        return $quote;
    }

    /**
     * Active cars, plus any car already on this quotation even if it has
     * since been switched off.
     *
     * @return \Illuminate\Support\Collection<int, Vehicle>
     */
    private function vehicleOptions(): \Illuminate\Support\Collection
    {
        $columns = ['id', 'name', 'plate_no', 'color', 'status'];
        $chosen = array_map('intval', array_filter(array_column($this->extraCars, 'vehicle_id'), 'is_numeric'));

        $list = Vehicle::activeOrSelected($this->vehicle_id, $columns);
        if ($chosen !== []) {
            $missing = array_diff($chosen, $list->pluck('id')->all());
            if ($missing !== []) {
                $list = $list->concat(Vehicle::query()->whereKey($missing)->get($columns));
            }
        }

        return $list->values()->toBase();
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'extraCars.*.vehicle_id.required' => __('Choose a car, or remove this line.'),
            'extraCars.*.vehicle_id.distinct' => __('This car is already on the quotation.'),
            'extraCars.*.vehicle_id.different' => __('This car is already on the quotation.'),
            'extraCars.*.rate.required' => __('Enter a rate for this car.'),
            'extraCars.max' => __('A quotation can have up to :max cars.', ['max' => RentalQuotation::MAX_CARS]),
        ];
    }

    public function render(): View
    {
        $preview = $this->previewQuote();

        return view('rental::quotation-form', [
            // Each already-selected customer/vehicle/driver/branch must stay
            // visible even if it was deactivated after this quotation was
            // raised.
            'customers' => RentalCustomer::activeOrSelected($this->customer_id, ['id', 'name', 'phone']),
            'vehicles' => $this->vehicleOptions(),
            'carCount' => 1 + count($this->extraCars),
            'maxCars' => RentalQuotation::MAX_CARS,
            'drivers' => Driver::activeOrSelected($this->driver_id, ['id', 'name']),
            'branches' => Branch::activeOrSelected($this->branch_id, ['id', 'name']),
            'previewDays' => $preview->days,
            'previewUnits' => $preview->billableUnits(),
            'previewSubtotal' => $preview->subtotal,
            'previewTotal' => $preview->total,
            'isEditing' => $this->id !== null,
        ]);
    }
}
