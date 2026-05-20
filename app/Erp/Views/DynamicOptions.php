<?php

declare(strict_types=1);

namespace App\Erp\Views;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares that a Form `select` field sources its options from a model
 * (a relation picker) instead of a static `options` list. Parsed from
 * `arch.fields[].optionsFrom` and resolved at render time by FormView.
 *
 * Generic on purpose: any module can point a select at any model
 * (e.g. a parent picker via `excludeSelf`) without bespoke components.
 */
final readonly class DynamicOptions
{
    /**
     * @param class-string<Model> $model
     */
    public function __construct(
        public string $model,
        public string $valueField = 'id',
        public string $labelField = 'name',
        public ?string $orderBy = null,
        public bool $excludeSelf = false,
    ) {}
}
