<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * A list-view filter whose choices are loaded from a model at render
 * time (one chip per row). Sibling of {@see FilterDef}: where FilterDef
 * is date-preset based, this one expresses "filter by partner_id /
 * category_id / state column equals one of these dynamically-resolved
 * values."
 *
 * Declared in arch as `filters_dynamic[]`:
 *
 *   [
 *     'name'        => 'category',
 *     'label'       => 'Category',
 *     'field'       => 'pos_category_id',
 *     'optionsFrom' => [
 *         'model'   => PosCategory::class,
 *         'value'   => 'id',
 *         'label'   => 'name',
 *         'orderBy' => 'sequence',
 *     ],
 *   ]
 *
 * The engine looks up rows from `model`, picks the `value` and `label`
 * columns (defaults: `id` / `name`), and renders one chip per result
 * above the static-filter chip row. Picking a chip applies
 * `where($field, $value)` to both the paginated and aggregate queries.
 *
 * @phpstan-type OptionsFromArray array{
 *     model: class-string<\Illuminate\Database\Eloquent\Model>,
 *     value: string,
 *     label: string,
 *     orderBy?: string
 * }
 */
final readonly class DynamicFilterDef
{
    /**
     * @param OptionsFromArray $optionsFrom
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $field,
        public array $optionsFrom,
    ) {}
}
