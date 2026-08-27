<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Livewire\Concerns\HandlesTripLegs;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;

/**
 * Bespoke limousine booking: a customer/PAX header plus unlimited trip legs
 * (transfer / chauffeur), with status actions (confirm → start → complete,
 * cancel, mark paid) and invoicing.
 */
#[Layout('components.layouts.app')]
#[Title('Booking')]
final class BookingForm extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    use HandlesTripLegs;

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    public string $reference = '';

    public string $booking_type = '';

    public ?int $customer_id = null;

    public string $company_reference = '';

    public string $pax_name = '';

    public string $pax_contact = '';

    public string $flight_number = '';

    public string $email = '';

    public string $requested_by = '';

    public string $prepared_by = '';

    public string $advance = '0';

    public string $payment_method = 'cash';

    public string $notes = '';

    public string $status = LimoBooking::STATUS_QUEUE;

    public string $payment_status = LimoBooking::PAYMENT_UNPAID;

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];

    public function mount(?int $id = null): void
    {
        $this->guardAccess(Permission::Read);
        if ($id !== null) {
            $booking = LimoBooking::query()->with('legs')->find($id);
            if ($booking !== null) {
                $this->id = $booking->id;
                $this->reference = $booking->reference ?? '';
                $this->booking_type = $booking->booking_type ?? '';
                $this->customer_id = $booking->customer_id;
                $this->company_reference = $booking->company_reference ?? '';
                $this->pax_name = $booking->pax_name ?? '';
                $this->pax_contact = $booking->pax_contact ?? '';
                $this->flight_number = $booking->flight_number ?? '';
                $this->email = $booking->email ?? '';
                $this->requested_by = $booking->requested_by ?? '';
                $this->prepared_by = $booking->prepared_by ?? '';
                $this->advance = (string) $booking->advance;
                $this->payment_method = $booking->payment_method ?? 'cash';
                $this->notes = $booking->notes ?? '';
                $this->status = $booking->status;
                $this->payment_status = $booking->payment_status;
                $this->loadLegs($booking);

                return;
            }
        }

        $this->seedLegs();
    }

    /**
     * Picking a customer fills the passenger block from their record.
     *
     * The three fields here are the ones that genuinely belong to the customer
     * — who travels, the number the driver rings, and where the confirmation
     * goes — so re-typing them on every booking was pure duplication. They stay
     * EDITABLE on purpose: a company books a car for a visiting guest often
     * enough that locking the PAX name would make those bookings impossible.
     *
     * Switching customer overwrites what's there rather than filling only the
     * blanks, so the sheet always agrees with the customer that is selected.
     * Nothing else auto-fills: the company reference is a per-job PO number,
     * and requested/prepared-by are the staff signing the sheet off.
     */
    public function updatedCustomerId(): void
    {
        if ($this->customer_id === null) {
            return;
        }

        $customer = LimoCustomer::query()->find($this->customer_id);
        if ($customer === null) {
            return;
        }

        $this->pax_name = (string) ($customer->name ?? '');
        $this->pax_contact = (string) ($customer->phone ?? '');
        $this->email = (string) ($customer->email ?? '');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'booking_type' => ['nullable', 'string'],
            'company_reference' => ['nullable', 'string', 'max:255'],
            'pax_name' => ['required', 'string', 'max:255'],
            'pax_contact' => ['nullable', 'string', 'max:100'],
            'flight_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'requested_by' => ['required', 'string', 'max:255'],
            'prepared_by' => ['required', 'string', 'max:255'],
            'advance' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            ...$this->legRules(),
        ];
    }

    public function save(): void
    {
        $this->guardSave($this->id === null);
        $this->validate();

        $booking = $this->id !== null ? LimoBooking::query()->find($this->id) : new LimoBooking();
        if ($booking === null) {
            return;
        }

        $first = $this->legs[0] ?? $this->emptyLeg();

        $booking->booking_type = $this->trimOrNull($this->booking_type);
        $booking->customer_id = $this->customer_id;
        $booking->company_reference = $this->trimOrNull($this->company_reference);
        $booking->pax_name = $this->trimOrNull($this->pax_name);
        $booking->pax_contact = $this->trimOrNull($this->pax_contact);
        $booking->flight_number = $this->trimOrNull($this->flight_number);
        $booking->email = $this->trimOrNull($this->email);
        $booking->requested_by = $this->trimOrNull($this->requested_by);
        $booking->prepared_by = $this->trimOrNull($this->prepared_by);
        $booking->advance = (float) $this->advance;
        $booking->payment_method = $this->trimOrNull($this->payment_method);
        $booking->notes = $this->trimOrNull($this->notes);
        // Header trip basics from the first leg (used by invoicing / the lists).
        $booking->pickup_at = ($first['start_at'] ?? '') !== '' ? Carbon::parse($first['start_at']) : Carbon::now();
        $booking->car_type = null; // the car now lives on each leg
        $booking->save();

        $this->persistLegs($booking); // recreates legs + sets fare/amount = grand total

        session()->flash('toast', __('Booking saved.'));
        $this->redirect('/app/limousine/booking', navigate: true);
    }

    private function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

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

    public function saveCustomer(): void
    {
        $this->guardAccess(Permission::Write);
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
        // Assigning the property server-side does not fire Livewire's `updated`
        // hook, so the fill has to be invoked by hand — otherwise a customer
        // created here would leave the passenger block empty while one picked
        // from the dropdown filled it.
        $this->updatedCustomerId();
        $this->addingCustomer = false;
    }

    public function confirm(): void
    {
        $this->guardAccess(Permission::Write);
        $this->transition(LimoBooking::STATUS_CONFIRMED);
    }

    public function start(): void
    {
        $this->guardAccess(Permission::Write);
        $this->transition(LimoBooking::STATUS_ACTIVE);
    }

    public function complete(): void
    {
        $this->guardAccess(Permission::Write);
        $this->transition(LimoBooking::STATUS_COMPLETED);
    }

    public function cancelBooking(): void
    {
        $this->guardAccess(Permission::Write);
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
        $this->guardAccess(Permission::Write);
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
        $this->guardAccess(Permission::Write);
        $this->withBooking(function (LimoBooking $b): void {
            $b->payment_status = LimoBooking::PAYMENT_PAID;
            $b->save();
        });
    }

    public function markUnpaid(): void
    {
        $this->guardAccess(Permission::Write);
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
        $grand = $this->grandTotal();

        return view('limousine::booking-form', [
            'customers' => LimoCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'bookingTypes' => LimoBooking::bookingTypeOptions(),
            'paymentMethods' => LimoBooking::paymentMethodOptions(),
            'balance' => round(max(0.0, $grand - (float) ($this->advance === '' ? '0' : $this->advance)), 3),
            'isEditing' => $this->id !== null,
            ...$this->legViewData(),
        ]);
    }
}
