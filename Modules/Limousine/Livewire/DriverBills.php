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

    /** The bill being rejected; its reason is typed in the dialog. */
    #[\Livewire\Attributes\Locked]
    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function openReject(int $id): void
    {
        $this->guardAccess(Permission::Create);
        $this->rejectingId = $id;
        $this->rejectReason = '';
        $this->resetValidation();
    }

    public function closeReject(): void
    {
        $this->rejectingId = null;
    }

    /**
     * Reject the bill with the reason. It stays here, under the driver's name,
     * until someone tells him in person; he then brings it again.
     */
    public function reject(): void
    {
        $this->guardAccess(Permission::Create);
        $this->validate(['rejectReason' => ['required', 'string', 'max:500']], ['rejectReason.required' => __('Say why the bill is rejected.')]);

        $bill = $this->rejectingId !== null ? LimoDriverBill::query()->find($this->rejectingId) : null;
        $bill?->reject($this->rejectReason, Auth::id() !== null ? (int) Auth::id() : null);
        $this->rejectingId = null;
    }

    /** The accountant told the driver in person: recorded, the bill leaves the "to tell" list. */
    public function markTold(int $id): void
    {
        $this->guardAccess(Permission::Create);

        LimoDriverBill::query()->whereKey($id)->where('status', LimoDriverBill::STATUS_REJECTED)->whereNull('told_at')
            ->update(['told_at' => Carbon::now(), 'told_by' => (string) (Auth::user()->name ?? '')]);
    }

    public function render(): View
    {
        return view('limousine::driver-bills', [
            'waiting' => LimoDriverBill::query()->with(['driver:id,name', 'leg:id,reference,start_at'])
                ->where('status', LimoDriverBill::STATUS_NEW)->orderBy('id')->get(),
            // Rejected and not yet told to the driver, under each driver's name.
            'toTell' => LimoDriverBill::query()->with(['driver:id,name', 'leg:id,reference'])
                ->where('status', LimoDriverBill::STATUS_REJECTED)->whereNull('told_at')
                ->orderBy('handled_at')->get()
                ->groupBy(static fn (LimoDriverBill $b): string => (string) ($b->driver->name ?? '—')),
            'handled' => LimoDriverBill::query()->with(['driver:id,name', 'leg:id,reference,driver_id', 'expense:id,reference'])
                ->where('status', '!=', LimoDriverBill::STATUS_NEW)->latest('handled_at')->limit(30)->get(),
            'canSave' => $this->mayAccess(Permission::Create),
        ]);
    }
}
