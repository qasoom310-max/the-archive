<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\RentalWebBooking;
use Modules\Limousine\Services\WebBookingToLimousine;
use Modules\Rental\Services\WebBookingConverter;

/**
 * Bookings taken on the website, waiting to be turned into rental orders.
 *
 * Two tabs, Pending and Complete, as the office already knows them. A booking
 * is read in full — including the raw request, because the website sends more
 * than this screen has columns for — and then either turned into an order in
 * one press or marked complete by hand.
 */
#[Layout('components.layouts.app')]
#[Title('Web bookings')]
final class WebBookings extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    /** pending | complete */
    #[Url]
    public string $tab = RentalWebBooking::STATUS_PENDING;

    #[Url(except: '')]
    public string $search = '';

    /** '' (both) | rental | limousine */
    #[Url(except: '')]
    public string $service = '';
    /** The booking being read. Locked: it identifies a record. */
    #[Locked]
    public ?int $viewingId = null;

    public string $flash = '';

    public string $error = '';

    protected function accessModelKey(): string
    {
        return 'rental.web_booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedService(): void
    {
        if (! in_array($this->service, ['', RentalWebBooking::SERVICE_RENTAL, RentalWebBooking::SERVICE_LIMOUSINE], true)) {
            $this->service = '';
        }

        $this->resetPage();
    }
    public function view(int $id): void
    {
        $this->guardAccess(Permission::Read);

        $this->viewingId = $id;
        $this->flash = '';
        $this->error = '';
    }

    public function closeView(): void
    {
        $this->viewingId = null;
    }

    /**
     * Turn the booking into a rental order and open it.
     *
     * What the website knows is filled in; what it does not know is left for
     * the desk. It never knows which car — the site sells one generic booking
     * product, not a vehicle from the fleet — so that is always a decision
     * made here.
     */
    public function createOrder(): void
    {
        $this->guardAccess(Permission::Write);

        $booking = $this->booking();
        if ($booking === null) {
            return;
        }

        if ($booking->isConverted() || $booking->isLimousine()) {
            return;
        }

        $order = app(WebBookingConverter::class)->convert($booking);
        $this->redirect(url('/app/rental/order/' . $order->id), navigate: true);
    }

    /**
     * Turn a limousine trip into a limousine booking, with the trip in the
     * queue, and open it so the desk can check it before dispatch.
     */
    public function createLimoBooking(): void
    {
        $this->guardAccess(Permission::Write);
        abort_unless($this->mayCreateLimo(), 403);

        $booking = $this->booking();
        if ($booking === null || $booking->isConverted() || ! $booking->isLimousine()) {
            return;
        }

        $user = Auth::user();
        $limo = app(WebBookingToLimousine::class)->convert($booking, $user instanceof User ? $user : null);

        $this->redirect(url('/app/limousine/booking/' . $limo->id), navigate: true);
    }

    /**
     * The website's sorting was wrong: this is a car hire, not a trip (or the
     * other way round). Remembered, so the next delivery does not undo it.
     */
    public function switchService(): void
    {
        $this->guardAccess(Permission::Write);

        $booking = $this->booking();
        if ($booking === null || $booking->isConverted()) {
            return;
        }

        $booking->service = $booking->isLimousine()
            ? RentalWebBooking::SERVICE_RENTAL
            : RentalWebBooking::SERVICE_LIMOUSINE;
        $booking->service_manual = true;
        $booking->save();
    }

    /**
     * Limousine bookings can be made from here only where the Limousine app is
     * installed and the user may create a booking in it.
     */
    private function mayCreateLimo(): bool
    {
        if (! Schema::hasTable('limo_bookings')) {
            return false;
        }

        $user = Auth::user();

        return $user instanceof User
            && app(AccessControl::class)->allows($user, 'limousine.booking', Permission::Create);
    }

    /** Dealt with elsewhere, or not a real booking: take it off the queue. */    public function markComplete(): void
    {
        $this->guardAccess(Permission::Write);

        $booking = $this->booking();
        if ($booking === null) {
            return;
        }

        $booking->status = RentalWebBooking::STATUS_COMPLETE;
        $booking->completed_at = now();
        $booking->save();

        $this->viewingId = null;
        $this->flash = __('Booking marked complete.');
    }

    /** Back on the queue — marked complete by mistake, or reopened. */
    public function markPending(): void
    {
        $this->guardAccess(Permission::Write);

        $booking = $this->booking();
        if ($booking === null) {
            return;
        }

        $booking->status = RentalWebBooking::STATUS_PENDING;
        $booking->completed_at = null;
        $booking->save();

        $this->viewingId = null;
        $this->flash = __('Booking put back on the pending list.');
    }

    private function booking(): ?RentalWebBooking
    {
        return $this->viewingId === null
            ? null
            : RentalWebBooking::query()->find($this->viewingId);
    }

    /** @return Builder<RentalWebBooking> */
    private function query(): Builder
    {
        $query = RentalWebBooking::query()->latest('id');

        if ($this->tab !== 'all') {
            $query->where('status', $this->tab);
        }

        if (in_array($this->service, [RentalWebBooking::SERVICE_RENTAL, RentalWebBooking::SERVICE_LIMOUSINE], true)) {
            $query->where('service', $this->service);
        }
        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function (Builder $q) use ($term): void {
                foreach (['source_reference', 'first_name', 'last_name', 'phone', 'email', 'product'] as $column) {
                    $q->orWhere($column, 'like', '%' . $term . '%');
                }
            });
        }

        return $query;
    }

    public function render(): View
    {
        return view('rental::web-bookings', [
            'bookings' => $this->query()->paginate(20),
            'pendingCount' => RentalWebBooking::query()->where('status', RentalWebBooking::STATUS_PENDING)
                ->when($this->service !== '', fn (Builder $q) => $q->where('service', $this->service))->count(),
            'pendingByService' => RentalWebBooking::query()->where('status', RentalWebBooking::STATUS_PENDING)
                ->selectRaw('service, COUNT(*) as n')->groupBy('service')->pluck('n', 'service')->map(static fn ($n): int => (int) $n)->all(),
            'mayCreateLimo' => $this->mayCreateLimo(),            'viewing' => $this->booking(),
            'mayAct' => $this->mayAccess(Permission::Write),
        ]);
    }
}
