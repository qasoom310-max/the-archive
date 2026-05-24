<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Views\ColumnDef;
use App\Erp\Views\DatePreset;
use App\Erp\Views\FilterDef;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use App\Models\UserViewPreference;
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
     * Field names the current user has hidden via the column picker.
     * Hydrated in mount() from {@see UserViewPreference}; defaults to the
     * arch's `hidden_by_default` set on first visit. Persisted back to
     * the DB by {@see toggleColumn()} so the choice survives a refresh
     * and follows the user across browsers / devices.
     *
     * @var list<string>
     */
    public array $hiddenColumns = [];

    /**
     * User-chosen display order of column field names. Empty = use the
     * arch's natural order. Unknown field names (after an arch change)
     * are filtered out at read time so a stale row can never crash the
     * view. Persisted by {@see reorderColumns()}.
     *
     * @var list<string>
     */
    public array $columnOrder = [];

    /**
     * Flips while the column-picker dropdown is open. Local to the
     * component — not URL-bound (the dropdown is ephemeral chrome,
     * not part of a shareable view state).
     */
    public bool $columnPickerOpen = false;

    /**
     * Free-text search query. URL-bound so a search is shareable and
     * survives a refresh. Applied as a single OR-grouped `LIKE
     * '%query%'` across the arch-declared `searchable` field list (see
     * {@see ViewArch::$searchable}) — empty arch list = no search bar
     * rendered and the input is silently ignored even if the URL
     * smuggles a value.
     */
    #[Url(except: '')]
    public string $search = '';

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

        $this->loadUserColumnPreferences();
    }

    /**
     * Pull per-user hidden + ordered columns. Modes:
     *  - No user (e.g. anonymous endpoint, test without login) → just
     *    apply the arch's hidden-by-default defaults; no persistence.
     *  - No row yet → seed from arch's `hidden_by_default` set so the
     *    user sees the curated initial view on their first visit. The
     *    row itself isn't written until they actually toggle something.
     *  - Existing row → apply as-is.
     */
    private function loadUserColumnPreferences(): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->hiddenColumns = $this->defaultHiddenFromArch();

            return;
        }

        $pref = UserViewPreference::forUserAndModel((int) $user->getKey(), $this->modelKey);

        if (! $pref->exists) {
            $this->hiddenColumns = $this->defaultHiddenFromArch();
            $this->columnOrder = [];

            return;
        }

        $this->hiddenColumns = $pref->hidden_columns;
        $this->columnOrder = $pref->column_order;
    }

    /**
     * @return list<string>
     */
    private function defaultHiddenFromArch(): array
    {
        return array_values(array_map(
            static fn (ColumnDef $c): string => $c->field,
            array_filter($this->arch->columns, static fn (ColumnDef $c): bool => $c->hiddenByDefault),
        ));
    }

    /**
     * Validate the dropdown's new value and reset to page 1 so the user
     * lands on a fresh paginated slice (otherwise a page index from the
     * smaller page size could overshoot the new larger result set).
     */
    /**
     * Typing in the search box on a deep page would otherwise leave the
     * user on an orphan page index (e.g. page 7 of an 80-row table after
     * the search narrows it to 3 rows). Reset to page 1 on every keystroke.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

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

    /**
     * Inline toggle for `format: toggle` boolean columns in the list view.
     * Flips the column on the named record and persists immediately so the
     * switch acts native — no Save click, no detail-page round-trip.
     *
     * Safety boundaries (all enforced server-side; the Blade just sends a
     * wire:click):
     *   - field MUST appear in the arch with format='toggle' (rejects
     *     `is_admin` / `password_verified_at` / any other random column)
     *   - user MUST have Write permission on this model
     *   - record MUST exist; an invalid id silently no-ops (don't 500)
     *
     * @param int|string $id
     */
    public function toggleBoolean(int|string $id, string $field): void
    {
        $column = $this->columnByField($field);
        if ($column === null || $column->format !== 'toggle') {
            return;
        }

        if (! $this->may(Permission::Write)) {
            $this->access()->authorize(Auth::user(), $this->modelKey, Permission::Write);
        }

        $record = $this->model::query()->find($id);
        if ($record === null) {
            return;
        }

        $record->setAttribute($field, ! (bool) $record->getAttribute($field));
        $record->save();
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
        $this->applySearch($query);

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
     * Apply the toolbar search box across every arch-declared `searchable`
     * field as a single OR-grouped `LIKE '%query%'`. No searchable list
     * = silent no-op (the input isn't rendered either, so a smuggled URL
     * value just gets ignored). Spaces around the query are trimmed; an
     * all-whitespace query is treated as empty.
     *
     * For Spatie translatable JSON columns (e.g. `name`), substring-LIKE
     * still matches because the column literally contains
     * `{"en":"Espresso",...}` — Espresso substring hits. Once Arabic
     * translations land we'll want to widen to per-locale JSON-path
     * matching; for now this is the simplest correct thing.
     *
     * @param  Builder<Model>  $query
     */
    private function applySearch(Builder $query): void
    {
        $needle = trim($this->search);

        if ($needle === '' || $this->arch->searchable === []) {
            return;
        }

        $fields = $this->arch->searchable;

        $query->where(function (Builder $sub) use ($fields, $needle): void {
            foreach ($fields as $field) {
                $sub->orWhere($field, 'like', '%'.$needle.'%');
            }
        });
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

    /**
     * Apply user's hidden + ordered prefs on top of the arch's column
     * list. Reordering keeps unknown fields in their natural slot so an
     * arch change (column added later) doesn't drop it from the user's
     * view. Filtering then drops hidden columns. Always returns a
     * list — never associative — so the Blade can foreach directly.
     *
     * @return list<\App\Erp\Views\ColumnDef>
     */
    public function visibleColumns(): array
    {
        $archCols = $this->arch->columns;

        if ($this->columnOrder !== []) {
            $byField = [];
            foreach ($archCols as $c) {
                $byField[$c->field] = $c;
            }

            $ordered = [];
            $seen = [];
            foreach ($this->columnOrder as $field) {
                if (isset($byField[$field])) {
                    $ordered[] = $byField[$field];
                    $seen[$field] = true;
                }
            }
            // Append any arch columns the prefs row hasn't seen yet
            // (introduced by a later arch update) — keep them visible
            // unless explicitly hidden by the user.
            foreach ($archCols as $c) {
                if (! isset($seen[$c->field])) {
                    $ordered[] = $c;
                }
            }

            $archCols = $ordered;
        }

        return array_values(array_filter(
            $archCols,
            fn (ColumnDef $c): bool => ! in_array($c->field, $this->hiddenColumns, true),
        ));
    }

    /**
     * Flip a column's visibility and persist the new hidden set per
     * (user, model). Unknown fields are ignored so a stale Blade or a
     * malicious client can't pollute the prefs row.
     */
    public function toggleColumn(string $field): void
    {
        if ($this->columnByField($field) === null) {
            return;
        }

        $hidden = $this->hiddenColumns;
        $idx = array_search($field, $hidden, true);

        if ($idx === false) {
            $hidden[] = $field;
        } else {
            array_splice($hidden, $idx, 1);
        }

        $this->hiddenColumns = array_values($hidden);

        $this->persistColumnPreferences();
    }

    /**
     * Apply a user-picked column order (drag-drop in the picker). Only
     * fields the arch actually declares are accepted; extras are
     * dropped silently. Missing arch columns are appended at the end
     * so a partial drag-drop still leaves every column reachable.
     *
     * @param list<string> $order
     */
    public function reorderColumns(array $order): void
    {
        $known = array_map(static fn (ColumnDef $c): string => $c->field, $this->arch->columns);

        $clean = [];
        foreach ($order as $field) {
            if (is_string($field) && in_array($field, $known, true) && ! in_array($field, $clean, true)) {
                $clean[] = $field;
            }
        }

        foreach ($known as $field) {
            if (! in_array($field, $clean, true)) {
                $clean[] = $field;
            }
        }

        $this->columnOrder = $clean;

        $this->persistColumnPreferences();
    }

    private function persistColumnPreferences(): void
    {
        $user = Auth::user();

        if ($user === null || $this->modelKey === '') {
            return;
        }

        UserViewPreference::query()->updateOrCreate(
            ['user_id' => (int) $user->getKey(), 'model_key' => $this->modelKey],
            ['hidden_columns' => $this->hiddenColumns, 'column_order' => $this->columnOrder],
        );
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
            $this->applySearch($aggregateQuery);

            if ($column->sum) {
                $aggregates[$column->field] = (float) $aggregateQuery->sum($column->field);
            } else {
                $aggregates[$column->field] = (float) $aggregateQuery->avg($column->field);
            }
        }

        return view('livewire.views.list-view', [
            'columns' => $this->visibleColumns(),
            'allColumns' => $arch->columns,
            'records' => $records,
            'aggregates' => $aggregates,
            'openUrl' => $arch->openUrl,
            'canDelete' => $this->may(Permission::Unlink),
            'filters' => $arch->filters,
            'activeFilter' => $this->filter,
            'customDateField' => $arch->customDateField,
            'customRangeLabel' => $this->customRangeLabel(),
            'perPageOptions' => $this->perPageOptions(),
            'searchable' => $arch->searchable !== [],
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
