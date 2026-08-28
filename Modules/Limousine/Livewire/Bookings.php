<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
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
use Modules\Rental\Models\Vehicle;

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
            'exportQuery' => http_build_query(['tab' => $this->tab, 'from' => $this->from, 'to' => $this->to, 'search' => $this->search]),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'carOptions' => $this->assigningId !== null ? $this->carOptions() : [],
            'driverOptions' => $this->assigningId !== null ? $this->driverOptions() : [],
            'assigningLeg' => $this->assigningId !== null
                ? LimoLeg::query()->with('legable.customer:id,name')->find($this->assigningId)
                : null,
            'canAssign' => $this->mayAccess(Permission::Write),
        ]);
    }
}
