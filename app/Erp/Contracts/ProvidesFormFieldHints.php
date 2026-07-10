<?php

declare(strict_types=1);

namespace App\Erp\Contracts;

/**
 * Implemented by a model that wants to show an extra, record-specific hint
 * under one of its engine FormView fields — computed live from the record
 * (unlike the static `help` baked into the view arch). Returns null for
 * fields with no hint.
 *
 * Example: a POS product whose cost is assembled from a recipe returns the
 * rolled-up "Recipe cost: 6.65 BD" under its Cost price field.
 */
interface ProvidesFormFieldHints
{
    public function formFieldHint(string $field): ?string;
}
