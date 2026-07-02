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

    // Full booking sheet (ported from the old system).
    public string $booking_type = '';

    public string $booking_to = '';

    public string $contact_person = '';

    public string $company_reference = '';

    public string $pax_name = '';

    public string $pax_contact = '';

    public string $flight_number = '';

    public string $email = '';

    public string $pickup_address = '';

    public string $dropoff_address = '';

    public string $amount = '0';

    public string $discount = '0';

    public string $advance = '0';

    public string $rate_type = '';

    public string $payment_method = 'cash';

    public string $num_cars = '1';

    public string $car_details = '';

    public string $requested_by = '';

    public string $prepared_by = '';

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];

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
                $this->booking_to = $booking->booking_to?->format('Y-m-d\TH:i') ?? '';
                $this->passengers = $booking->passengers !== null ? (string) $booking->passengers : '';
                $this->car_type = $booking->car_type ?? 'sedan';
                $this->driver_name = $booking->driver_name ?? '';
                $this->fare = (string) $booking->fare;
                $this->notes = $booking->notes ?? '';
                $this->reference = $booking->reference ?? '';
                $this->status = $booking->status;
                $this->payment_status = $booking->payment_status;

                $this->booking_type = $booking->booking_type ?? '';
                $this->contact_person = $booking->contact_person ?? '';
                $this->company_reference = $booking->company_reference ?? '';
                $this->pax_name = $booking->pax_name ?? '';
                $this->pax_contact = $booking->pax_contact ?? '';
                $this->flight_number = $booking->flight_number ?? '';
                $this->email = $booking->email ?? '';
                $this->pickup_address = $booking->pickup_address ?? '';
                $this->dropoff_address = $booking->dropoff_address ?? '';
                $this->amount = (string) $booking->amount;
                $this->discount = (string) $booking->discount;
                $this->advance = (string) $booking->advance;
                $this->rate_type = $booking->rate_type ?? '';
                $this->payment_method = $booking->payment_method ?? 'cash';
                $this->num_cars = (string) $booking->num_cars;
                $this->car_details = $booking->car_details ?? '';
                $this->requested_by = $booking->requested_by ?? '';
                $this->prepared_by = $booking->prepared_by ?? '';

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
            'booking_type' => ['nullable', 'string'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'company_reference' => ['nullable', 'string', 'max:255'],
            'pax_name' => ['required', 'string', 'max:255'],
            'pax_contact' => ['nullable', 'string', 'max:100'],
            'flight_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'pickup_location_id' => ['nullable', 'integer'],
            'dropoff_location_id' => ['nullable', 'integer'],
            'pickup_address' => ['nullable', 'string', 'max:1000'],
            'dropoff_address' => ['nullable', 'string', 'max:1000'],
            'pickup_at' => ['required', 'date'],
            'booking_to' => ['nullable', 'date'],
            'passengers' => ['nullable', 'numeric', 'min:0'],
            'car_type' => ['nullable', 'string'],
            'driver_name' => ['nullable', 'string'],
            'num_cars' => ['nullable', 'integer', 'min:1'],
            'car_details' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'advance' => ['nullable', 'numeric', 'min:0'],
            'rate_type' => ['required', 'string'],
            'payment_method' => ['required', 'string'],
            'requested_by' => ['required', 'string', 'max:255'],
            'prepared_by' => ['required', 'string', 'max:255'],
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
        $booking->booking_type = $this->trimOrNull($this->booking_type);
        $booking->contact_person = $this->trimOrNull($this->contact_person);
        $booking->company_reference = $this->trimOrNull($this->company_reference);
        $booking->pax_name = $this->trimOrNull($this->pax_name);
        $booking->pax_contact = $this->trimOrNull($this->pax_contact);
        $booking->flight_number = $this->trimOrNull($this->flight_number);
        $booking->email = $this->trimOrNull($this->email);
        $booking->pickup_location_id = $this->pickup_location_id;
        $booking->dropoff_location_id = $this->dropoff_location_id;
        $booking->pickup_address = $this->trimOrNull($this->pickup_address);
        $booking->dropoff_address = $this->trimOrNull($this->dropoff_address);
        $booking->pickup_at = Carbon::parse($this->pickup_at);
        $booking->booking_to = $this->booking_to !== '' ? Carbon::parse($this->booking_to) : null;
        $booking->passengers = $this->passengers !== '' ? (int) $this->passengers : null;
        $booking->car_type = $this->car_type !== '' ? $this->car_type : null;
        $booking->driver_name = $this->trimOrNull($this->driver_name);
        $booking->num_cars = $this->num_cars !== '' ? max(1, (int) $this->num_cars) : 1;
        $booking->car_details = $this->trimOrNull($this->car_details);
        $booking->amount = (float) $this->amount;
        $booking->discount = (float) $this->discount;
        $booking->advance = (float) $this->advance;
        $booking->fare = $booking->netAmount();   // net = amount − discount
        $booking->rate_type = $this->trimOrNull($this->rate_type);
        $booking->payment_method = $this->trimOrNull($this->payment_method);
        $booking->requested_by = $this->trimOrNull($this->requested_by);
        $booking->prepared_by = $this->trimOrNull($this->prepared_by);
        $booking->notes = $this->trimOrNull($this->notes);
        $booking->save();

        session()->flash('toast', __('Booking saved.'));
        $this->redirect('/app/limousine/booking', navigate: true);
    }

    /** Open the inline new-customer modal (adds to the shared customer list). */
    public function openCustomerModal(): void
    {
        $this->newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];
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
            'newCustomer.phone' => ['required', 'string', 'max:50'],
            'newCustomer.email' => ['required', 'email', 'max:255'],
            'newCustomer.type' => ['required', 'in:individual,company'],
        ]);

        $customer = LimoCustomer::query()->create([
            'name' => trim($this->newCustomer['name']),
            'type' => $this->newCustomer['type'],
            'phone' => trim($this->newCustomer['phone']),
            'email' => trim($this->newCustomer['email']),
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
            'bookingTypes' => LimoBooking::bookingTypeOptions(),
            'rateTypes' => LimoBooking::rateTypeOptions(),
            'paymentMethods' => LimoBooking::paymentMethodOptions(),
            'isEditing' => $this->id !== null,
        ]);
    }
}
