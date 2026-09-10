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
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoPettyAdvance;
use Modules\Limousine\Services\PettyCash as PettyCashService;

/**
 * The petty-cash desk: the float, the advances out with drivers, and the
 * month's expense report.
 *
 * The manager issues; the accountant confirms and settles. Those are kept as
 * different hands on purpose — handing a man cash and vouching for what came
 * back should not be the same signature.
 */
#[Layout('components.layouts.app')]
#[Title('Petty Cash')]
final class PettyCash extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    protected function accessModelKey(): string
    {
        return 'limousine.petty_cash';
    }

    /** open · cleared · report */
    #[Url(except: '')]
    public string $tab = 'open';

    /** The report's month, as Y-m. */
    #[Url(except: '')]
    public string $month = '';

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    /** Issuing and topping up are the manager's actions. */
    private function guardManage(): void
    {
        $this->guardAccess(Permission::Write);
        abort_unless(Auth::user()?->isAdmin() ?? false, 403);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /** Top-up dialog. */
    public bool $toppingUp = false;

    public string $topAmount = '0';

    public string $topDate = '';

    public string $topNotes = '';

    public function openTopUp(): void
    {
        $this->guardManage();
        $this->topAmount = '0';
        $this->topDate = now()->format('Y-m-d');
        $this->topNotes = '';
        $this->resetErrorBag();
        $this->toppingUp = true;
    }

    public function closeTopUp(): void
    {
        $this->toppingUp = false;
    }

    public function saveTopUp(PettyCashService $petty): void
    {
        $this->guardManage();

        $this->validate([
            'topAmount' => ['required', 'numeric', 'min:0.001'],
            'topDate' => ['required', 'date'],
            'topNotes' => ['nullable', 'string', 'max:255'],
        ]);

        $petty->topUp(
            (float) $this->topAmount,
            $this->topDate,
            $this->topNotes !== '' ? $this->topNotes : null,
            (string) (Auth::user()->name ?? ''),
        );

        $this->toppingUp = false;
        session()->flash('toast', __('Float topped up.'));
    }

    /** Issue dialog. */
    public bool $issuing = false;

    public ?int $issueDriverId = null;

    public string $issueAmount = '0';

    public string $issueDate = '';

    public string $issueNotes = '';

    public function openIssue(): void
    {
        $this->guardManage();
        $this->issueDriverId = null;
        $this->issueAmount = '0';
        $this->issueDate = now()->format('Y-m-d');
        $this->issueNotes = '';
        $this->resetErrorBag();
        $this->issuing = true;
    }

    public function closeIssue(): void
    {
        $this->issuing = false;
    }

    public function saveIssue(PettyCashService $petty): void
    {
        $this->guardManage();

        $this->validate([
            'issueDriverId' => ['required', 'integer'],
            'issueAmount' => ['required', 'numeric', 'min:0.001'],
            'issueDate' => ['required', 'date'],
            'issueNotes' => ['nullable', 'string', 'max:255'],
        ]);

        $driver = LimoDriver::query()->find($this->issueDriverId);
        if ($driver === null) {
            $this->addError('issueDriverId', __('Pick a driver.'));

            return;
        }

        $advance = $petty->issue(
            $driver,
            (float) $this->issueAmount,
            $this->issueDate,
            $this->issueNotes !== '' ? $this->issueNotes : null,
            (string) (Auth::user()->name ?? ''),
        );

        // The one refusal that matters: cash that is not in the float cannot
        // be handed out of it.
        if ($advance === null) {
            $this->addError('issueAmount', __('The float does not hold that much. Top it up first.'));

            return;
        }

        $this->issuing = false;
        session()->flash('toast', __('Sent to :driver — waiting for the accountant to confirm.', ['driver' => $driver->name]));
    }

    /**
     * The month's story: what was spent by category and by driver, and what
     * the settlements decided about salaries.
     *
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $from = Carbon::parse($this->month . '-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();

        $cleared = LimoPettyAdvance::query()
            ->with(['driver:id,name', 'lines'])
            ->where('status', LimoPettyAdvance::STATUS_CLEARED)
            ->whereBetween('settled_at', [$from, $to])
            ->orderBy('settled_at')
            ->get();

        $byCategory = [];
        foreach ($cleared as $advance) {
            foreach ($advance->lines as $line) {
                $byCategory[$line->category] = round(($byCategory[$line->category] ?? 0.0) + $line->amount, 3);
            }
        }

        $byDriver = $cleared->groupBy('driver_id')->map(fn ($group) => [
            'name' => (string) ($group->first()?->driver->name ?? '—'),
            'issued' => round((float) $group->sum('amount'), 3),
            'receipts' => round((float) $group->sum('receipts_total'), 3),
            'shortfall' => round((float) $group->sum('shortfall'), 3),
            'excess' => round((float) $group->sum('excess'), 3),
        ])->values();

        return [
            'byCategory' => $byCategory,
            'byDriver' => $byDriver,
            // The list that goes to payroll: what each settlement decided.
            'deductions' => $cleared->filter(fn (LimoPettyAdvance $a): bool => $a->shortfall > 0.0005)->values(),
            'spent' => round((float) $cleared->sum('receipts_total'), 3),
        ];
    }

    public function render(PettyCashService $petty): View
    {
        $query = LimoPettyAdvance::query()->with('driver:id,name')->withSum('lines', 'amount')->orderByDesc('id');

        if ($this->tab === 'open') {
            $query->where('status', '!=', LimoPettyAdvance::STATUS_CLEARED);
        } elseif ($this->tab === 'cleared') {
            $query->where('status', LimoPettyAdvance::STATUS_CLEARED);
        }

        return view('limousine::petty-cash', [
            'advances' => $this->tab === 'report' ? null : $query->paginate(20),
            'balance' => $petty->floatBalance(),
            'outstanding' => $petty->outstanding(),
            'toConfirmCount' => LimoPettyAdvance::query()->where('status', LimoPettyAdvance::STATUS_ISSUED)->count(),
            'openCount' => LimoPettyAdvance::query()->where('status', '!=', LimoPettyAdvance::STATUS_CLEARED)->count(),
            'drivers' => LimoDriver::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => Auth::user()?->isAdmin() ?? false,
            'report' => $this->tab === 'report' ? $this->report() : null,
        ]);
    }
}
