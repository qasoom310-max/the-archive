<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Views\DatePreset;
use App\Erp\Views\FilterDef;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Abstract, metadata-driven List view: multi-column sorting, checkbox
 * bulk actions and aggregate column totals. The layout comes entirely
 * from the resolved `ir_ui_view` arch (or an auto-generated default).
 *
 * @property-read ViewArch $arch
 */
final class ListView extends Component
{
    use WithPagination;

    /** @var class-string<Model> */
    public string $model;

    public string $modelKey = '';

    public string $title = '';

    /** @var list<array{field: string, dir: 'asc'|'desc'}> */
    public array $sorts = [];

    /** @var list<int|string> */
    public array $selected = [];

    public bool $selectPage = false;

    /**
     * Rows-per-page selection. URL-bound (so the choice is shareable
     * and survives a refresh). Initialised in `mount()` from the arch
     * default; user-changeable via the footer dropdown. Validated in
     * `updatedPerPage()` against {@see PER_PAGE_OPTIONS} (plus the arch
     * default itself) to shield against URL tampering and to keep the
     * select chip on a known value.
     */
    #[Url(except: 0)]
    public int $perPage = 0;

    /**
     * Canonical row-count options the dropdown offers. Kept small +
     * round so the chip stays one line. A view's arch may declare a
     * different `per_page` (e.g. 25) — that value becomes the initial
     * selection AND is preserved as a valid choice so refreshes don't
     * silently bump the user back to 20.
     */
    public const PER_PAGE_OPTIONS = [20, 50, 100];

    /**
     * Name of the currently-active filter preset (see {@see FilterDef})
     * or the sentinel string `custom` when the user has chosen a
     * date range via the Custom popover. URL-bound so the Reporting
     * link can deep-link to a pre-filtered view (e.g. `?filter=today`).
     * Empty string = no filter applied.
     */
    #[Url(except: '')]
    public string $filter = '';

    /**
     * Inclusive lower bound for the Custom-range filter, as ISO date
     * (YYYY-MM-DD). Together with {@see $customTo} it forms a
     * `whereBetween($field, [startOfDay, endOfDay])` on the column
     * declared by the arch's `custom_date_field`. URL-bound so a
     * picked range is shareable / browser-back-able.
     */
    #[Url(except: '')]
    public string $customFrom = '';

    /** Inclusive upper bound — see {@see $customFrom}. */
    #[Url(except: '')]
    public string $customTo = '';

    /**
     * @param  class-string<Model>  $model
     */
    public function mount(string $model, string $modelKey = '', string $title = ''): void
    {
        $this->model = $model;
        $this->modelKey = $modelKey;
        $this->title = $title;

        if ($this->sorts === []) {
            $this->sorts = $this->arch->defaultSort;
        }

        if ($this->perPage === 0) {
            $this->perPage = $this->arch->perPage;
        }
    }

    /**
     * Validate the dropdown's new value and reset to page 1 so the user
     * lands on a fresh paginated slice (otherwise a page index from the
     * smaller page size could overshoot the new larger result set).
     */
    public function updatedPerPage(): void
    {
        $valid = [...self::PER_PAGE_OPTIONS, $this->arch->perPage];

        if (! in_array($this->perPage, $valid, true)) {
            $this->perPage = $this->arch->perPage;
        }

        $this->resetPage();
    }

    #[Computed]
    public function arch(): ViewArch
    {
        return app(ViewResolver::class)->arch($this->modelKey, 'list');
    }

    /**
     * @return list<string>
     */
    private function sortableFields(): array
    {
        return array_values(array_map(
            static fn ($c): string => $c->field,
            array_filter($this->arch->columns, static fn ($c): bool => $c->sortable),
        ));
    }

    public function sortBy(string $field, bool $append = false): void
    {
        if (! in_array($field, $this->sortableFields(), true)) {
            return;
        }

        $idx = null;
        foreach ($this->sorts as $i => $sort) {
            if ($sort['field'] === $field) {
                $idx = $i;
                break;
            }
        }

        if (! $append) {
            // Single-column cycle: asc → desc → cleared.
            if ($idx !== null && count($this->sorts) === 1) {
                $this->sorts = $this->sorts[0]['dir'] === 'asc'
                    ? [['field' => $field, 'dir' => 'desc']]
                    : [];
            } else {
                $this->sorts = [['field' => $field, 'dir' => 'asc']];
            }
        } elseif ($idx === null) {
            $this->sorts[] = ['field' => $field, 'dir' => 'asc'];
        } elseif ($this->sorts[$idx]['dir'] === 'asc') {
            $this->sorts[$idx]['dir'] = 'desc';
        } else {
            array_splice($this->sorts, $idx, 1);
        }

        $this->resetPage();
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value ? $this->pageIds() : [];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    private function access(): AccessControl
    {
        return app(AccessControl::class);
    }

    private function may(Permission $permission): bool
    {
        return $this->modelKey === ''
            || $this->access()->allows(Auth::user(), $this->modelKey, $permission);
    }

    public function bulkDelete(): void
    {
        if ($this->selected === []) {
            return;
        }

        if (! $this->may(Permission::Unlink)) {
            $this->access()->authorize(Auth::user(), $this->modelKey, Permission::Unlink);
        }

        $this->model::query()->whereKey($this->selected)->delete();
        $this->dispatch('records-deleted', count: count($this->selected));
        $this->clearSelection();
        $this->resetPage();
    }

    /**
     * @return list<int|string>
     */
    private function pageIds(): array
    {
        $keyName = $this->model::query()->getModel()->getKeyName();

        return $this->buildQuery()
            ->forPage($this->getPage(), $this->perPage)
            ->pluck($keyName)
            ->map(static fn (mixed $v): int|string => is_int($v) ? $v : (string) $v)
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->model::query();

        $this->applyFilter($query);

        $sortable = $this->sortableFields();

        foreach ($this->sorts as $sort) {
            if (! in_array($sort['field'], $sortable, true)) {
                continue;
            }

            // Honour the column's `sort_field` override when present —
            // lets accessor-backed columns (e.g. `processed_by` → user
            // name) sort by a real underlying column (e.g. `user_id`)
            // instead of failing on a non-existent SQL identifier.
            $column = $this->columnByField($sort['field']);
            $sortColumn = $column !== null ? $column->sortColumn() : $sort['field'];

            $query->orderBy($sortColumn, $sort['dir']);
        }

        return $query;
    }

    /**
     * If the URL carries an active filter preset (or 'custom' range),
     * narrow the builder. Used in BOTH the paginated query and the
     * aggregate queries so the footer total tracks the filter (a
     * 'Today' filter shows today's grand total, not the all-time one).
     *
     * Unknown / blank filter names are no-ops — defensive against URL
     * tampering and old links after a preset is removed from the arch.
     *
     * @param  Builder<Model>  $query
     */
    private function applyFilter(Builder $query): void
    {
        if ($this->filter === '') {
            return;
        }

        // Custom range: parse the URL-bound from/to into Carbon
        // start-of-day / end-of-day instants, applied to the arch-
        // declared `custom_date_field`. Either bound missing or
        // unparseable → skip silently (treat as "no filter").
        if ($this->filter === 'custom') {
            $field = $this->arch->customDateField;

            if ($field === null || $this->customFrom === '' || $this->customTo === '') {
                return;
            }

            try {
                $start = Carbon::parse($this->customFrom)->startOfDay();
                $end = Carbon::parse($this->customTo)->endOfDay();
            } catch (Throwable) {
                return;
            }

            if ($start->greaterThan($end)) {
                // Defensive — swap so the user's reversed pick still works.
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            $query->whereBetween($field, [$start, $end]);

            return;
        }

        $def = $this->filterByName($this->filter);

        if ($def === null) {
            return;
        }

        $range = DatePreset::range($def->preset);

        if ($range === null) {
            return;
        }

        [$start, $end] = $range;
        $query->whereBetween($def->field, [$start, $end]);
    }

    /**
     * Toggle / activate a named filter preset. Clicking the active one
     * deactivates it ("All" behaviour); clicking another switches.
     * Always resets pagination so the user lands on page 1 of the new
     * scope (rather than an orphan page index from the previous view).
     */
    public function applyFilterPreset(string $name): void
    {
        $valid = array_map(static fn (FilterDef $f): string => $f->name, $this->arch->filters);

        if ($name === '' || ! in_array($name, $valid, true)) {
            $this->filter = '';
            $this->customFrom = '';
            $this->customTo = '';
        } elseif ($this->filter === $name) {
            $this->filter = ''; // toggle off
            $this->customFrom = '';
            $this->customTo = '';
        } else {
            $this->filter = $name;
            // Switching to a preset clears any leftover custom range,
            // otherwise old from/to would shadow the preset's window.
            $this->customFrom = '';
            $this->customTo = '';
        }

        $this->resetPage();
    }

    /**
     * Apply a user-picked Custom date range. Validates both bounds
     * with Carbon's lenient parser, normalises to ISO `YYYY-MM-DD`,
     * and sets `$filter = 'custom'`. Missing / invalid input keeps
     * the previous filter intact so a fumbled click can't blank the
     * view by accident.
     */
    public function applyCustomRange(): void
    {
        if ($this->arch->customDateField === null) {
            return; // arch hasn't opted in to the Custom popover
        }

        $from = trim($this->customFrom);
        $to = trim($this->customTo);

        if ($from === '' || $to === '') {
            return;
        }

        try {
            $start = Carbon::parse($from);
            $end = Carbon::parse($to);
        } catch (Throwable) {
            return;
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $this->customFrom = $start->toDateString();
        $this->customTo = $end->toDateString();
        $this->filter = 'custom';
        $this->resetPage();
    }

    private function filterByName(string $name): ?FilterDef
    {
        foreach ($this->arch->filters as $filter) {
            if ($filter->name === $name) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * Human-readable label for the Custom chip when active — e.g.
     * "May 1 → May 7". Returns null when no custom range is active,
     * so the chip falls back to plain "Custom…".
     */
    private function customRangeLabel(): ?string
    {
        if ($this->filter !== 'custom' || $this->customFrom === '' || $this->customTo === '') {
            return null;
        }

        try {
            $start = Carbon::parse($this->customFrom);
            $end = Carbon::parse($this->customTo);
        } catch (Throwable) {
            return null;
        }

        return $start->format('M j') . ' → ' . $end->format('M j');
    }

    private function columnByField(string $field): ?\App\Erp\Views\ColumnDef
    {
        foreach ($this->arch->columns as $column) {
            if ($column->field === $field) {
                return $column;
            }
        }

        return null;
    }

    public function render(): View
    {
        if (! $this->may(Permission::Read)) {
            return view('livewire.views.forbidden');
        }

        $arch = $this->arch;
        $records = $this->buildQuery()->paginate($this->perPage);

        // Aggregates must scope to the same filter as the rows above
        // them — otherwise a "Today" filter would show today's rows
        // with the all-time grand total at the bottom, which is the
        // opposite of useful.
        $aggregates = [];
        foreach ($arch->columns as $column) {
            if (! $column->sum && ! $column->avg) {
                continue;
            }

            $aggregateQuery = $this->model::query();
            $this->applyFilter($aggregateQuery);

            if ($column->sum) {
                $aggregates[$column->field] = (float) $aggregateQuery->sum($column->field);
            } else {
                $aggregates[$column->field] = (float) $aggregateQuery->avg($column->field);
            }
        }

        return view('livewire.views.list-view', [
            'columns' => $arch->columns,
            'records' => $records,
            'aggregates' => $aggregates,
            'openUrl' => $arch->openUrl,
            'canDelete' => $this->may(Permission::Unlink),
            'filters' => $arch->filters,
            'activeFilter' => $this->filter,
            'customDateField' => $arch->customDateField,
            'customRangeLabel' => $this->customRangeLabel(),
            'perPageOptions' => $this->perPageOptions(),
        ]);
    }

    /**
     * Build the dropdown option list — the canonical {@see PER_PAGE_OPTIONS}
     * plus the arch default if it isn't already in there. Sorted + de-duped
     * so the dropdown stays clean for non-standard arch defaults (e.g. an
     * arch with per_page=25 surfaces as 20/25/50/100, not 20/50/100/25).
     *
     * @return list<int>
     */
    private function perPageOptions(): array
    {
        $options = array_values(array_unique([...self::PER_PAGE_OPTIONS, $this->arch->perPage]));
        sort($options);

        return $options;
    }
}
