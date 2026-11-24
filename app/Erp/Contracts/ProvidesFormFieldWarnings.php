<?php

declare(strict_types=1);

namespace App\Erp\Contracts;

/**
 * Implemented by a model that wants to warn, under one of its engine FormView
 * fields, that the record is not in the state the field suggests. Computed
 * live from the SAVED record on every render, so it always reflects what the
 * database holds. Returns null for fields with nothing to say.
 *
 * Example: a POS product whose "Show in the online store" is on but which has
 * no photo, so the website will not list it.
 */
interface ProvidesFormFieldWarnings
{
    public function formFieldWarning(string $field): ?string;
}
