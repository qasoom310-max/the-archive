<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosDamage;

/**
 * Damage Report — the bespoke index for `pos.damage`. Lists every write-off in
 * a pick-up date range with a total loss value + item count, a reason filter,
 * a "Log damage" action, and a per-row delete that restores the stock (the
 * model's deleting hook). Doubles as both the log and the report.
 */
#[Layout('components.layouts.app')]
#[Title('Damage Report')]
final class PosDamages extends Component
{
    public string $from = '';

    public string $to = '';

    /** Reason filter; '' = all. */
    public string $reason = '';

    public function mount(): void
    {
        $this->guard(Permission::Read);

        // Default to the current month.
        $this->from = Carbon::now()->startOfMonth()->toDateString();
        $this->to = Carbon::now()->endOfMonth()->toDateString();
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.damage', $permission);
    }

    public function delete(int $id): void
    {
        $this->guard(Permission::Unlink);

        // Model deleting hook restores the stock it removed.
        PosDamage::query()->whereKey($id)->first()?->delete();

        session()->flash('toast', __('Damage entry removed; stock restored.'));
    }

    public function render(): View
    {
        $canCreate = app(AccessControl::class)->allows(Auth::user(), 'pos.damage', Permission::Create);
        $canDelete = app(AccessControl::class)->allows(Auth::user(), 'pos.damage', Permission::Unlink);

        $query = PosDamage::query()
            ->when($this->from !== '', fn ($q) => $q->whereDate('damaged_on', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('damaged_on', '<=', $this->to))
            ->when($this->reason !== '', fn ($q) => $q->where('reason', $this->reason))
            ->orderByDesc('damaged_on')
            ->orderByDesc('id');

        $entries = $query->get();

        return view('pos::damages', [
            'entries' => $entries,
            'totalLoss' => (float) $entries->sum('loss_value'),
            'totalQty' => (float) $entries->sum('quantity'),
            'count' => $entries->count(),
            'reasons' => PosDamage::REASONS,
            'canCreate' => $canCreate,
            'canDelete' => $canDelete,
        ]);
    }
}
