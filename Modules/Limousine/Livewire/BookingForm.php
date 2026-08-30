<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\ScrollsToFirstError;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Livewire\Concerns\HandlesTripLegs;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\CouponRedeemer;

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
    use ScrollsToFirstError;

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

    /**
     * Who raised this booking — stamped from the signed-in user, never typed.
     *
     * `#[Locked]` because the browser can set any unlocked public property in
     * Livewire 3: without it, a read-only input would still be trivially
     * rewritten from the client, which defeats the point of a sign-off field.
     * `save()` re-stamps it on create as well, so the value never depends on
     * what arrived from the browser.
     */
    #[Locked]
    public string $prepared_by = '';

    public string $advance = '0';

    /** Refund-coupon code being applied against this booking. */
    public string $couponCode = '';

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
                // Keep whoever actually raised this booking. Re-stamping it with
                // the current viewer would quietly rewrite the sign-off every
                // time somebody else opened the record — the opposite of an
                // audit trail. Only a brand-new booking gets stamped (below).
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

        $this->prepared_by = $this->currentUserName();
        $this->seedLegs();
    }

    /**
     * Display name for the signed-in user, for the "Prepared by" stamp.
     *
     * Falls back to the email because POS-style staff accounts can be
     * username-only, and an empty string would trip the `required` rule and
     * block a save on a field nobody can type into.
     */
    private function currentUserName(): string
    {
        $user = Auth::user();
        if ($user === null) {
            return '';
        }

        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : trim((string) ($user->email ?? ''));
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

        // Stamp the preparer from the session before validating, so a new
        // booking can never fail the `required` rule on a field the user is
        // not allowed to type in. An existing booking keeps its original
        // preparer — editing someone else's booking must not reassign it.
        if ($this->id === null) {
            $this->prepared_by = $this->currentUserName();
        }

        $this->validateFocusing();

        $wasNew = $this->id === null;

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

        $this->persistLegs($booking); // updates legs + sets fare/amount = grand total

        // After the legs have priced the job, decide the payment flag from the
        // money taken. This has to run AFTER persistLegs, which is what sets the
        // fare the advance is compared against.
        $booking->syncPaymentFromAdvance();
        $this->payment_status = $booking->payment_status;

        // Confirm with the REFERENCES rather than just "saved": they are what
        // the office quotes to the customer, and a multi-leg booking produces
        // one per trip — so name them all, not only the booking's own number.
        $refs = $booking->legs()->orderBy('sequence')->pluck('reference')
            ->filter()->map(static fn ($r): string => (string) $r)->all();

        session()->flash('toast', $this->savedMessage($wasNew, $refs));
        $this->redirect('/app/limousine/booking', navigate: true);
    }

    /**
     * Spend a refund coupon against this booking.
     *
     * Only on a saved booking: credit is applied to the money already taken, so
     * there has to be a priced job to apply it to. Applies the smaller of what
     * is left on the coupon and what is still owed, so a big coupon keeps its
     * balance for the next trip and a small one just reduces the bill.
     */
    public function applyCoupon(): void
    {
        $this->guardSave(false);

        if ($this->id === null) {
            $this->addError('couponCode', __('Save the booking first, then apply a coupon.'));

            return;
        }

        if (trim($this->couponCode) === '') {
            return;
        }

        $booking = LimoBooking::query()->find($this->id);
        if ($booking === null) {
            return;
        }

        $redeemer = app(CouponRedeemer::class);
        $result = $redeemer->apply($this->couponCode, $booking);

        if (! ($result['ok'] ?? false)) {
            $this->addError('couponCode', $redeemer->errorMessage((string) ($result['error'] ?? '')));

            return;
        }

        // Reflect the money that just landed, so the form shows the new balance
        // without a reload.
        $booking->refresh();
        $this->advance = (string) $booking->advance;
        $this->payment_status = $booking->payment_status;
        $this->couponCode = '';

        session()->flash('toast', __('Coupon applied: :amount BD. Remaining on coupon: :left BD.', [
            'amount' => number_format((float) ($result['applied'] ?? 0), 3),
            'left' => number_format((float) ($result['remaining'] ?? 0), 3),
        ]));
    }

    /**
     * The confirmation the office reads out to the customer.
     *
     * One line per trip reference, because a booking with three legs hands the
     * customer three numbers — quoting only the booking's own would leave them
     * unable to ask about a single trip.
     *
     * @param  list<string>  $refs
     */
    private function savedMessage(bool $isNew, array $refs): string
    {
        if ($refs === []) {
            return $isNew ? (string) __('Booking done successfully.') : (string) __('Booking has been updated successfully.');
        }

        $lines = array_map(
            fn (string $ref): string => $isNew
                ? (string) __('Booking done successfully. Ref. # :ref', ['ref' => $ref])
                : (string) __('Booking has been updated successfully. Ref. # :ref', ['ref' => $ref]),
            $refs,
        );

        return implode("\n", $lines);
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
        $this->validateFocusing([
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

    /**
     * Dispatch the trip. This is the point a car has to exist: bookings are
     * taken before anyone knows which vehicle will run them, so `car_id` is
     * optional at save time and only becomes mandatory here.
     */
    public function start(): void
    {
        $this->guardAccess(Permission::Write);

        $unassigned = [];
        foreach ($this->legs as $i => $leg) {
            if (($leg['car_id'] ?? '') === '' || $leg['car_id'] === null) {
                $unassigned[] = $i;
            }
        }

        if ($unassigned !== []) {
            foreach ($unassigned as $i) {
                $this->addError("legs.$i.car_id", __('Assign a car before starting the trip.'));
            }

            return;
        }

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

    /**
     * Move the whole booking, legs and all.
     *
     * Legs are dispatched individually from the queue, but the buttons on this
     * sheet act on the job as a whole — so the status is pushed DOWN to every
     * leg rather than set on the parent alone. Without that the booking and its
     * legs would disagree, and `syncStatusFromLegs()` would immediately undo it.
     * Cancelled legs are left cancelled.
     */
    private function transition(string $status): void
    {
        $this->withBooking(function (LimoBooking $b) use ($status): void {
            $b->legs()->where('status', '!=', LimoLeg::STATUS_CANCELLED)->update(['status' => $status]);
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
