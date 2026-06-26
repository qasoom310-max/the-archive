<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Chatter\Chatterable;
use App\Erp\Security\Permission;
use App\Livewire\Concerns\HasAccessControl;
use App\Erp\Views\ValueFormat;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Abstract, metadata-driven Kanban board: records grouped by a state
 * column, drag-and-drop between columns performs the state transition,
 * and untouched cards show the Odoo-19 "rotting" aging cue.
 *
 * Two rendering modes:
 *
 *   - **Grouped** (`arch.group_by` set, e.g. POS order kanban): full
 *     state-machine workflow. All matching records are loaded (capped
 *     at `$limit`) so every swimlane shows its true count. Lazy load
 *     is disabled — a workflow board is not a catalogue.
 *
 *   - **Ungrouped** (no `arch.group_by`, e.g. POS product catalogue):
 *     responsive tile grid. The first `arch.per_page` records render,
 *     and an IntersectionObserver sentinel calls `loadMore()` to bump
 *     `$loaded` by the same step when the viewport hits the bottom.
 *
 * `$search` (URL-bound, debounced from the view) drives a single OR-
 * grouped `LIKE '%query%'` over every field in `arch.searchable`.
 *
 * @property-read ViewArch $arch
 */
final class KanbanView extends Component
{
    use HasAccessControl;

    /** @var class-string<Model> */
    public string $model;

    public string $modelKey = '';

    public string $title = '';

    /**
     * Hard cap for grouped (state-machine) boards. Catalogues use the
     * per-page lazy-load instead.
     */
    public int $limit = 200;

    /**
     * Free-text search query. URL-bound so a search is shareable and
     * survives a refresh — empty arch.searchable = no input rendered
     * and the input is silently ignored even if the URL smuggles a
     * value. Debounced in the blade so each keystroke doesn't round-
     * trip.
     */
    #[Url(except: '')]
    public string $search = '';

    /**
     * Cards visible right now on an ungrouped (catalogue) board.
     * Initialised in mount() from `arch.per_page`; `loadMore()` adds
     * another page-worth on each IntersectionObserver hit.
     */
    public int $loaded = 12;

    /**
     * @param  class-string<Model>  $model
     */
    public function mount(string $model, string $modelKey = '', string $title = ''): void
    {
        $this->model = $model;
        $this->modelKey = $modelKey;
        $this->title = $title;

        // Page size from arch (kanban arch declares `per_page`); fall
        // back to 12 so the engine works even without explicit arch.
        $this->loaded = $this->pageSize();
    }

    #[Computed]
    public function arch(): ViewArch
    {
        return app(ViewResolver::class)->arch($this->modelKey, 'kanban');
    }

    private function pageSize(): int
    {
        $perPage = $this->arch->perPage;

        return $perPage > 0 ? $perPage : 12;
    }

    /**
     * Search box keystroke handler. Reset the lazy-load window to the
     * first page whenever the query changes — otherwise typing into a
     * deep-scrolled board would leave it showing a confusing slice of
     * the now-narrowed result set.
     */
    public function updatedSearch(): void
    {
        $this->loaded = $this->pageSize();
    }

    /**
     * Reveal another page of records. Bound to the bottom sentinel's
     * IntersectionObserver in the blade; no-ops on grouped boards
     * because they don't use the lazy-load window in the first place.
     */
    public function loadMore(): void
    {
        $this->loaded += $this->pageSize();
    }

    /**
     * Drag-drop handler: move a card to another column = state transition.
     */
    public function moveCard(int|string $id, string $toStage): void
    {
        if (! $this->may(Permission::Write)) {
            $this->access()->authorize(Auth::user(), $this->modelKey, Permission::Write);
        }

        $groupBy = $this->arch->groupBy;

        if ($groupBy === null) {
            return;
        }

        $record = $this->model::query()->whereKey($id)->first();

        if ($record === null) {
            return;
        }

        $from = ValueFormat::key($record->getAttribute($groupBy));

        if ($from === $toStage) {
            return;
        }

        $record->setAttribute($groupBy, $toStage);
        $record->save();

        if ($record instanceof Chatterable) {
            $record->logChange("Stage: {$from} → {$toStage}");
        }

        $this->dispatch('card-moved', id: $id, to: $toStage);
    }

    /**
     * Apply the toolbar search box as a single OR-grouped `LIKE '%q%'`
     * across every arch-declared `searchable` field. Empty arch list =
     * silent no-op (and the input isn't rendered, so a smuggled URL
     * value just gets ignored). Mirrors the ListView counterpart.
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

    public function render(): View
    {
        if (! $this->may(Permission::Read)) {
            return view('livewire.views.forbidden');
        }

        $arch = $this->arch;
        $groupBy = $arch->groupBy;

        /** @var Builder<Model> $base */
        $base = $this->model::query();
        $this->applySearch($base);

        if ($groupBy === null) {
            // Catalogue mode — lazy-load window. count() runs before
            // limit() so we know whether to render the load-more
            // sentinel; the second query takes only the visible page.
            $totalMatching = (clone $base)->count();
            $records = (clone $base)->limit($this->loaded)->get();
            $hasMore = $totalMatching > $this->loaded;
        } else {
            // State-machine mode — workflow board, all stages full.
            $records = (clone $base)->limit($this->limit)->get();
            $hasMore = false;
        }

        // Establish column order: explicit stages first, then any extra
        // values found in the data.
        $columns = [];
        foreach ($arch->stages as $stage) {
            $columns[$stage['value']] = $stage['label'];
        }

        if ($groupBy === null) {
            $columns = ['' => $this->title !== '' ? $this->title : 'All'];
        } else {
            foreach ($records as $record) {
                $value = ValueFormat::key($record->getAttribute($groupBy));
                if (! array_key_exists($value, $columns)) {
                    $columns[$value] = $value === '' ? 'Undefined' : $value;
                }
            }
        }

        /** @var array<string, list<Model>> $grouped */
        $grouped = array_fill_keys(array_keys($columns), []);

        foreach ($records as $record) {
            $value = $groupBy === null ? '' : ValueFormat::key($record->getAttribute($groupBy));
            $grouped[$value][] = $record;
        }

        return view('livewire.views.kanban-view', [
            'columns' => $columns,
            'grouped' => $grouped,
            'card' => $arch->card,
            'rotting' => $arch->rotting,
            'groupBy' => $groupBy,
            'openUrl' => $arch->openUrl,
            'searchable' => $arch->searchable !== [],
            'hasMore' => $hasMore,
        ]);
    }
}
