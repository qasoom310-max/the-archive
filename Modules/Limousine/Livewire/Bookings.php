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
        $query = LimoBooking::query()
            // `legs` is eager-loaded because every row now prints its assigned
            // car — reading it lazily would be one query per row.
            ->with(['customer:id,name', 'pickupLocation:id,name', 'dropoffLocation:id,name', 'legs'])
            ->orderByDesc('pickup_at');

        if (in_array($this->tab, [
            LimoBooking::STATUS_QUEUE,
            LimoBooking::STATUS_CONFIRMED,
            LimoBooking::STATUS_ACTIVE,
            LimoBooking::STATUS_COMPLETED,
            LimoBooking::STATUS_CANCELLED,
        ], true)) {
            $query->where('status', $this->tab);
        }

        if ($this->from !== '') {
            $query->whereDate('pickup_at', '>=', $this->from);
        }
        if ($this->to !== '') {
            $query->whereDate('pickup_at', '<=', $this->to);
        }

        $counts = LimoBooking::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('limousine::bookings', [
            'bookings' => $query->paginate(20),
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
