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

        $this->applyVehicleRate($vehicle);
        $this->deposit = (string) $vehicle->deposit;
        if ($this->branch_id === null) {
            $this->branch_id = $vehicle->branch_id;
        }
    }

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
        $this->guardSave($this->id === null);
        $this->validateFocusing();

        $quote = $this->id !== null ? RentalQuotation::query()->find($this->id) : new RentalQuotation();
        if ($quote === null) {
            return;
        }

        $quote->customer_id = $this->customer_id;
        $quote->vehicle_id = $this->vehicle_id;
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

        session()->flash('toast', __('Converted to order.'));
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
        $quote->rate = (float) ($this->rate === '' ? '0' : $this->rate);
        $quote->discount = (float) ($this->discount === '' ? '0' : $this->discount);
        $quote->recalcTotals();

        return $quote;
    }

    public function render(): View
    {
        $preview = $this->previewQuote();

        return view('rental::quotation-form', [
            'customers' => RentalCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'plate_no', 'color', 'status']),
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
