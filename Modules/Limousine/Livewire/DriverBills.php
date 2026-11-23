<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoDriverBill;

/**
 * Bills drivers sent through their trip link, waiting for the office: open
 * one to check what was read and save it as an expense (with the petty-cash
 * question), or set it aside. Nothing is deleted.
 */
#[Layout('components.layouts.app')]
#[Title('Bills from drivers')]
final class DriverBills extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'limousine.expense';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /** Not an expense: kept with its photo, marked set aside. */
    public function discard(int $id): void
    {
        $this->guardAccess(Permission::Create);

        LimoDriverBill::query()->whereKey($id)->where('status', LimoDriverBill::STATUS_NEW)->update([
            'status' => LimoDriverBill::STATUS_DISCARDED,
            'handled_by_user_id' => Auth::id(),
            'handled_at' => Carbon::now(),
        ]);
    }

    public function render(): View
    {
        return view('limousine::driver-bills', [
            'waiting' => LimoDriverBill::query()->with(['driver:id,name', 'leg:id,reference,start_at'])
                ->where('status', LimoDriverBill::STATUS_NEW)->orderBy('id')->get(),
            'handled' => LimoDriverBill::query()->with(['driver:id,name', 'leg:id,reference', 'expense:id,reference'])
                ->where('status', '!=', LimoDriverBill::STATUS_NEW)->latest('handled_at')->limit(30)->get(),
            'canSave' => $this->mayAccess(Permission::Create),
        ]);
    }
}
