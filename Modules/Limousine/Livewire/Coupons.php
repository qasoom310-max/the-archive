<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoCoupon;

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

    protected function accessModelKey(): string
    {
        return 'limousine.booking';
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
