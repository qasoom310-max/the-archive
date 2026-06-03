<?php

declare(strict_types=1);

namespace App\Erp\Views;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed, defensive reader over an `ir_ui_view.arch` array. Keeps the
 * dynamic view components free of raw mixed-array access.
 */
final readonly class ViewArch
{
    /**
     * @param list<ColumnDef>                                $columns      (list views)
     * @param list<array{field: string, dir: 'asc'|'desc'}>  $defaultSort
     * @param list<array{value: string, label: string}>      $stages       (kanban board columns)
     * @param list<FormFieldDef>                             $formFields   (form views)
     * @param list<FilterDef>                                $filters      (list-view date presets)
     * @param ?string                                        $customDateField  column the "Custom…" range filters on
     */
    /**
     * @param list<ColumnDef>                                $columns      (list views)
     * @param list<array{field: string, dir: 'asc'|'desc'}>  $defaultSort
     * @param list<array{value: string, label: string}>      $stages       (kanban board columns)
     * @param list<FormFieldDef>                             $formFields   (form views)
     * @param list<FilterDef>                                $filters      (list-view date presets)
     * @param ?string                                        $customDateField  column the "Custom…" range filters on
     * @param list<string>                                   $searchable   list-view free-text search fields
     * @param list<DynamicFilterDef>                         $dynamicFilters list-view filter chips loaded from another model
     */
    private function __construct(
        public array $columns,
        public array $defaultSort,
        public int $perPage,
        public ?string $groupBy,
        public array $stages,
        public ?KanbanCard $card,
        public ?RottingRule $rotting,
        public array $formFields,
        public int $formCols,
        public ?string $openUrl,
        public array $filters,
        public ?string $customDateField,
        public array $searchable,
        public array $dynamicFilters,
    ) {}

    /**
     * @param array<string, mixed> $arch
     */
    public static function fromArray(array $arch): self
    {
        return new self(
            columns: self::parseColumns($arch),
            defaultSort: self::parseSort($arch),
            perPage: self::int($arch, 'per_page', 20),
            groupBy: self::str($arch, 'group_by'),
            stages: self::parseStages($arch),
            card: self::parseCard($arch),
            rotting: self::parseRotting($arch),
            formFields: self::parseFormFields($arch),
            formCols: self::int($arch, 'cols', 2),
            openUrl: self::str($arch, 'open'),
            filters: self::parseFilters($arch),
            customDateField: self::str($arch, 'custom_date_field'),
            searchable: self::parseSearchable($arch),
            dynamicFilters: self::parseDynamicFilters($arch),
        );
    }

    /**
     * Parse a list of dynamic (model-sourced) filter chip groups:
     * `[{name, label?, field, optionsFrom: {model, value?, label?, orderBy?}}, …]`.
     *
     * Entries missing any of {name, field, optionsFrom.model} are
     * dropped silently — same defensive policy as `parseFilters`.
     *
     * @param array<string, mixed> $arch
     * @return list<DynamicFilterDef>
     */
    private static function parseDynamicFilters(array $arch): array
    {
        $raw = $arch['filters_dynamic'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = self::str($entry, 'name');
            $field = self::str($entry, 'field');

            $optionsFromRaw = $entry['optionsFrom'] ?? null;
            if ($name === null || $field === null || ! is_array($optionsFromRaw)) {
                continue;
            }

            $model = $optionsFromRaw['model'] ?? null;
            if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            /** @var array{model: class-string<\Illuminate\Database\Eloquent\Model>, value: string, label: string, orderBy?: string} $optionsFrom */
            $optionsFrom = [
                'model' => $model,
                'value' => is_string($optionsFromRaw['value'] ?? null) ? $optionsFromRaw['value'] : 'id',
                'label' => is_string($optionsFromRaw['label'] ?? null) ? $optionsFromRaw['label'] : 'name',
            ];
            if (is_string($optionsFromRaw['orderBy'] ?? null)) {
                $optionsFrom['orderBy'] = $optionsFromRaw['orderBy'];
            }

            $out[] = new DynamicFilterDef(
                name: $name,
                label: self::str($entry, 'label') ?? ucfirst(str_replace('_', ' ', $name)),
                field: $field,
                optionsFrom: $optionsFrom,
            );
        }

        return $out;
    }

    /**
     * Arch-declared list of column names the toolbar search bar should
     * `LIKE '%query%'` across. Empty array = no search bar rendered.
     * Strings only; non-string entries are dropped silently so a typo
     * in arch can't crash the view.
     *
     * @param array<string, mixed> $arch
     * @return list<string>
     */
    private static function parseSearchable(array $arch): array
    {
        $raw = $arch['searchable'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));
    }

    /**
     * Parse a list of named filter presets:
     * `[{name: string, label?: string, field: string, preset: string}, …]`.
     * Skips malformed entries and entries naming an unknown preset (the
     * engine treats those as "no filter available" rather than crashing).
     *
     * @param array<string, mixed> $arch
     * @return list<FilterDef>
     */
    private static function parseFilters(array $arch): array
    {
        $raw = $arch['filters'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = self::str($entry, 'name');
            $field = self::str($entry, 'field');
            $preset = self::str($entry, 'preset');

            if ($name === null || $field === null || $preset === null) {
                continue;
            }

            if (! DatePreset::isValid($preset)) {
                continue;
            }

            $filters[] = new FilterDef(
                name: $name,
                label: self::str($entry, 'label') ?? ucfirst(str_replace('_', ' ', $name)),
                field: $field,
                preset: $preset,
            );
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $arch
     * @return list<FormFieldDef>
     */
    private static function parseFormFields(array $arch): array
    {
        $raw = $arch['fields'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $fields = [];

        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry['field']) || ! is_string($entry['field'])) {
                continue;
            }

            $widget = self::str($entry, 'widget') ?? 'text';
            $allowed = ['text', 'textarea', 'email', 'tel', 'number', 'checkbox', 'select', 'date', 'datetime', 'image', 'color'];

            $options = [];
            if (isset($entry['options']) && is_array($entry['options'])) {
                foreach ($entry['options'] as $opt) {
                    if (is_array($opt) && isset($opt['value']) && is_string($opt['value'])) {
                        $options[] = ['value' => $opt['value'], 'label' => self::str($opt, 'label') ?? $opt['value']];
                    } elseif (is_string($opt)) {
                        $options[] = ['value' => $opt, 'label' => $opt];
                    }
                }
            }

            $fields[] = new FormFieldDef(
                field: $entry['field'],
                label: self::str($entry, 'label') ?? ucfirst(str_replace('_', ' ', $entry['field'])),
                widget: in_array($widget, $allowed, true) ? $widget : 'text',
                required: ($entry['required'] ?? false) === true,
                placeholder: self::str($entry, 'placeholder'),
                options: $options,
                help: self::str($entry, 'help'),
                optionsSource: self::parseOptionsSource($entry),
                translatable: ($entry['translatable'] ?? false) === true,
            );
        }

        return $fields;
    }

    /**
     * Parse `optionsFrom` (a model-sourced select). Defensive: only a
     * real Eloquent model class yields a source, else null (→ static).
     *
     * @param array<string, mixed> $entry
     */
    private static function parseOptionsSource(array $entry): ?DynamicOptions
    {
        $src = $entry['optionsFrom'] ?? null;

        if (! is_array($src)) {
            return null;
        }

        $model = $src['model'] ?? null;

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $model */
        return new DynamicOptions(
            model: $model,
            valueField: self::str($src, 'value') ?? 'id',
            labelField: self::str($src, 'label') ?? 'name',
            orderBy: self::str($src, 'orderBy'),
            excludeSelf: ($src['excludeSelf'] ?? false) === true,
        );
    }

    /**
     * @param array<string, mixed> $arch
     * @return list<ColumnDef>
     */
    private static function parseColumns(array $arch): array
    {
        $raw = $arch['columns'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $columns = [];

        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry['field']) || ! is_string($entry['field'])) {
                continue;
            }

            $align = self::str($entry, 'align') ?? 'left';
            $format = self::str($entry, 'format') ?? 'text';

            $columns[] = new ColumnDef(
                field: $entry['field'],
                label: self::str($entry, 'label') ?? ucfirst(str_replace('_', ' ', $entry['field'])),
                sortable: ($entry['sortable'] ?? true) !== false,
                sum: ($entry['sum'] ?? false) === true,
                avg: ($entry['avg'] ?? false) === true,
                align: in_array($align, ['left', 'right', 'center'], true) ? $align : 'left',
                format: in_array($format, ['text', 'number', 'money', 'date', 'datetime', 'badge', 'bool', 'toggle'], true)
                    ? $format
                    : 'text',
                sortField: self::str($entry, 'sort_field'),
                hiddenByDefault: ($entry['hidden_by_default'] ?? false) === true,
            );
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $arch
     * @return list<array{field: string, dir: 'asc'|'desc'}>
     */
    private static function parseSort(array $arch): array
    {
        $raw = $arch['default_sort'] ?? [];

        // Allow a single {field,dir} object or a list of them.
        if (is_array($raw) && isset($raw['field'])) {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        $sorts = [];

        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry['field']) || ! is_string($entry['field'])) {
                continue;
            }

            $dir = ($entry['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $sorts[] = ['field' => $entry['field'], 'dir' => $dir];
        }

        return $sorts;
    }

    /**
     * @param array<string, mixed> $arch
     * @return list<array{value: string, label: string}>
     */
    private static function parseStages(array $arch): array
    {
        $raw = $arch['stages'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $stages = [];

        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['value']) && is_string($entry['value'])) {
                $stages[] = [
                    'value' => $entry['value'],
                    'label' => self::str($entry, 'label') ?? $entry['value'],
                ];
            } elseif (is_string($entry)) {
                $stages[] = ['value' => $entry, 'label' => $entry];
            }
        }

        return $stages;
    }

    /**
     * @param array<string, mixed> $arch
     */
    private static function parseCard(array $arch): ?KanbanCard
    {
        $raw = $arch['card'] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $title = self::str($raw, 'title');

        if ($title === null) {
            return null;
        }

        $badges = [];

        if (isset($raw['badges']) && is_array($raw['badges'])) {
            $badges = array_values(array_filter(
                $raw['badges'],
                static fn (mixed $b): bool => is_string($b) && $b !== '',
            ));
        }

        $image = self::str($raw, 'image');

        $meta = [];
        if (isset($raw['meta']) && is_array($raw['meta'])) {
            foreach ($raw['meta'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $field = self::str($entry, 'field');
                if ($field === null) {
                    continue;
                }
                $format = self::str($entry, 'format');
                $meta[] = [
                    'field' => $field,
                    'label' => self::str($entry, 'label'),
                    // Whitelist the formats the kanban Blade knows how to render.
                    'format' => in_array($format, ['money', 'number', 'date', 'datetime', 'bool'], true)
                        ? $format
                        : null,
                ];
            }
        }

        return new KanbanCard($title, self::str($raw, 'subtitle'), $badges, $image, $meta);
    }

    /**
     * @param array<string, mixed> $arch
     */
    private static function parseRotting(array $arch): ?RottingRule
    {
        $raw = $arch['rotting'] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $field = self::str($raw, 'field');

        if ($field === null) {
            return null;
        }

        return new RottingRule($field, self::int($raw, 'days', 7));
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
