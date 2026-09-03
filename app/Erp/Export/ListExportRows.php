<?php

declare(strict_types=1);

namespace App\Erp\Export;

use App\Erp\Views\ColumnDef;
use App\Erp\Views\DatePreset;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use App\Models\Ir\IrModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One export of one engine list, whichever model it belongs to.
 *
 * The list SCREEN ({@see \App\Livewire\Views\ListView}) and every download of
 * it must show the same rows, so this mirrors that component's own
 * filter/search/sort logic rather than re-deriving it differently — reading
 * it back off the export link's query string (built by the screen itself)
 * instead of Livewire component state, because a plain download link has no
 * Livewire request to read state from.
 *
 * Deliberately reads ALL of the arch's columns, not just the ones the current
 * viewer has chosen to show on screen (the per-user column picker): an export
 * is a copy of the record, and hiding a column from a download because it
 * happened to be hidden from one person's table is how a document quietly
 * goes missing information nobody meant to drop.
 */
final class ListExportRows
{
    public readonly ViewArch $arch;

    /** @var class-string<Model> */
    private readonly string $modelClass;

    public function __construct(public readonly string $modelKey)
    {
        $registered = IrModel::query()->where('model', $modelKey)->first();
        abort_if($registered === null, 404);

        /** @var class-string<Model> $class */
        $class = $registered->class;
        $this->modelClass = $class;
        $this->arch = app(ViewResolver::class)->arch($modelKey, 'list');
    }

    /**
     * @return array<string, string>  field => label, in arch order
     */
    public function headings(): array
    {
        $out = [];
        foreach ($this->arch->columns as $col) {
            $out[$col->field] = $col->label;
        }

        return $out;
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(Request $request): array
    {
        return $this->query($request)->get()->map(fn (Model $m): array => $this->row($m))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(Model $model): array
    {
        $out = [];
        foreach ($this->arch->columns as $col) {
            $out[$col->field] = \App\Erp\Views\ValueFormat::cell($model->getAttribute($col->field), $col->format);
        }

        return $out;
    }

    /**
     * @return Builder<Model>
     */
    public function query(Request $request): Builder
    {
        $query = $this->modelClass::query();

        if ($this->arch->eager !== []) {
            $query->with($this->arch->eager);
        }

        $this->applyFilter($query, $request);
        $this->applyDynamicFilters($query, $request);
        $this->applySearch($query, $request);
        $this->applySort($query, $request);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyFilter(Builder $query, Request $request): void
    {
        $filter = (string) $request->query('filter', '');
        if ($filter === '') {
            return;
        }

        if ($filter === 'custom') {
            $field = $this->arch->customDateField;
            $from = (string) $request->query('from', '');
            $to = (string) $request->query('to', '');

            if ($field === null || $from === '' || $to === '') {
                return;
            }

            try {
                $start = Carbon::parse($from)->startOfDay();
                $end = Carbon::parse($to)->endOfDay();
            } catch (Throwable) {
                return;
            }

            if ($start->greaterThan($end)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            $query->whereBetween($field, [$start, $end]);

            return;
        }

        $def = null;
        foreach ($this->arch->filters as $f) {
            if ($f->name === $filter) {
                $def = $f;
                break;
            }
        }

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
     * @param  Builder<Model>  $query
     */
    private function applyDynamicFilters(Builder $query, Request $request): void
    {
        if ($this->arch->dynamicFilters === []) {
            return;
        }

        /** @var array<string, mixed> $active */
        $active = (array) $request->query('df', []);
        if ($active === []) {
            return;
        }

        $validByName = [];
        foreach ($this->arch->dynamicFilters as $def) {
            $validByName[$def->name] = $def;
        }

        foreach ($active as $name => $value) {
            if (! is_string($name) || ! isset($validByName[$name])) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $query->where($validByName[$name]->field, $value);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $needle = trim((string) $request->query('q', ''));
        if ($needle === '' || $this->arch->searchable === []) {
            return;
        }

        $fields = $this->arch->searchable;

        $query->where(function (Builder $sub) use ($fields, $needle): void {
            foreach ($fields as $field) {
                $sub->orWhere($field, 'like', '%' . $needle . '%');
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySort(Builder $query, Request $request): void
    {
        $sortable = array_values(array_map(
            static fn (ColumnDef $c): string => $c->field,
            array_filter($this->arch->columns, static fn (ColumnDef $c): bool => $c->sortable),
        ));

        /** @var list<array{field?: string, dir?: string}> $sorts */
        $sorts = (array) $request->query('sorts', []);

        $applied = false;
        foreach ($sorts as $sort) {
            $field = (string) ($sort['field'] ?? '');
            if (! in_array($field, $sortable, true)) {
                continue;
            }

            $column = null;
            foreach ($this->arch->columns as $c) {
                if ($c->field === $field) {
                    $column = $c;
                    break;
                }
            }

            $dir = ($sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($column?->sortColumn() ?? $field, $dir);
            $applied = true;
        }

        if (! $applied) {
            $query->orderByDesc($query->getModel()->getKeyName());
        }
    }
}
