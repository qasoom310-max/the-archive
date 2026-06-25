<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLocation;

/**
 * Bespoke limousine booking form: a trip from pickup → dropoff at a time for a
 * fare, with status actions (confirm → start → complete, cancel, mark paid).
 */
#[Layout('components.layouts.app')]
#[Title('Booking')]
final class BookingForm extends Component
{
    public ?int $id = null;

    public ?int $customer_id = null;

    public ?int $pickup_location_id = null;

    public ?int $dropoff_location_id = null;

    public string $pickup_at = '';

    public string $passengers = '';

    public string $car_type = 'sedan';

    public string $driver_name = '';

    public string $fare = '0';

    public string $notes = '';

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'cpr' => '', 'license_no' => ''];

    public string $reference = '';

    public string $status = LimoBooking::STATUS_QUEUE;

    public string $payment_status = LimoBooking::PAYMENT_UNPAID;

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $booking = LimoBooking::query()->find($id);
            if ($booking !== null) {
                $this->id = $booking->id;
                $this->customer_id = $booking->customer_id;
                $this->pickup_location_id = $booking->pickup_location_id;
                $this->dropoff_location_id = $booking->dropoff_location_id;
                $this->pickup_at = $booking->pickup_at?->format('Y-m-d\TH:i') ?? '';
                $this->passengers = $booking->passengers !== null ? (string) $booking->passengers : '';
                $this->car_type = $booking->car_type ?? 'sedan';
                $this->driver_name = $booking->driver_name ?? '';
                $this->fare = (string) $booking->fare;
                $this->notes = $booking->notes ?? '';
                $this->reference = $booking->reference ?? '';
                $this->status = $booking->status;
                $this->payment_status = $booking->payment_status;

                return;
            }
        }

        $this->pickup_at = now()->addHour()->format('Y-m-d\TH:i');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'pickup_location_id' => ['nullable', 'integer'],
            'dropoff_location_id' => ['nullable', 'integer'],
            'pickup_at' => ['required', 'date'],
            'passengers' => ['nullable', 'numeric', 'min:0'],
            'car_type' => ['nullable', 'string'],
            'driver_name' => ['nullable', 'string'],
            'fare' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $booking = $this->id !== null ? LimoBooking::query()->find($this->id) : new LimoBooking();
        if ($booking === null) {
            return;
        }

        $booking->customer_id = $this->customer_id;
        $booking->pickup_location_id = $this->pickup_location_id;
        $booking->dropoff_location_id = $this->dropoff_location_id;
        $booking->pickup_at = Carbon::parse($this->pickup_at);
        $booking->passengers = $this->passengers !== '' ? (int) $this->passengers : null;
        $booking->car_type = $this->car_type !== '' ? $this->car_type : null;
        $booking->driver_name = $this->driver_name !== '' ? $this->driver_name : null;
        $booking->fare = (float) $this->fare;
        $booking->notes = $this->notes !== '' ? $this->notes : null;
        $booking->save();

        session()->flash('toast', __('Booking saved.'));
        $this->redirect('/app/limousine/booking', navigate: true);
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

    /** Persist a shared customer and select it on the booking. */
    public function saveCustomer(): void
    {
        $this->validate([
            'newCustomer.name' => ['required', 'string', 'max:255'],
            'newCustomer.phone' => ['nullable', 'string', 'max:50'],
            'newCustomer.email' => ['nullable', 'email', 'max:255'],
            'newCustomer.cpr' => ['nullable', 'string', 'max:50'],
            'newCustomer.license_no' => ['nullable', 'string', 'max:50'],
        ]);

        $customer = LimoCustomer::query()->create([
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

    public function confirm(): void
    {
        $this->transition(LimoBooking::STATUS_CONFIRMED);
    }

    public function start(): void
    {
        $this->transition(LimoBooking::STATUS_ACTIVE);
    }

    public function complete(): void
    {
        $this->transition(LimoBooking::STATUS_COMPLETED);
    }

    public function cancelBooking(): void
    {
        $this->transition(LimoBooking::STATUS_CANCELLED);
    }

    private function transition(string $status): void
    {
        $this->withBooking(function (LimoBooking $b) use ($status): void {
            $b->setStatus($status);
        });
    }

    public function createInvoice(): void
    {
        if ($this->id === null) {
            return;
        }

        $booking = LimoBooking::query()->find($this->id);
        if ($booking === null) {
            return;
        }

        $invoice = $booking->createInvoice();
        session()->flash('toast', __('Invoice created.'));
        $this->redirect('/app/limousine/invoice/' . $invoice->id, navigate: true);
    }

    public function markPaid(): void
    {
        $this->withBooking(function (LimoBooking $b): void {
            $b->payment_status = LimoBooking::PAYMENT_PAID;
            $b->save();
        });
    }

    public function markUnpaid(): void
    {
        $this->withBooking(function (LimoBooking $b): void {
            $b->payment_status = LimoBooking::PAYMENT_UNPAID;
            $b->save();
        });
    }

    private function withBooking(Closure $fn): void
    {
        if ($this->id === null) {
            return;
        }

        $booking = LimoBooking::query()->find($this->id);
        if ($booking === null) {
            return;
        }

        $fn($booking);
        $booking->refresh();
        $this->status = $booking->status;
        $this->payment_status = $booking->payment_status;
    }

    public function render(): View
    {
        return view('limousine::booking-form', [
            'customers' => LimoCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'locations' => LimoLocation::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'carTypes' => LimoBooking::carTypeOptions(),
            'isEditing' => $this->id !== null,
        ]);
    }
}
