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

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    /** Booking whose cars are being assigned, or null when the modal is shut. */
    #[Locked]
    public ?int $assigningId = null;

    /** Chosen car per leg, keyed by leg id: `<legId> => <carId as string>`. */
    /** @var array<int, string> */
    public array $assignCars = [];

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /**
     * Open the car assignment for a booking waiting in the queue.
     *
     * Dispatch is a separate job from taking the booking: the trip is written
     * up first and a vehicle is put against it later, from this list. Keyed by
     * leg id rather than position so a re-ordered or deleted leg can never send
     * a car to the wrong one.
     */
    public function openAssign(int $id): void
    {
        $this->guardAccess(Permission::Write);

        $booking = LimoBooking::query()->with('legs')->find($id);
        if ($booking === null) {
            return;
        }

        $this->assigningId = $booking->id;
        $this->assignCars = $booking->legs
            ->mapWithKeys(fn (LimoLeg $l): array => [$l->id => $l->car_id !== null ? (string) $l->car_id : ''])
            ->all();
    }

    public function closeAssign(): void
    {
        $this->assigningId = null;
        $this->assignCars = [];
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
     * Save the chosen cars onto the booking's legs.
     *
     * Writes `car_id` plus the label snapshot the rest of the app reads, so a
     * leg still names its vehicle if the fleet entry is later renamed. Legs are
     * matched by id and anything not belonging to this booking is ignored — the
     * keys arrive from the browser and cannot be trusted.
     */
    public function saveAssign(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->assigningId === null) {
            return;
        }

        $booking = LimoBooking::query()->with('legs')->find($this->assigningId);
        if ($booking === null) {
            $this->closeAssign();

            return;
        }

        $carIds = collect($this->assignCars)->filter()->map(fn ($x): int => (int) $x)->all();
        $labels = Vehicle::query()->whereIn('id', $carIds)->get()
            ->mapWithKeys(fn (Vehicle $v): array => [$v->id => $v->displayName()]);

        foreach ($booking->legs as $leg) {
            if (! array_key_exists($leg->id, $this->assignCars)) {
                continue;
            }

            $carId = $this->assignCars[$leg->id] !== '' ? (int) $this->assignCars[$leg->id] : null;
            if ($carId !== null && ! $labels->has($carId)) {
                continue; // unknown vehicle id — ignore rather than store a dangling ref
            }

            $leg->car_id = $carId;
            $leg->vehicle = $carId !== null ? $labels[$carId] : null;
            $leg->save();
        }

        $this->closeAssign();
        session()->flash('toast', __('Car assigned.'));
    }

    /**
     * Vehicles offerable for assignment: everything free in the Rent A Car
     * fleet, plus whatever is already on these legs so an existing choice does
     * not vanish from its own dropdown once the car is marked rented.
     *
     * @return list<array{value: int, label: string}>
     */
    public function carOptions(): array
    {
        $ids = Vehicle::query()->where('active', true)
            ->where('status', Vehicle::STATUS_AVAILABLE)->pluck('id')->all();
        $chosen = collect($this->assignCars)->filter()->map(fn ($x): int => (int) $x)->all();
        $ids = array_values(array_unique([...$ids, ...$chosen]));

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
        $legs = $rows->paginate($this->tab, $this->from, $this->to);

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
            'exportQuery' => http_build_query(['tab' => $this->tab, 'from' => $this->from, 'to' => $this->to]),
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'carOptions' => $this->assigningId !== null ? $this->carOptions() : [],
            'assigningBooking' => $this->assigningId !== null
                ? LimoBooking::query()->with('legs')->find($this->assigningId)
                : null,
            'canAssign' => $this->mayAccess(Permission::Write),
        ]);
    }
}
