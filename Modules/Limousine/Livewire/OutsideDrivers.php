<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Support\OutsideDriverPayments;

/**
 * Outside drivers: each commission driver, with every trip they drove in the
 * period and what they are paid for it. Administrators, Supervisors and
 * Accountants (the owner's call, 2026-10-21), each still needing Read on
 * Limousine drivers; Staff do not see what drivers are paid.
 */
#[Layout('components.layouts.app')]
#[Title('Outside drivers')]
final class OutsideDrivers extends Component
{
    use GuardsModelAccess;

    /** Y-m; the month shown when no From/To is typed. */
    #[Url]
    public string $month = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(except: '')]
    public string $search = '';

    protected function accessModelKey(): string
    {
        return 'limousine.driver';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        $this->guardRole();

        if ($this->month === '' || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = Carbon::now()->format('Y-m');
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->guardRole();

        $this->month = $this->monthStart()->addMonthsNoOverflow($by)->format('Y-m');
        $this->from = '';
        $this->to = '';
    }

    public function render(): View
    {
        [$from, $to] = $this->window();

        return view('limousine::outside-drivers', [
            'report' => (new OutsideDriverPayments($from, $to, $this->search))->report(),
            'periodFrom' => $from,
            'periodTo' => $to,
            'isMonth' => $this->from === '' && $this->to === '',
        ]);
    }

    /**
     * The typed From/To when either is set, else the chosen month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        $from = $this->date($this->from);
        $to = $this->date($this->to);

        if ($from === null && $to === null) {
            $start = $this->monthStart();

            return [$start, $start->copy()->endOfMonth()];
        }

        $from ??= ($to ?? Carbon::now())->copy()->startOfMonth();
        $to ??= Carbon::now();

        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    private function monthStart(): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $this->month.'-01')?->startOfDay() ?? Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            return Carbon::now()->startOfMonth();
        }
    }

    private function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Administrators, Supervisors and Accountants. */
    public static function roleMayView(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isAdmin()
            || in_array(app(UserProvisioner::class)->roleOf($user), [StaffRole::Supervisor, StaffRole::Accountant], true);
    }

    private function guardRole(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && self::roleMayView($user), 403);
    }
}
