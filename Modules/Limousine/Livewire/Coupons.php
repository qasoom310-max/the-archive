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
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Services\CouponSender;
use Throwable;

/**
 * Refund coupons: credit held for customers whose paid trips were cancelled too
 * late to refund, and how much of each is left.
 *
 * A coupon is spent in pieces, so what matters on this screen is the BALANCE,
 * not the face value — the office needs to answer "how much can this customer
 * still put towards a trip?" at a glance.
 */
#[Layout('components.layouts.app')]
#[Title('Refund coupons')]
final class Coupons extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    /** all | active | used | expired */
    #[Url]
    public string $tab = 'active';

    /** Code, customer or the cancelled trip's reference. */
    #[Url(except: '')]
    public string $search = '';

    /** The coupon being spent, while the office picks what on. */
    #[Locked]
    public ?int $usingId = null;

    /** The coupon being emailed to its customer. */
    #[Locked]
    public ?int $sendingId = null;

    /**
     * Where it goes.
     *
     * Pre-filled from the customer and then EDITABLE, because the address on
     * file is often not the one that should get this: a booking is placed by
     * whoever happened to call, and the credit belongs to whoever paid.
     * Offering it filled in and correctable beats typing it out every time.
     */
    public string $sendEmail = '';

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /**
     * Start a booking that this credit pays for.
     *
     * Two businesses share these customers, and the credit is the customer's
     * money — somebody owed for a limousine trip may well want a rental car
     * with it. So the office is asked WHICH rather than the coupon deciding on
     * their behalf, and the code travels to the form so nobody copies it across
     * by hand and mistypes it.
     */
    public function openUse(int $couponId): void
    {
        $this->guardAccess(Permission::Write);

        $coupon = LimoCoupon::query()->with('redemptions')->find($couponId);
        if ($coupon === null || ! $coupon->isUsable()) {
            return;
        }

        $this->usingId = (int) $coupon->id;
    }

    public function closeUse(): void
    {
        $this->usingId = null;
    }

    /** Off to the right new-booking form, carrying the code. */
    public function useFor(string $what): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->usingId === null) {
            return;
        }

        $coupon = LimoCoupon::query()->with('redemptions')->find($this->usingId);
        if ($coupon === null || ! $coupon->isUsable()) {
            $this->closeUse();

            return;
        }

        $path = match ($what) {
            'rental' => '/app/rental/order/new',
            default => '/app/limousine/booking/new',
        };

        $this->closeUse();

        // The customer rides along too: the credit is theirs, and a booking for
        // somebody else could not spend it anyway.
        $this->redirect(
            $path . '?' . http_build_query([
                'coupon' => $coupon->code,
                'customer' => $coupon->customer_id,
            ]),
            navigate: true,
        );
    }

    public function openSend(int $couponId): void
    {
        $this->guardAccess(Permission::Write);

        $coupon = LimoCoupon::query()->with('customer')->find($couponId);
        if ($coupon === null) {
            return;
        }

        $this->resetErrorBag();
        $this->sendingId = (int) $coupon->id;
        $this->sendEmail = app(CouponSender::class)->suggestedEmail($coupon);
    }

    public function closeSend(): void
    {
        $this->sendingId = null;
        $this->sendEmail = '';
        $this->resetErrorBag();
    }

    /** Email the customer their coupon, with the voucher attached. */
    public function sendCoupon(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->sendingId === null) {
            return;
        }

        $coupon = LimoCoupon::query()->with(['customer', 'redemptions'])->find($this->sendingId);
        if ($coupon === null) {
            $this->closeSend();

            return;
        }

        $this->validate(
            ['sendEmail' => ['required', 'email', 'max:255']],
            [],
            ['sendEmail' => __('Email')],
        );

        try {
            $result = app(CouponSender::class)->send($coupon, $this->sendEmail);
        } catch (Throwable $e) {
            // Mail is the one part of this that leans on something outside the
            // app, so a failure is reported where the office is looking instead
            // of arriving as a 500.
            $this->addError('sendEmail', __('Could not send: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $this->closeSend();
        session()->flash('coupon_status', $result['resent']
            ? __('Coupon sent again to :email.', ['email' => $result['email']])
            : __('Coupon sent to :email.', ['email' => $result['email']]));
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $coupons = LimoCoupon::query()
            ->with(['customer:id,name', 'redemptions'])
            ->when($this->search !== '', function ($q): void {
                $like = '%' . $this->search . '%';
                $q->where(function ($w) use ($like): void {
                    $w->where('code', 'like', $like)
                        ->orWhere('leg_reference', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like));
                });
            })
            ->latest('id')
            ->paginate(20);

        // State is derived from the redemptions and the date, so filtering has
        // to happen on the loaded page rather than in SQL. Fine at this volume,
        // and it keeps one definition of "used" instead of two that can drift.
        $rows = collect($coupons->items())
            ->filter(fn (LimoCoupon $c): bool => $this->tab === 'all' || $c->state() === $this->tab)
            ->values();

        $all = LimoCoupon::query()->with('redemptions')->get();

        return view('limousine::coupons', [
            'coupons' => $coupons,
            'canWrite' => $this->mayAccess(Permission::Write),
            'using' => $this->usingId !== null
                ? LimoCoupon::query()->with('redemptions')->find($this->usingId)
                : null,
            'sending' => $this->sendingId !== null
                ? LimoCoupon::query()->with(['customer', 'redemptions'])->find($this->sendingId)
                : null,
            'rows' => $rows,
            'counts' => [
                'all' => $all->count(),
                'active' => $all->filter(fn (LimoCoupon $c): bool => $c->state() === 'active')->count(),
                'used' => $all->filter(fn (LimoCoupon $c): bool => $c->state() === 'used')->count(),
                'expired' => $all->filter(fn (LimoCoupon $c): bool => $c->state() === 'expired')->count(),
            ],
            // What the business still owes in credit — the number worth knowing.
            'outstanding' => round($all->sum(fn (LimoCoupon $c): float => $c->isExpired() ? 0.0 : $c->remaining()), 3),
        ]);
    }
}
