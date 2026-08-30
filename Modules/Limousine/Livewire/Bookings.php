<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\ServiceOrderSender;
use Modules\Limousine\Services\TripCancellation;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * Limousine bookings list — status tabs + a pick-up date range filter.
 */
#[Layout('components.layouts.app')]
#[Title('Bookings')]
final class Bookings extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    /** all | queue | confirmed | active | completed | cancelled */
    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Free-text search across reference, customer, passenger and route. */
    #[Url(except: '')]
    public string $search = '';

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    /** LEG whose car is being assigned, or null when the modal is shut. */
    #[Locked]
    public ?int $assigningId = null;

    /** The chosen car for that leg. */
    public string $assignCar = '';

    /** The chosen driver for that leg. */
    public string $assignDriver = '';

    /** BOOKING being quick-edited, or null when that dialog is shut. */
    #[Locked]
    public ?int $editingId = null;

    /**
     * The leg whose row opened the dialog. The car and driver are assigned PER
     * LEG from the queue, so the dialog shows that trip's crew read-only rather
     * than pretending a booking has one car.
     */
    #[Locked]
    public ?int $editingLegId = null;

    /** Trip whose cancel dialog is open, or null when it is shut. */
    #[Locked]
    public ?int $cancellingId = null;

    public string $cancelReason = '';

    /** Give a due refund as credit instead of money back. */
    public bool $cancelAsCoupon = false;

    /**
     * Quick-edit field values, keyed by column. A flat array keeps the dialog
     * declarative — adding a field is one entry here plus one input.
     *
     * @var array<string, string>
     */
    public array $edit = [];

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /**
     * Open the car assignment for ONE leg.
     *
     * Deliberately per leg, not per booking: legs run at different times on
     * different days, so they are dispatched independently and each gets its
     * own vehicle. Assigning them together as a group would force the office to
     * think about a trip they aren't sending yet. All they really share is a
     * receipt, and that lives on the booking.
     */
    public function openAssign(int $legId): void
    {
        $this->guardAccess(Permission::Write);

        $leg = LimoLeg::query()->with('legable')->find($legId);
        if ($leg === null || ! $leg->legable instanceof LimoBooking) {
            return;
        }

        $this->assigningId = $leg->id;
        $this->assignCar = $leg->car_id !== null ? (string) $leg->car_id : '';
        $this->assignDriver = $leg->driver_id !== null ? (string) $leg->driver_id : '';
    }

    public function closeAssign(): void
    {
        $this->assigningId = null;
        $this->assignCar = '';
        $this->assignDriver = '';
    }

    /**
     * Move one leg along its own track: queue → confirmed → active → completed.
     *
     * Each leg runs at its own hour with its own car, so one can be finished
     * while the next is still waiting. The booking's own status follows the
     * least-progressed leg, so the parent still reads as "active" until every
     * leg is done.
     *
     * A leg cannot go Active without a car — the same rule the booking form
     * enforced, kept here now that dispatch happens from this screen.
     */
    public function advanceLeg(int $legId, string $to): void
    {
        $this->guardAccess(Permission::Write);

        $allowed = [
            LimoLeg::STATUS_CONFIRMED,
            LimoLeg::STATUS_ACTIVE,
            LimoLeg::STATUS_COMPLETED,
            LimoLeg::STATUS_CANCELLED,
        ];
        if (! in_array($to, $allowed, true)) {
            return;
        }

        $leg = LimoLeg::query()->with('legable')->find($legId);
        if ($leg === null || ! $leg->legable instanceof LimoBooking) {
            return;
        }

        if ($to === LimoLeg::STATUS_ACTIVE && $leg->car_id === null) {
            session()->flash('toast', __('Assign a car before starting the trip.'));

            return;
        }

        $leg->status = $to;
        $leg->save();

        $leg->legable->syncStatusFromLegs();
    }

    /**
     * Save the chosen car onto the open leg.
     *
     * Writes `car_id` plus the label snapshot the rest of the app reads, so a
     * leg still names its vehicle if the fleet entry is later renamed. The leg
     * is re-read from `assigningId`, which is #[Locked] — the car id itself
     * comes from the browser, so an unknown vehicle is refused rather than
     * stored as a dangling reference.
     */
    public function saveAssign(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->assigningId === null) {
            return;
        }

        $leg = LimoLeg::query()->with('legable')->find($this->assigningId);
        if ($leg === null) {
            $this->closeAssign();

            return;
        }

        // Car and driver are set together but resolved independently: an
        // unknown id from the browser clears that one field rather than being
        // stored as a dangling reference, and a blank selection un-assigns.
        $car = $this->assignCar !== '' ? Vehicle::query()->find((int) $this->assignCar) : null;
        $leg->car_id = $car?->id;
        $leg->vehicle = $car?->displayName();

        $driver = $this->assignDriver !== '' ? LimoDriver::query()->find((int) $this->assignDriver) : null;
        $leg->driver_id = $driver?->id;
        $leg->driver = $driver?->displayName();

        // Naming a driver IS dispatching the trip — there is nothing left to
        // decide once a car and a person are against it, so the leg goes Active
        // here rather than needing a second click on the row.
        //
        // Only from queue/confirmed: editing the driver on a leg that already
        // ran must not drag a completed or cancelled trip back to Active.
        $dispatchable = in_array($leg->status, [LimoLeg::STATUS_QUEUE, LimoLeg::STATUS_CONFIRMED], true);
        $started = false;

        if ($driver !== null && $dispatchable) {
            if ($leg->car_id === null) {
                // The pre-existing rule stands: no car, no trip. Say so instead
                // of silently saving a driver and leaving the leg where it was.
                $leg->save();
                $this->closeAssign();
                session()->flash('toast', __('Assign a car before starting the trip.'));

                return;
            }

            $leg->status = LimoLeg::STATUS_ACTIVE;
            $started = true;
        }

        $leg->save();

        if ($started && $leg->legable instanceof LimoBooking) {
            // The booking summarises its legs, so it follows them up.
            $leg->legable->syncStatusFromLegs();
        }

        $this->closeAssign();
        session()->flash('toast', $started ? __('Trip started.') : __('Assignment saved.'));
    }

    /**
     * Vehicles offerable for assignment: everything free in the Rent A Car
     * fleet, plus whatever is already on this leg so an existing choice does
     * not vanish from its own dropdown once the car is marked rented.
     *
     * @return list<array{value: int, label: string}>
     */
    public function carOptions(): array
    {
        $ids = Vehicle::query()->where('active', true)
            ->where('status', Vehicle::STATUS_AVAILABLE)->pluck('id')->all();
        if ($this->assignCar !== '') {
            $ids[] = (int) $this->assignCar;
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return Vehicle::query()->whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'color', 'is_outside'])
            ->map(fn (Vehicle $v): array => [
                'value' => $v->id,
                'label' => $v->displayName() . ($v->is_outside ? ' · ' . __('Outside') : ''),
            ])
            ->all();
    }

    /**
     * Drivers offerable for assignment: everyone active, plus whoever is
     * already on this leg so an existing choice cannot vanish from its own
     * dropdown after being deactivated.
     *
     * @return list<array{value: int, label: string}>
     */
    public function driverOptions(): array
    {
        $query = LimoDriver::query()->where('active', true);
        if ($this->assignDriver !== '') {
            $query->orWhere('id', (int) $this->assignDriver);
        }

        return $query->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn (LimoDriver $d): array => ['value' => $d->id, 'label' => $d->displayName()])
            ->all();
    }

    /**
     * Open the quick-edit dialog for the BOOKING a leg belongs to.
     *
     * The assign modal above is per leg (each leg is dispatched separately);
     * these are booking-level details — passenger, contact, flight, rate — that
     * are shared by every leg of the job, so editing them is per booking.
     *
     * Deliberately excludes money: the fare is the sum of the legs
     * ({@see LimoBooking::recalcTotal()}), so a figure typed here would be
     * silently overwritten the next time a leg changes. It is shown read-only
     * with a link to the full booking.
     */
    public function openEdit(int $bookingId, ?int $legId = null): void
    {
        $this->guardAccess(Permission::Write);

        $booking = LimoBooking::query()->find($bookingId);
        if ($booking === null) {
            return;
        }

        $this->editingId = $bookingId;
        // Which leg's row was clicked, so the dialog can show the car and driver
        // actually dispatched for THAT trip.
        $this->editingLegId = $legId;
        $this->resetErrorBag();
        $this->edit = [
            'booking_type' => (string) ($booking->booking_type ?? ''),
            // <input type="datetime-local"> only accepts exactly "Y-m-d\TH:i".
            'pickup_at' => $booking->pickup_at?->format('Y-m-d\TH:i') ?? '',
            'booking_to' => $booking->booking_to?->format('Y-m-d\TH:i') ?? '',
            'flight_number' => (string) ($booking->flight_number ?? ''),
            'email' => (string) ($booking->email ?? ''),
            'pax_name' => (string) ($booking->pax_name ?? ''),
            'pax_contact' => (string) ($booking->pax_contact ?? ''),
            'contact_person' => (string) ($booking->contact_person ?? ''),
            'company_reference' => (string) ($booking->company_reference ?? ''),
            'rate_type' => (string) ($booking->rate_type ?? ''),
            'notes' => (string) ($booking->notes ?? ''),
        ];
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingLegId = null;
        $this->edit = [];
        $this->resetErrorBag();
    }

    /** Save the quick-edit dialog back onto the booking. */
    public function saveEdit(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->editingId === null) {
            return;
        }

        $booking = LimoBooking::query()->find($this->editingId);
        if ($booking === null) {
            $this->cancelEdit();

            return;
        }

        $this->validate([
            'edit.pickup_at' => ['nullable', 'date'],
            'edit.booking_to' => ['nullable', 'date', 'after_or_equal:edit.pickup_at'],
            'edit.email' => ['nullable', 'email', 'max:255'],
            'edit.pax_name' => ['nullable', 'string', 'max:255'],
            'edit.pax_contact' => ['nullable', 'string', 'max:50'],
            'edit.flight_number' => ['nullable', 'string', 'max:100'],
            'edit.contact_person' => ['nullable', 'string', 'max:255'],
            'edit.company_reference' => ['nullable', 'string', 'max:255'],
            'edit.notes' => ['nullable', 'string'],
        ], [], [
            'edit.pickup_at' => __('Booking from'),
            'edit.booking_to' => __('Booking to'),
        ]);

        $text = fn (string $key): ?string => trim((string) ($this->edit[$key] ?? '')) !== ''
            ? trim((string) $this->edit[$key])
            : null;

        foreach ([
            'booking_type', 'flight_number', 'email',
            'pax_name', 'pax_contact', 'contact_person', 'company_reference', 'rate_type', 'notes',
        ] as $field) {
            $booking->{$field} = $text($field);
        }

        $pickupAt = $text('pickup_at');
        $bookingTo = $text('booking_to');
        $booking->pickup_at = $pickupAt !== null ? Carbon::parse($pickupAt) : null;
        $booking->booking_to = $bookingTo !== null ? Carbon::parse($bookingTo) : null;

        $booking->save();

        $this->cancelEdit();
        session()->flash('booking_status', __('Booking updated.'));
    }

    /**
     * Email the customer the link to sign this leg's Service Order — the proof
     * the driver arrived and the trip was used.
     */
    public function sendServiceOrder(int $legId): void
    {
        $this->guardAccess(Permission::Write);

        $leg = LimoLeg::query()->find($legId);
        if ($leg === null) {
            return;
        }

        try {
            $result = app(ServiceOrderSender::class)->send($leg);
        } catch (Throwable $e) {
            // Mail can fail for reasons the office can act on (bad address, SMTP
            // down). Say so plainly instead of a silent no-op or a 500.
            session()->flash('booking_status', __('Could not send the service order: :error', ['error' => $e->getMessage()]));

            return;
        }

        if ($result === null) {
            session()->flash('booking_status', __('That customer has no email address on file — add one first.'));

            return;
        }

        // Say which kind went out: a company gets told, an individual gets asked
        // to sign, and the office should know which happened.
        session()->flash('booking_status', $result['kind'] === ServiceOrderSender::KIND_NOTICE
            ? __('Service completed notice sent to :email.', ['email' => $result['email']])
            : __('Signing link sent to :email.', ['email' => $result['email']]));
    }

    /** Open the cancel dialog for one trip, with what it would cost shown. */
    public function openCancel(int $legId): void
    {
        $this->guardAccess(Permission::Write);

        $leg = LimoLeg::query()->with('legable')->find($legId);
        if ($leg === null || $leg->status === LimoLeg::STATUS_CANCELLED) {
            return;
        }

        $this->cancellingId = $legId;
        $this->cancelReason = '';
        // Default to money back; the office can switch to credit when a full
        // refund is due.
        $this->cancelAsCoupon = false;
        $this->resetErrorBag();
    }

    public function closeCancel(): void
    {
        $this->cancellingId = null;
        $this->cancelReason = '';
        $this->cancelAsCoupon = false;
    }

    /** Cancel the trip and settle what the customer gets back. */
    public function confirmCancel(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->cancellingId === null) {
            return;
        }

        $leg = LimoLeg::query()->with('legable')->find($this->cancellingId);
        if ($leg === null) {
            $this->closeCancel();

            return;
        }

        $result = app(TripCancellation::class)->cancel($leg, $this->cancelReason, $this->cancelAsCoupon);

        $this->closeCancel();

        // Say what the customer actually gets, not just "cancelled" — the whole
        // point of the rule is that the three outcomes differ.
        $coupon = $result['coupon'];

        session()->flash('booking_status', match ($result['outcome']) {
            TripCancellation::OUTCOME_COUPON => __('Trip cancelled. Coupon :code issued for :amount BD.', [
                'code' => $coupon !== null ? $coupon->code : '',
                'amount' => number_format($result['amount'], 3),
            ]),
            TripCancellation::OUTCOME_REFUNDED => __('Trip cancelled. Full refund of :amount BD is due.', [
                'amount' => number_format($result['amount'], 3),
            ]),
            default => __('Trip cancelled.'),
        });
    }

    /** Mark a running trip finished. */
    public function completeLeg(int $legId): void
    {
        $this->advanceLeg($legId, LimoLeg::STATUS_COMPLETED);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        // Typing while on a deep page would otherwise leave the user on a page
        // number the narrowed result set no longer has.
        $this->resetPage();
    }

    public function render(): View
    {
        // The list is of LEGS, not bookings. Each leg is dispatched separately —
        // its own reference, car and status — so the office works one row per
        // leg. The booking is still the money: payment hangs off the parent and
        // every one of its legs shows it.
        //
        // Rows come from LimoQueueRows, the same source the exports read, so a
        // printed sheet can never disagree with the screen.
        $rows = app(LimoQueueRows::class);
        $legs = $rows->paginate($this->tab, $this->from, $this->to, $this->search);

        $counts = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('limousine::bookings', [
            'legs' => $legs,
            // Flattened through the shared builder so the table prints exactly
            // what the exports do, keyed by leg id.
            'rows' => collect($legs->items())->mapWithKeys(
                fn (LimoLeg $l): array => [$l->id => $rows->row($l)]
            )->all(),
            'headings' => $rows->headings(),
            // The trip as a WhatsApp message, built server-side so the text is
            // the same wherever it is copied from. Keyed by leg id.
            'whatsapp' => collect($legs->items())->mapWithKeys(
                fn (LimoLeg $l): array => [$l->id => $rows->whatsappText($l)]
            )->all(),
            'exportQuery' => http_build_query(['tab' => $this->tab, 'from' => $this->from, 'to' => $this->to, 'search' => $this->search]),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'carOptions' => $this->assigningId !== null ? $this->carOptions() : [],
            'driverOptions' => $this->assigningId !== null ? $this->driverOptions() : [],
            'assigningLeg' => $this->assigningId !== null
                ? LimoLeg::query()->with('legable.customer:id,name')->find($this->assigningId)
                : null,
            'canAssign' => $this->mayAccess(Permission::Write),
            // Who signs vs who is merely told: an individual travelled and can
            // attest to the trip; a company booked it for a guest and cannot.
            // Keyed by leg id so the row renders the right action without
            // re-querying per row.
            'signable' => collect($legs->items())->mapWithKeys(
                fn (LimoLeg $l): array => [$l->id => app(ServiceOrderSender::class)->isSignable($l)]
            )->all(),
            'editing' => $this->editingId !== null
                ? LimoBooking::query()->with('customer:id,name,type')->find($this->editingId)
                : null,
            // The dispatched crew for the clicked trip — shown read-only in the
            // dialog, since car and driver are assigned per leg from the queue.
            'editingLeg' => $this->editingLegId !== null
                ? LimoLeg::query()->find($this->editingLegId)
                : null,
            'cancellingLeg' => $this->cancellingId !== null
                ? LimoLeg::query()->with('legable')->find($this->cancellingId)
                : null,
            // What cancelling would mean, so the office sees the consequence
            // before committing rather than after.
            'cancelPreview' => $this->cancellingId !== null
                ? app(TripCancellation::class)->preview(
                    LimoLeg::query()->with('legable')->findOrFail($this->cancellingId)
                )
                : null,
            'bookingTypes' => LimoBooking::bookingTypeOptions(),
            'rateTypes' => LimoBooking::rateTypeOptions(),
        ]);
    }
}
