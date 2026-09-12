<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Support\DriverAliases as DriverAliasService;

/**
 * Saying who the old system's driver logins actually were.
 *
 * Trips carried over name their driver in text, and the text is a LOGIN —
 * "kown", "smakhlooq". This is where each one is told apart: a driver in the
 * register, or the office (an owner's account, a system login, a trip nobody
 * drove). Until a name is decided it keeps appearing exactly as imported, so
 * an unanswered question never quietly becomes an answer.
 *
 * Nothing about a trip is rewritten by saving here. The earnings league, a
 * driver's job history and the petty-cash column all read the trips THROUGH
 * this mapping, so a name decided wrongly is put right by changing it back.
 */
#[Layout('components.layouts.app')]
#[Title('Old driver names')]
final class DriverAliases extends Component
{
    use GuardsModelAccess;

    /** The office, rather than any driver in the register. */
    public const OFFICE = 'office';

    /**
     * slug => '' | 'office' | driver id, one for every name on file.
     *
     * Typed as mixed because it is filled from the browser: the page sends back
     * whatever it sends back, and {@see save()} is where that becomes a string.
     *
     * @var array<string, mixed>
     */
    public array $choices = [];

    #[Url(except: '')]
    public string $search = '';

    /** 'todo' = still to decide; 'auto' = decided by the system; 'all' = every name. */
    #[Url(except: 'todo')]
    public string $filter = 'todo';

    protected function accessModelKey(): string
    {
        return 'limousine.driver';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        $this->loadChoices();
    }

    public function updatedFilter(string $value): void
    {
        $this->filter = in_array($value, ['todo', 'auto', 'all'], true) ? $value : 'todo';
    }

    public function setFilter(string $filter): void
    {
        $this->updatedFilter($filter);
    }

    /**
     * Put every name's current answer in the box, and a first guess where there
     * is no answer yet. The guess is only ever offered — it is written when the
     * person presses Save, exactly like one they typed themselves.
     */
    private function loadChoices(): void
    {
        $this->choices = [];

        foreach ($this->candidates() as $row) {
            $this->choices[$row['slug']] = match (true) {
                $row['office'] => self::OFFICE,
                $row['driverId'] !== null => (string) $row['driverId'],
                $row['suggestion'] !== null => (string) $row['suggestion'],
                default => '',
            };
        }
    }

    public function save(): void
    {
        // Re-checked on the action: Livewire dispatches straight to a method,
        // so a gate that only ran on mount is not a gate.
        $this->guardAccess(Permission::Write);

        $user = Auth::user();

        $counts = app(DriverAliasService::class)->save(
            array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->choices),
            $this->candidates(),
            $user === null ? null : (string) ($user->name ?? ''),
        );

        $this->candidatesCache = null;
        $this->loadChoices();

        session()->flash('toast', __(':drivers name(s) matched to a driver, :office marked as the office, :todo still to decide.', [
            'drivers' => $counts['drivers'],
            'office' => $counts['office'],
            'todo' => $counts['undecided'],
        ]));
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $candidatesCache = null;

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        return $this->candidatesCache ??= app(DriverAliasService::class)->candidates();
    }

    public function render(): View
    {
        $this->guardAccess(Permission::Read);

        $rows = $this->candidates();
        $term = mb_strtolower(trim($this->search));

        $todo = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['decided'] === false && $r['linked'] < $r['trips'],
        ));

        // What the system answered for itself. Worth its own list: an answer
        // nobody can see is an answer nobody can correct.
        $auto = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['auto'] === true && $r['decided'] === true,
        ));

        if ($this->filter === 'todo') {
            $rows = $todo;
        } elseif ($this->filter === 'auto') {
            $rows = $auto;
        }

        if ($term !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => str_contains(mb_strtolower((string) $r['display']), $term),
            ));
        }

        $drivers = LimoDriver::query()->orderBy('name')->get(['id', 'name']);

        $options = [
            ['value' => '', 'label' => __('— Not decided —')],
            ['value' => self::OFFICE, 'label' => __('Not a driver (office / system account)')],
        ];

        foreach ($drivers as $driver) {
            $options[] = ['value' => (string) $driver->getKey(), 'label' => (string) $driver->name];
        }

        return view('limousine::driver-aliases', [
            'rows' => $rows,
            'options' => $options,
            'driverNames' => $drivers->pluck('name', 'id')->all(),
            'todoCount' => count($todo),
            'autoCount' => count($auto),
            'totalCount' => count($this->candidates()),
            'canSave' => $this->mayAccess(Permission::Write),
        ]);
    }
}
