<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

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
            ->forPage($this->getPage(), $this->arch->perPage)
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
        $sortable = $this->sortableFields();

        foreach ($this->sorts as $sort) {
            if (in_array($sort['field'], $sortable, true)) {
                $query->orderBy($sort['field'], $sort['dir']);
            }
        }

        return $query;
    }

    public function render(): View
    {
        if (! $this->may(Permission::Read)) {
            return view('livewire.views.forbidden');
        }

        $arch = $this->arch;
        $records = $this->buildQuery()->paginate($arch->perPage);

        $aggregates = [];
        foreach ($arch->columns as $column) {
            if ($column->sum) {
                $aggregates[$column->field] = (float) $this->model::query()->sum($column->field);
            } elseif ($column->avg) {
                $aggregates[$column->field] = (float) $this->model::query()->avg($column->field);
            }
        }

        return view('livewire.views.list-view', [
            'columns' => $arch->columns,
            'records' => $records,
            'aggregates' => $aggregates,
            'openUrl' => $arch->openUrl,
            'canDelete' => $this->may(Permission::Unlink),
        ]);
    }
}
