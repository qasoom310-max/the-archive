<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Erp\Views\ValueFormat;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\ServiceOrderPortalClient;
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

    /**
     * queue | confirmed | active | completed | unpaid | cancelled
     *
     * Opens on the QUEUE — trips nobody has picked up yet are the ones that
     * need a person. There is deliberately no "all": see the tab row in the
     * view. An old link carrying one is normalised in mount() rather than
     * quietly rendering an unfiltered list with no tab lit up.
     */
    #[Url]
    public string $tab = self::DEFAULT_TAB;

    /** Where the screen opens, and where an unknown tab falls back to. */
    private const DEFAULT_TAB = 'queue';

    /**
     * Accepted in the URL. `all` is deliberately here but NOT rendered as a
     * tab: the schedule cards on the app home open a whole day across every
     * status, and their promise is that the number on the card equals the rows
     * on the page. Dropping the value as well as the button would have quietly
     * broken that. So it is reachable by link, just not somewhere to click.
     *
     * @var list<string>
     */
    private const VALID_TABS = ['all', 'queue', 'confirmed', 'active', 'completed', 'unpaid', 'cancelled'];

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Free-text search across reference, customer, passenger and route. */
    #[Url(except: '')]
    public string $search = '';

    /**
     * Which column the list is ordered by, and which way.
     *
     * In the URL beside the filters, because a sorted queue is a view of the
     * work — "biggest balance first", "oldest first" — and a view is worth
     * keeping when the page is reloaded, bookmarked or sent to someone else.
     */
    #[Url(except: 'from_date')]
    public string $sort = 'from_date';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    /**
     * How many trips on a page.
     *
     * Twenty-five is enough to glance at, which is what the queue is for most
     * of the day. Three hundred is for the times somebody is going through the
     * month properly — and being told they can only have twenty-five is its
     * own annoyance.
     */
    #[Url(except: self::PER_PAGE_DEFAULT)]
    public int $perPage = self::PER_PAGE_DEFAULT;

    public const PER_PAGE_DEFAULT = 25;

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [25, 50, 100, 300];

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

    /**
     * The dialog was opened by pressing Start trip, not by the Assign buttons.
     *
     * Only changes what the dialog SAYS — that naming both sends the trip out —
     * so the office knows the press they made is still going to happen.
     */
    #[Locked]
    public bool $startAfterAssign = false;

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

    /**
     * BOOKING shown in the quick-preview dialog, or null when it is shut.
     *
     * A glance at the whole job — customer, every leg, money — without
     * leaving the list or committing to the full booking page. Read-only:
     * opened on Read alone, so anyone who may see the queue may peek at a
     * row's details.
     */
    #[Locked]
    public ?int $previewingId = null;

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

    /**
     * The booking a payment is being taken against.
     *
     * A booking, not a leg: the customer settles the JOB. Three trips on one
     * booking are one bill, one amount received and one balance — which is why
     * the queue prints the same Received and Balance on each of their rows
     * rather than a third of it on each.
     */
    #[Locked]
    public ?int $collectingId = null;

    public string $collectAmount = '';

    public string $collectMethod = 'cash';

    public string $collectNote = '';

    /**
     * The day the money changed hands — not always the day it is typed in.
     * An office writing up Saturday's cash on Monday would otherwise date
     * every receipt Monday, and the customer's own record would disagree
     * with ours. Opens on today, which is the usual answer.
     */
    public string $collectDate = '';

    /**
     * The clicked trip's own values — route, time, pricing.
     *
     * Separate from `$edit` because they are a different scope: `$edit` is the
     * booking, shared by every leg, while this is the one trip whose row was
     * clicked. Keeping them apart is what lets the dialog say which is which.
     *
     * @var array<string, string>
     */
    public array $editLeg = [];

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        // A bookmark or old link may still carry ?tab=all (or anything else).
        // Without this it would render every trip with no tab highlighted,
        // which reads as a broken page rather than a deliberate view.
        if (! in_array($this->tab, self::VALID_TABS, true)) {
            $this->tab = self::DEFAULT_TAB;
        }
    }

    /**
     * Open the quick-preview dialog for the booking behind a trip row.
     *
     * Read-only, so it needs nothing more than the Read the list itself is
     * already gated on — the office glances at a job's full shape (route,
     * crew, money) without the weight of the edit dialog or a page change.
     */
    public function openPreview(int $bookingId): void
    {
        $this->guardAccess(Permission::Read);

        $this->previewingId = $bookingId;
    }

    public function closePreview(): void
    {
        $this->previewingId = null;
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

        // A finished or cancelled trip is a record of what happened, not a draft.
        // The buttons are hidden for those rows; this refuses the action itself,
        // since a hidden button is not a rule.
        if ($this->isLocked($leg)) {
            session()->flash('toast', __('That trip is closed — its car and driver can no longer be changed.'));

            return;
        }

        $this->assigningId = $leg->id;
        $this->assignCar = $leg->car_id !== null ? (string) $leg->car_id : '';
        $this->assignDriver = $leg->driver_id !== null ? (string) $leg->driver_id : '';
    }

    /**
     * Whether a trip is closed to changes: completed or cancelled.
     *
     * Both are history — one was driven, the other called off — and editing
     * either would make the record disagree with what actually happened.
     */
    private function isLocked(LimoLeg $leg): bool
    {
        return in_array($leg->status, [LimoLeg::STATUS_COMPLETED, LimoLeg::STATUS_CANCELLED], true);
    }

    public function closeAssign(): void
    {
        $this->assigningId = null;
        $this->assignCar = '';
        $this->assignDriver = '';
        $this->startAfterAssign = false;
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

        // Starting a trip IS dispatching it, and nothing goes out without a car
        // and a driver. Rather than refuse with a note telling the office to go
        // and do that somewhere else, open the dialog that does it — the same
        // one the Assign car / Assign driver buttons open. saveAssign() starts
        // the trip the moment both are named, so this button still ends where
        // it said it would, in one place.
        if ($to === LimoLeg::STATUS_ACTIVE && ($leg->car_id === null || $leg->driver_id === null)) {
            $this->openAssign($legId);

            // openAssign refuses a closed trip, so only follow through if it
            // actually opened.
            if ($this->assigningId !== null) {
                $this->startAfterAssign = true;
            }

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

        // The licence is checked HERE as well as hidden from the picker: a
        // dropped option is a courtesy, not a rule, and a trip must not go out
        // behind an expired licence because somebody kept a stale page open.
        if ($driver !== null && ! $driver->canBeDispatched()) {
            $leg->save();
            $this->addError('assignDriver', (string) $driver->dispatchBlockReason());

            return;
        }

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

        // Either the office named a driver (which is itself the act of
        // dispatching) or they pressed Start trip and were brought here to
        // finish it. Both mean the same thing: this trip is going out.
        $goingOut = $driver !== null || $this->startAfterAssign;

        if ($goingOut && $dispatchable) {
            // A trip needs a car and someone to drive it — both, or it does not
            // leave. Whichever is missing is said HERE, with the dialog still
            // open on the two pickers, rather than closing with a note that
            // sends the office off to another button.
            if ($car === null) {
                $leg->save();
                $this->addError('assignCar', __('Pick the car — a trip cannot go out without one.'));

                return;
            }

            if ($driver === null) {
                $leg->save();
                $this->addError('assignDriver', __('Pick the driver — a trip cannot go out without one.'));

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

        $drivers = $query->orderBy('name')->get();

        // A driver whose licence has run out is not offered. Kept in the list
        // only if they are already on this trip, so the dialog can say why the
        // save is refused instead of the name silently vanishing.
        return $drivers
            ->filter(fn (LimoDriver $d): bool => $d->canBeDispatched() || (string) $d->id === $this->assignDriver)
            ->map(fn (LimoDriver $d): array => [
                'value' => $d->id,
                'label' => $d->licenceExpired()
                    ? $d->displayName() . ' · ' . __('licence expired')
                    : $d->displayName(),
            ])
            ->values()
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

        // Opened from a finished trip's row → refuse. The details are shared by
        // the booking, but a closed trip is not a place to edit them from.
        $leg = $legId !== null ? LimoLeg::query()->find($legId) : null;

        if ($leg !== null && $this->isLocked($leg)) {
            session()->flash('toast', __('That trip is closed — open the booking to review it.'));

            return;
        }

        $this->editingId = $bookingId;
        // Which leg's row was clicked, so the dialog can show the car and driver
        // actually dispatched for THAT trip.
        $this->editingLegId = $legId;
        $this->resetErrorBag();

        // The clicked trip's OWN details. A booking can hold several legs and
        // the office edits the one whose row they clicked — opening the full
        // booking to change one leg's pick-up means scrolling past the other
        // trips and risking an edit to the wrong one.
        $this->editLeg = $leg !== null ? [
            'service_type' => (string) ($leg->service_type ?? LimoLeg::TYPE_TRANSFER),
            'from_location' => (string) ($leg->from_location ?? ''),
            'from_location_url' => (string) ($leg->from_location_url ?? ''),
            'to_location' => (string) ($leg->to_location ?? ''),
            'to_location_url' => (string) ($leg->to_location_url ?? ''),
            'start_at' => $leg->start_at?->format('Y-m-d\TH:i') ?? '',
            'hours' => $leg->hours !== null ? (string) $leg->hours : '',
            'days' => (string) ($leg->days ?? 1),
            'rate' => (string) ($leg->rate ?? 0),
            'rate_basis' => (string) ($leg->rate_basis ?? LimoLeg::BASIS_TRIP),
            'discount' => (string) ($leg->discount ?? 0),
            'vat' => (string) ($leg->vat ?? 0),
        ] : [];

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
        $this->editLeg = [];
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

        $this->saveEditedLeg($booking);

        // Confirm with the REFERENCE, worded exactly as the booking form words
        // it: this is the line the office sends the customer, and it must not
        // depend on which screen the change was made from.
        $message = $this->updatedMessage($booking);

        $this->cancelEdit();
        session()->flash('booking_status', $message);
        session()->flash('booking_status_id', $booking->id);
    }

    /**
     * What to tell the office — and through them the customer — after an edit.
     *
     * Names the trip that was edited, since that is the number the customer
     * quotes. A booking-wide edit with no single trip behind it names them all,
     * one per line, rather than picking one arbitrarily.
     */
    private function updatedMessage(LimoBooking $booking): string
    {
        $edited = $this->editingLegId !== null
            ? LimoLeg::query()->find($this->editingLegId)
            : null;

        $refs = $edited !== null
            ? [(string) ($edited->reference ?? '')]
            : $booking->legs()->orderBy('sequence')->pluck('reference')
                ->filter()->map(static fn ($r): string => (string) $r)->all();

        $refs = array_values(array_filter($refs, static fn (string $r): bool => $r !== ''));

        if ($refs === []) {
            return (string) __('Booking has been updated successfully.');
        }

        return implode("
", array_map(
            static fn (string $ref): string => (string) __('Booking has been updated successfully. Ref. # :ref', ['ref' => $ref]),
            $refs,
        ));
    }

    /**
     * Open the payment dialog for the booking behind a trip row.
     *
     * Reached from any leg of the booking, because there is only one bill: the
     * dialog opens on the whole job and lists its trips, so it is plain that
     * paying here settles all of them and not just the row that was clicked.
     */
    public function openCollect(int $legId): void
    {
        $this->guardAccess(Permission::Write);

        $leg = LimoLeg::query()->find($legId);
        if ($leg === null) {
            return;
        }

        $booking = LimoBooking::query()->find($leg->legable_id);
        if ($booking === null) {
            return;
        }

        $this->resetErrorBag();
        $this->collectingId = (int) $booking->id;
        // Offered as the whole balance, since settling up is the usual case —
        // typed over when the customer pays part of it.
        $this->collectAmount = (string) $booking->balanceDue();
        $this->collectMethod = (string) ($booking->payment_method ?? 'cash');
        $this->collectDate = Carbon::today()->toDateString();
        $this->collectNote = '';
    }

    public function closeCollect(): void
    {
        $this->collectingId = null;
        $this->collectAmount = '';
        $this->collectDate = '';
        $this->collectNote = '';
        $this->resetErrorBag();
    }

    /**
     * Take a payment against the booking.
     *
     * Adds to what has already been received rather than replacing it, so the
     * 50 taken when the booking was made and the 98 taken later read as one
     * running total instead of the second overwriting the first. "Paid" is then
     * settled the same way every other payment settles it — from the money
     * against the fare — so there is no second route to becoming paid that can
     * disagree with the first.
     */
    public function saveCollect(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->collectingId === null) {
            return;
        }

        $booking = LimoBooking::query()->find($this->collectingId);
        if ($booking === null) {
            $this->closeCollect();

            return;
        }

        $this->validate([
            'collectAmount' => ['required', 'numeric', 'min:0.001', 'max:' . max(0.001, $booking->balanceDue())],
            'collectMethod' => ['required', 'string'],
            // Money cannot have arrived tomorrow. Back-dating is the whole
            // point of the field, so only the future is refused.
            'collectDate' => ['required', 'date', 'before_or_equal:' . Carbon::today()->toDateString()],
            'collectNote' => ['nullable', 'string', 'max:255'],
        ], [
            'collectAmount.max' => __('That is more than the :amount still owed on this booking.', [
                'amount' => ValueFormat::money($booking->balanceDue()),
            ]),
            'collectDate.before_or_equal' => __('A payment cannot be dated in the future.'),
        ], [
            'collectAmount' => __('Amount'),
            'collectDate' => __('Date received'),
        ]);

        $taken = round((float) $this->collectAmount, 3);

        // Taking the money and issuing its receipt are one act, not two. A
        // receipt that depends on somebody remembering is one the customer
        // sometimes never gets.
        $receipt = app(BookingPayments::class)->receive(
            $booking,
            $taken,
            $this->collectMethod,
            trim($this->collectNote) !== '' ? trim($this->collectNote) : null,
            null,
            Carbon::parse($this->collectDate),
        );

        $fresh = $booking->fresh();
        $remaining = $fresh?->balanceDue() ?? 0.0;

        // Name the receipt — it exists now, and the customer will ask for it.
        $issued = $receipt !== null && (string) $receipt->reference !== ''
            ? ' ' . __('Receipt :reference issued.', ['reference' => $receipt->reference])
            : '';

        $this->closeCollect();
        session()->flash('booking_status', ($remaining > 0
            ? __(':amount received. :balance still owed on this booking.', [
                'amount' => ValueFormat::money($taken),
                'balance' => ValueFormat::money($remaining),
            ])
            : __(':amount received. This booking is settled in full.', [
                'amount' => ValueFormat::money($taken),
            ])) . $issued);
    }

    /**
     * What the trip being edited comes to, priced from the live inputs so the
     * dialog answers "what does this change cost?" before it is saved.
     */
    public function editLegTotal(): float
    {
        if ($this->editLeg === []) {
            return 0.0;
        }

        $number = fn (string $key): float => (float) (($this->editLeg[$key] ?? '') === '' ? '0' : $this->editLeg[$key]);
        $chauffeur = ($this->editLeg['service_type'] ?? '') === LimoLeg::TYPE_CHAUFFEUR;

        return LimoLeg::netFor(
            (string) ($this->editLeg['rate_basis'] ?? LimoLeg::BASIS_TRIP),
            $number('rate'),
            $chauffeur && ($this->editLeg['hours'] ?? '') !== '' ? $number('hours') : null,
            $chauffeur ? max(1, (int) (($this->editLeg['days'] ?? '') === '' ? '1' : $this->editLeg['days'])) : 1,
            $number('discount'),
            $number('vat'),
        );
    }

    /**
     * Write the clicked trip's own details back — just that leg.
     *
     * The other legs of the booking are left exactly as they were: the office
     * clicked one row, and one row is what changes. Re-prices the leg from its
     * rate and basis the same way the full form does, then re-totals the
     * booking, since the fare is the sum of its legs and would otherwise still
     * quote the old price.
     */
    private function saveEditedLeg(LimoBooking $booking): void
    {
        if ($this->editingLegId === null || $this->editLeg === []) {
            return;
        }

        $leg = LimoLeg::query()->find($this->editingLegId);

        // Re-checked rather than trusted from openEdit: a trip can be completed
        // by someone else while this dialog sits open.
        if ($leg === null || $this->isLocked($leg) || (int) $leg->legable_id !== (int) $booking->id) {
            return;
        }

        $chauffeur = ($this->editLeg['service_type'] ?? '') === LimoLeg::TYPE_CHAUFFEUR;

        $this->validate([
            'editLeg.service_type' => ['required', 'in:transfer,chauffeur'],
            'editLeg.from_location' => ['required', 'string', 'max:255'],
            'editLeg.from_location_url' => ['nullable', 'url', 'max:500'],
            'editLeg.to_location' => [$chauffeur ? 'nullable' : 'required', 'string', 'max:255'],
            'editLeg.to_location_url' => ['nullable', 'url', 'max:500'],
            'editLeg.start_at' => ['required', 'date'],
            'editLeg.hours' => [$chauffeur ? 'required' : 'nullable', 'numeric', 'min:0.5'],
            'editLeg.days' => [$chauffeur ? 'required' : 'nullable', 'integer', 'min:1'],
            'editLeg.rate' => ['required', 'numeric', 'min:0'],
            'editLeg.rate_basis' => ['required', 'in:trip,hour,day'],
            'editLeg.discount' => ['nullable', 'numeric', 'min:0'],
            'editLeg.vat' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'editLeg.from_location' => __('Pickup'),
            'editLeg.to_location' => __('Drop off'),
            'editLeg.start_at' => __('Date & time'),
            'editLeg.hours' => __('Hours per day'),
            'editLeg.days' => __('Number of days'),
            'editLeg.rate' => __('Rate'),
        ]);

        $number = fn (string $key): float => (float) (($this->editLeg[$key] ?? '') === '' ? '0' : $this->editLeg[$key]);
        $blank = fn (string $key): ?string => trim((string) ($this->editLeg[$key] ?? '')) !== ''
            ? trim((string) $this->editLeg[$key])
            : null;

        $basis = (string) $this->editLeg['rate_basis'];
        $rate = $number('rate');
        $discount = $number('discount');
        $vat = $number('vat');
        $hours = $chauffeur && ($this->editLeg['hours'] ?? '') !== '' ? $number('hours') : null;
        $days = $chauffeur ? max(1, (int) (($this->editLeg['days'] ?? '') === '' ? '1' : $this->editLeg['days'])) : 1;

        $leg->fill([
            'service_type' => (string) $this->editLeg['service_type'],
            'from_location' => $blank('from_location'),
            'from_location_url' => $blank('from_location_url'),
            // A chauffeur job is a car at disposal, not a route — it has no
            // drop-off, so switching to one clears any the leg used to carry.
            'to_location' => $chauffeur ? null : $blank('to_location'),
            'to_location_url' => $chauffeur ? null : $blank('to_location_url'),
            'start_at' => Carbon::parse((string) $this->editLeg['start_at']),
            'hours' => $hours,
            'days' => $days,
            'rate' => $rate,
            'rate_basis' => $basis,
            'discount' => $discount,
            'vat' => $vat,
            'line_total' => LimoLeg::grossFor($basis, $rate, $hours, $days),
            'net_amount' => LimoLeg::netFor($basis, $rate, $hours, $days, $discount, $vat),
        ]);
        $leg->save();

        // The fare is the sum of the legs, so re-pricing one re-prices the job —
        // and a job that just got dearer than what was taken is not paid any
        // more, whatever its flag said a moment ago.
        $booking->recalcTotal();
        $booking->save();
        $booking->syncPaymentFromAdvance();
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

    /* ── Online payment link (Wanaan WordPress portal → Tap) ─────────────── */

    /** The leg a payment link is being raised for, or null when shut. */
    #[Locked]
    public ?int $paymentLegId = null;

    /** The partition the agent chooses to charge on this link. */
    public string $paymentAmount = '';

    /** The generated public link, shown to the agent to send to the customer. */
    public string $paymentLinkUrl = '';

    /** Whether the portal is switched on — drives the button's visibility. */
    public function portalEnabled(): bool
    {
        return LimoPortalConfiguration::current()->isConfigured();
    }

    /**
     * Open the "create payment link" dialog for a trip, pre-filled with the
     * booking's remaining balance. The agent can lower it to take a deposit or
     * one partition — the customer pays that amount, and the booking only reads
     * "paid" once the whole balance is cleared.
     */
    public function openPaymentLink(int $legId): void
    {
        $this->guardAccess(Permission::Read);

        $leg = LimoLeg::query()->with('legable')->find($legId);
        $booking = $leg?->legable instanceof LimoBooking ? $leg->legable : null;
        if ($booking === null) {
            return;
        }

        $this->resetValidation();
        $this->paymentLegId = $legId;
        $this->paymentLinkUrl = '';
        $this->paymentAmount = number_format(max(0.0, $booking->balanceDue()), 3, '.', '');
    }

    public function closePaymentLink(): void
    {
        $this->paymentLegId = null;
        $this->paymentAmount = '';
        $this->paymentLinkUrl = '';
    }

    /**
     * Raise the link and push it to the portal. Writing money into the world is
     * a Write; the portal call is wrapped so a WordPress outage surfaces as a
     * message, never a 500, and the half-made link is dropped so a broken URL is
     * never handed to a customer.
     */
    public function createPaymentLink(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->paymentLegId === null) {
            return;
        }

        $leg = LimoLeg::query()->with('legable')->find($this->paymentLegId);
        $booking = $leg?->legable instanceof LimoBooking ? $leg->legable : null;
        if ($leg === null || $booking === null) {
            $this->closePaymentLink();

            return;
        }

        $balance = max(0.001, $booking->balanceDue());
        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.001', 'max:' . $balance],
        ], [
            'paymentAmount.max' => __('That is more than the :amount still owed on this booking.', [
                'amount' => ValueFormat::money($booking->balanceDue()),
            ]),
        ], [
            'paymentAmount' => __('Amount'),
        ]);

        $link = LimoPaymentLink::query()->create([
            'leg_id' => $leg->id,
            'booking_id' => $booking->id,
            'amount' => round((float) $this->paymentAmount, 3),
            'currency' => 'BHD',
            'created_by_user_id' => Auth::id(),
        ]);

        if (! app(ServiceOrderPortalClient::class)->push($link)) {
            $link->delete();
            session()->flash('booking_status', __('Could not create the payment link. Check the portal settings and try again.'));
            $this->closePaymentLink();

            return;
        }

        $this->paymentLinkUrl = (string) $link->refresh()->url;
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

    public function setPerPage(int $size): void
    {
        if (! in_array($size, self::PER_PAGE_OPTIONS, true)) {
            return;
        }

        $this->perPage = $size;
        // The row that was on page 3 of ten is on page 1 of five hundred.
        $this->resetPage();
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

    /**
     * Sort by a column, or turn it around if it is already the one sorting.
     *
     * First click on a column gives the end of it people actually want: newest
     * date, biggest amount, but names and places A→Z. Saves the second click
     * that "sort by date" almost always means.
     */
    public function sortBy(string $column): void
    {
        if (! in_array($column, LimoQueueRows::SORTS, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = in_array($column, [
                'from_date', 'to_date', 'booked_time', 'amount', 'received', 'balance',
            ], true) ? 'desc' : 'asc';
        }

        // The row that was on page 3 under the old order is somewhere else now.
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
        $perPage = in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : self::PER_PAGE_DEFAULT;
        $legs = $rows->paginate($this->tab, $this->from, $this->to, $this->search, $perPage, $this->sort, $this->dir);

        $counts = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // Computed once so the preview panel and its per-leg WhatsApp copy
        // button read the same booking — its legs are not necessarily on the
        // current page/tab, so they can't reuse $legs/$whatsapp above.
        $previewing = $this->previewingId !== null
            ? LimoBooking::query()->with(['customer:id,name,type,phone,email', 'legs' => fn ($q) => $q->orderBy('sequence')])->find($this->previewingId)
            : null;

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
            // Sort rides along with the filters: an export is of what the user
            // is looking at, in the order they put it in.
            'exportQuery' => http_build_query([
                'tab' => $this->tab, 'from' => $this->from, 'to' => $this->to,
                'search' => $this->search, 'sort' => $this->sort, 'dir' => $this->dir,
            ]),
            'counts' => $counts,
            'unpaidCount' => $rows->unpaidCount(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'carOptions' => $this->assigningId !== null ? $this->carOptions() : [],
            'driverOptions' => $this->assigningId !== null ? $this->driverOptions() : [],
            'assigningLeg' => $this->assigningId !== null
                ? LimoLeg::query()->with('legable.customer:id,name')->find($this->assigningId)
                : null,
            'canAssign' => $this->mayAccess(Permission::Write),
            // Importing trips is a manager action, the same gate every other
            // CSV import in the app uses.
            'canManage' => Auth::user()?->canApproveMaintenance() ?? false,
            // For the per-trip section of the quick-edit dialog.
            'serviceTypes' => LimoLeg::serviceTypeOptions(),
            'rateBasisOptions' => LimoLeg::rateBasisOptions(),
            // The booking a payment is being taken against, with its trips, so
            // the dialog can show what the one balance is actually made of.
            'collecting' => $this->collectingId !== null
                ? LimoBooking::query()->with(['customer:id,name', 'legs'])->find($this->collectingId)
                : null,
            'paymentMethods' => LimoBooking::paymentMethodOptions(),
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
            // Every leg, in order, so the preview shows the whole job at a
            // glance rather than just the row that opened it.
            'previewing' => $previewing,
            // The trip as a WhatsApp message for each of the previewed
            // booking's legs — the copy button the reference used to be
            // moved here, per trip, once the reference itself started
            // opening the preview instead.
            'previewWhatsapp' => $previewing !== null
                ? collect($previewing->legs)->mapWithKeys(function (LimoLeg $l) use ($rows, $previewing): array {
                    // Already have the parent in hand — skip the lazy re-query
                    // whatsappText()'s bookingOf() would otherwise do per leg.
                    $l->setRelation('legable', $previewing);

                    return [$l->id => $rows->whatsappText($l)];
                })->all()
                : [],
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
