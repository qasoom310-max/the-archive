<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Settings\Setting;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Rental\Mail\RentalAgreementMail;
use Modules\Rental\Services\RentalAgreementPdf;
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

    /** Drop-off — deliver the car (flat RentalOrder::DELIVERY_FEE when on). */
    public bool $delivery = false;

    /** Pick-up — collect the car back (flat RentalOrder::PICKUP_FEE when on). */
    public bool $pickup = false;

    /** Where to deliver the car — shown/required only when Delivery is on. */
    public string $delivery_location = '';

    public string $advance_amount = '0';

    /** What we pay the outside vendor for this booking (outside cars only). */
    public string $outside_cost = '0';

    public string $deposit = '0';

    public string $payment_type = 'cash';

    public string $notes = '';

    /**
     * Document uploads. Stored paths come from the direct-upload controller
     * (a single synchronous POST that works on Hostinger shared hosting, unlike
     * Livewire's two-phase async upload). Empty = "no new file".
     */
    public string $cprImagePath = '';

    public string $licenseImagePath = '';

    public ?string $existingCprImage = null;

    public ?string $existingLicenseImage = null;

    // ── Handover (Start) capture modal ───────────────────────────────────
    public bool $showHandover = false;

    public string $handover_km = '';

    public string $handover_fuel = 'full';

    public string $handover_notes = '';

    public string $handover_video_url = '';

    // ── Return (Close) capture modal ─────────────────────────────────────
    public bool $showReturn = false;

    public string $return_km = '';

    public string $return_fuel = 'full';

    /** Fuel level the customer RECEIVED (read-only hint on the return modal). */
    public string $receivedFuel = '';

    /** Refuel amount the employee enters for a fuel shortfall (BHD). */
    public string $fuel_charge = '0';

    /** Free-form extra charge added at return (extra day, fee…) and what it's for. */
    public string $extra_charge = '0';

    public string $extra_charge_note = '';

    public bool $has_damage = false;

    public string $damage_notes = '';

    public string $damage_video_url = '';

    /** Mandatory video of the car's condition on return. */
    public string $return_video_url = '';

    /** Deposit settlement modal (accountant / super-admin only). */
    public bool $showDeposit = false;

    /** refund | deduct | forfeit */
    public string $depositOutcome = 'refund';

    public string $deposit_deducted = '0';

    public string $deposit_reason = '';

    /** @var array<int, string> Uploaded evidence-photo paths (direct upload). */
    public array $depositPhotoPaths = [];

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'cpr' => '', 'license_no' => ''];

    // Read-only state surfaced in the status panel.
    public string $reference = '';

    public string $state = RentalOrder::STATE_DRAFT;

    public string $payment_status = RentalOrder::PAYMENT_UNPAID;

    public bool $payment_confirmed = false;

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
                $this->pickup = $order->pickup;
                $this->delivery_location = $order->delivery_location ?? '';
                $this->advance_amount = (string) $order->advance_amount;
                $this->outside_cost = (string) $order->outside_cost;
                $this->deposit = (string) $order->deposit;
                $this->payment_type = $order->payment_type ?? 'cash';
                $this->notes = $order->notes ?? '';
                $this->existingCprImage = $order->cpr_image_path;
                $this->existingLicenseImage = $order->license_image_path;
                $this->reference = $order->reference ?? '';
                $this->state = $order->state;
                $this->payment_status = $order->payment_status;
                $this->payment_confirmed = $order->payment_confirmed;

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
            // Pick-up can't be before today — unless an admin / super-admin is
            // deliberately backdating a booking.
            'start_date' => $this->canBackdate()
                ? ['required', 'date']
                : ['required', 'date', 'after_or_equal:today'],
            // Return must be a LATER day than pick-up (no same / earlier day).
            'end_date' => ['required', 'date', 'after:start_date'],
            'hired_time' => ['required', 'string', 'max:10'],
            'rate_type' => ['required', 'in:daily,weekly,monthly'],
            'rate' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'delivery' => ['boolean'],
            'pickup' => ['boolean'],
            // Either service needs a location; otherwise the box is hidden and ignored.
            'delivery_location' => $this->delivery || $this->pickup ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'start_date.after_or_equal' => __('The pick-up date can’t be in the past. Only an admin can backdate a booking.'),
            'end_date.after' => __('The return date must be after the pick-up date.'),
        ];
    }

    /** Admins (and super-admins, a superset) may book a past pick-up date. */
    private function canBackdate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
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
        // For an outside (rented-in) car, suggest the vendor cost from the car's
        // agreed purchase price; cleared for an owned car.
        $this->outside_cost = $vehicle->is_outside ? (string) $vehicle->purchase_price : '0';
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
        // On a validation failure, point the user at the first missing field
        // (the form is long and the Save button sits at the bottom).
        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->dispatch('order-scroll-to-error');

            throw $e;
        }

        // Block double-booking: refuse if the car is already held (reserved or
        // rented) by another open order over overlapping dates.
        if ($this->vehicle_id !== null) {
            $conflict = RentalOrder::overlappingOpenOrder(
                $this->vehicle_id,
                Carbon::parse($this->start_date),
                Carbon::parse($this->end_date),
                $this->id,
            );
            if ($conflict !== null) {
                $this->addError('vehicle_id', __('This car is already booked (:ref) for overlapping dates.', ['ref' => $conflict->reference ?? '']));

                return;
            }
        }

        $order = $this->id !== null ? RentalOrder::query()->find($this->id) : new RentalOrder();
        if ($order === null) {
            return;
        }

        $wasNew = ! $order->exists;

        if ($wasNew) {
            $order->created_by_user_id = Auth::id();
        }

        // Remember the previously-held car so we can release it if it changes.
        $previousVehicleId = $order->exists ? $order->vehicle_id : null;

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
        $order->vat_rate = RentalOrder::DEFAULT_VAT_RATE; // fixed rate, not user-editable
        $order->delivery = $this->delivery;
        $order->pickup = $this->pickup;
        // Keep a location only while a drop-off or pick-up is on; clear it otherwise.
        $order->delivery_location = ($this->delivery || $this->pickup) ? $this->trimOrNull($this->delivery_location) : null;
        $order->advance_amount = $this->toFloat($this->advance_amount);
        $order->outside_cost = $this->toFloat($this->outside_cost);
        $order->deposit = $this->toFloat($this->deposit);
        $order->payment_type = $this->trimOrNull($this->payment_type);
        $order->notes = $this->trimOrNull($this->notes);

        if ($this->cprImagePath !== '') {
            $order->cpr_image_path = $this->cprImagePath;
        }
        if ($this->licenseImagePath !== '') {
            $order->license_image_path = $this->licenseImagePath;
        }

        $order->recalcTotals();
        $order->save();

        app(ActivityLogger::class)->logFor($order, $wasNew ? 'created' : 'updated');

        // Hold the car: a draft reserves it. If the vehicle changed, free the
        // old one (when nothing else holds it).
        if ($previousVehicleId !== null && $previousVehicleId !== $order->vehicle_id) {
            RentalOrder::releaseVehicleIfUnheld($previousVehicleId, $order->id);
        }
        if ($order->state === RentalOrder::STATE_DRAFT) {
            $order->reserveVehicle();
        }

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

    /** Fuel-gauge values, for the `in:` validation rule. */
    private function fuelValues(): string
    {
        return implode(',', array_column(RentalOrder::fuelLevelOptions(), 'value'));
    }

    /** Start rental → open the handover capture modal (pre-fill the KM). */
    public function startRental(): void
    {
        if ($this->id === null) {
            return;
        }

        $this->handover_km = $this->pickup_mileage;
        if ($this->handover_km === '' && $this->vehicle_id !== null) {
            $odo = Vehicle::query()->where('id', $this->vehicle_id)->value('odometer');
            $this->handover_km = $odo !== null ? (string) $odo : '';
        }
        $this->handover_fuel = 'full';
        $this->handover_notes = '';
        $this->handover_video_url = '';
        $this->resetValidation();
        $this->showHandover = true;
    }

    public function closeHandover(): void
    {
        $this->showHandover = false;
    }

    /** Record the handover details, then hand the car over (draft → active). */
    public function confirmHandover(): void
    {
        if ($this->id === null) {
            return;
        }

        $this->validate([
            'handover_km' => ['nullable', 'integer', 'min:0'],
            'handover_fuel' => ['required', 'in:' . $this->fuelValues()],
            'handover_notes' => ['nullable', 'string', 'max:1000'],
            'handover_video_url' => ['nullable', 'url', 'max:500'],
        ]);

        $this->withOrder(function (RentalOrder $o): void {
            $o->handover_km = $this->handover_km !== '' ? (int) $this->handover_km : null;
            $o->handover_fuel = $this->handover_fuel;
            $o->handover_notes = $this->trimOrNull($this->handover_notes);
            $o->handover_video_url = $this->trimOrNull($this->handover_video_url);
            $o->startRental();
            app(ActivityLogger::class)->logFor($o, 'started');
        });

        $this->showHandover = false;
        session()->flash('toast', __('Car handed over.'));
    }

    /** Close rental → open the return capture modal (pre-fill the KM). */
    public function closeRental(): void
    {
        if ($this->id === null) {
            return;
        }

        $order = RentalOrder::query()->find($this->id);
        if ($order === null) {
            return;
        }

        $this->return_km = $order->handover_km !== null ? (string) $order->handover_km : $this->pickup_mileage;
        // Default to the level it went out with, and show what was received.
        $this->return_fuel = $order->handover_fuel ?? 'full';
        $this->receivedFuel = RentalOrder::fuelLabel($order->handover_fuel);
        $this->fuel_charge = '0';
        $this->extra_charge = '0';
        $this->extra_charge_note = '';
        $this->has_damage = false;
        $this->damage_notes = '';
        $this->damage_video_url = '';
        $this->return_video_url = $order->return_video_url ?? '';
        $this->resetValidation();
        $this->showReturn = true;
    }

    public function closeReturn(): void
    {
        $this->showReturn = false;
    }

    /** Record the return details, then receive the car back (active → closed). */
    public function confirmReturn(): void
    {
        if ($this->id === null) {
            return;
        }

        $order = RentalOrder::query()->find($this->id);
        if ($order === null) {
            return;
        }

        // The car can't come back with fewer KM than it went out with.
        $floor = $order->handover_km ?? 0;

        $this->validate([
            'return_km' => ['required', 'integer', 'min:' . $floor],
            'return_fuel' => ['required', 'in:' . $this->fuelValues()],
            'fuel_charge' => ['nullable', 'numeric', 'min:0'],
            'extra_charge' => ['nullable', 'numeric', 'min:0'],
            'extra_charge_note' => ['nullable', 'string', 'max:255'],
            'has_damage' => ['boolean'],
            'damage_notes' => $this->has_damage ? ['required', 'string', 'max:1000'] : ['nullable', 'string', 'max:1000'],
            'damage_video_url' => ['nullable', 'url', 'max:500'],
            'return_video_url' => ['required', 'url', 'max:500'],
        ], [
            'return_km.min' => __('The return KM can’t be less than the handover KM (:km).', ['km' => $floor]),
            'return_video_url.required' => __('A return video is required to close the rental.'),
        ]);

        $this->withOrder(function (RentalOrder $o): void {
            $o->return_km = $this->return_km !== '' ? (int) $this->return_km : null;
            $o->return_fuel = $this->return_fuel;
            $o->fuel_charge = $this->toFloat($this->fuel_charge);
            $o->extra_charge = $this->toFloat($this->extra_charge);
            $o->extra_charge_note = $this->trimOrNull($this->extra_charge_note);
            $o->has_damage = $this->has_damage;
            $o->damage_notes = $this->has_damage ? $this->trimOrNull($this->damage_notes) : null;
            $o->damage_video_url = $this->has_damage ? $this->trimOrNull($this->damage_video_url) : null;
            $o->return_video_url = $this->trimOrNull($this->return_video_url);
            $o->recalcTotals(); // fold the fuel + extra charge into the total/balance
            $o->closeRental();
            app(ActivityLogger::class)->logFor($o, 'returned');
        });

        $this->showReturn = false;
        session()->flash('toast', __('Car returned.'));
    }

    public function cancelOrder(): void
    {
        $this->withOrder(function (RentalOrder $o): void {
            $o->cancelOrder();
            app(ActivityLogger::class)->logFor($o, 'cancelled');
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
        app(ActivityLogger::class)->logFor($order, 'invoiced');
        session()->flash('toast', __('Invoice created.'));
        $this->redirect('/app/rental/invoice/' . $invoice->id, navigate: true);
    }

    /** Email the self-contained agreement PDF to the customer on file. */
    public function emailAgreement(): void
    {
        if ($this->id === null) {
            return;
        }

        $order = RentalOrder::query()->with('customer', 'vehicle', 'branch')->find($this->id);
        if ($order === null) {
            return;
        }

        // Send once only.
        if ($order->agreement_emailed_at !== null) {
            session()->flash('toast', __('The agreement was already emailed to this customer.'));

            return;
        }

        $email = $order->customer?->email;
        if (! is_string($email) || trim($email) === '') {
            session()->flash('toast', __('This customer has no email — add one to send the agreement.'));

            return;
        }

        $pdf = app(RentalAgreementPdf::class)->render($order);
        Mail::to(trim($email))->send(new RentalAgreementMail($pdf, $order, (string) Setting::get('company.name', 'OpenERP')));

        $order->agreement_emailed_at = Carbon::now();
        $order->save();

        session()->flash('toast', __('Agreement emailed to :email.', ['email' => trim($email)]));
    }

    /** Only the accountant / super-admin may confirm money was received. */
    private function canConfirmPayments(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canConfirmPayments();
    }

    /** Accountant / super-admin confirms a fully-paid order's payment. */
    public function confirmPayment(): void
    {
        abort_unless($this->canConfirmPayments(), 403);

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $this->withOrder(function (RentalOrder $o) use ($user): void {
            $o->confirmPayment($user);
            app(ActivityLogger::class)->logFor($o, 'payment_confirmed');
        });
    }

    /** Revoke a payment confirmation (same gate). */
    public function unconfirmPayment(): void
    {
        abort_unless($this->canConfirmPayments(), 403);

        $this->withOrder(function (RentalOrder $o): void {
            $o->unconfirmPayment();
        });
    }

    /** A super-admin may settle a deposit early / use a lapsed-papers car. */
    private function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /** Open the deposit-settlement modal (accountant / super-admin only). */
    public function settleDeposit(): void
    {
        abort_unless($this->canConfirmPayments(), 403);

        // The deposit is held for 14 days — only a super-admin may settle early.
        $order = $this->id !== null ? RentalOrder::query()->find($this->id) : null;
        if ($order !== null && ! $order->depositHoldElapsed() && ! $this->isSuperAdmin()) {
            session()->flash('toast', __('The deposit is held until :date — only a super-admin can settle it early.', ['date' => $order->depositHoldUntil()?->format('Y-m-d')]));

            return;
        }

        $this->depositOutcome = 'refund';
        $this->deposit_deducted = '0';
        $this->deposit_reason = '';
        $this->depositPhotoPaths = [];
        $this->resetValidation();
        $this->showDeposit = true;
    }

    public function closeDeposit(): void
    {
        $this->showDeposit = false;
    }

    /** Direct-upload callback: append an uploaded evidence-photo path. */
    public function addDepositPhoto(string $path): void
    {
        if ($path !== '' && count($this->depositPhotoPaths) < 10) {
            $this->depositPhotoPaths[] = $path;
        }
    }

    /** Record the deposit outcome — refund / deduct part / forfeit — with reason + photos. */
    public function confirmDeposit(): void
    {
        abort_unless($this->canConfirmPayments(), 403);

        $user = Auth::user();
        if (! $user instanceof User || $this->id === null) {
            return;
        }

        $order = RentalOrder::query()->find($this->id);
        if ($order === null) {
            return;
        }

        // Re-check the hold here too (the modal could be opened then submitted).
        if (! $order->depositHoldElapsed() && ! $this->isSuperAdmin()) {
            $this->showDeposit = false;
            session()->flash('toast', __('The deposit is still within its 14-day hold.'));

            return;
        }

        $rules = [
            'depositOutcome' => ['required', 'in:refund,deduct,forfeit'],
            'depositPhotoPaths' => ['array', 'max:10'],
            'depositPhotoPaths.*' => ['string'],
        ];
        if ($this->depositOutcome === 'deduct') {
            $rules['deposit_deducted'] = ['required', 'numeric', 'gt:0', 'max:' . $order->deposit];
            $rules['deposit_reason'] = ['required', 'string', 'max:1000'];
        } elseif ($this->depositOutcome === 'forfeit') {
            $rules['deposit_reason'] = ['required', 'string', 'max:1000'];
        }

        $this->validate($rules, [
            'deposit_deducted.max' => __('The deduction can’t be more than the deposit (:amount).', ['amount' => $order->deposit]),
        ]);

        // How much of the deposit is kept.
        $deducted = match ($this->depositOutcome) {
            'forfeit' => $order->deposit,
            'deduct' => $this->toFloat($this->deposit_deducted),
            default => 0.0,
        };

        // Evidence photos were already uploaded (direct controller) — use paths.
        $paths = array_values(array_filter($this->depositPhotoPaths, static fn (string $p): bool => $p !== ''));

        $order->resolveDeposit($user, $deducted, $this->depositOutcome === 'refund' ? null : $this->deposit_reason, $paths);
        app(ActivityLogger::class)->logFor($order, 'deposit_settled');

        $this->showDeposit = false;
        $this->depositPhotoPaths = [];
        session()->flash('toast', __('Deposit settled.'));
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
        $this->payment_confirmed = $order->payment_confirmed;
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
        $order->vat_rate = RentalOrder::DEFAULT_VAT_RATE; // fixed rate, not user-editable
        $order->delivery = $this->delivery;
        $order->pickup = $this->pickup;
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

        // A car with lapsed registration / insurance is held out of the picker
        // until it's renewed — except for a super-admin (urgent override). The
        // currently-selected car is always kept so editing never drops it.
        $user = Auth::user();
        $isSuperAdmin = $user instanceof User && $user->isSuperAdmin();
        $cols = ['id', 'name', 'plate_no', 'color', 'status', 'is_outside', 'registration_expiry', 'insurance_expiry'];
        $vehiclesQuery = Vehicle::query()->where('active', true);
        if (! $isSuperAdmin) {
            $current = $this->vehicle_id;
            $today = Carbon::today();
            $vehiclesQuery->where(function (Builder $q) use ($current, $today): void {
                $q->where(function (Builder $valid) use ($today): void {
                    $valid->whereNotNull('registration_expiry')
                        ->whereNotNull('insurance_expiry')
                        ->whereDate('registration_expiry', '>=', $today)
                        ->whereDate('insurance_expiry', '>=', $today);
                });
                if ($current !== null) {
                    $q->orWhere('id', $current);
                }
            });
        }

        return view('rental::order-form', [
            'customers' => RentalCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => $vehiclesQuery->orderBy('name')->get($cols),
            'isSuperAdmin' => $isSuperAdmin,
            'drivers' => Driver::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'selectedVehicle' => $selectedVehicle,
            'paymentTypes' => RentalOrder::paymentTypeOptions(),
            'fuelLevels' => RentalOrder::fuelLevelOptions(),
            // The persisted order, for the read-only handover / return summary
            // and the payment-confirmation details (who / when).
            'savedOrder' => $this->id !== null ? RentalOrder::query()->with('confirmedBy', 'depositResolvedBy')->find($this->id) : null,
            'canConfirmPayment' => $this->canConfirmPayments(),
            'canBackdate' => $this->canBackdate(),
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
