<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Auto-generates a human reference (e.g. "RO/00042") the first time a record is
 * created, from a per-model prefix plus the zero-padded id. Using models declare
 * their prefix via {@see referencePrefix()}.
 *
 * The hook coexists with a model's own booted()/boot{Trait} hooks — Laravel
 * calls every boot method — so a model can keep other creation logic.
 */
trait HasReference
{
    public static function bootHasReference(): void
    {
        static::created(static function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }

            $current = $model->getAttribute('reference');
            if ($current === null || $current === '') {
                $model->setAttribute(
                    'reference',
                    $model->referencePrefix() . '/' . str_pad((string) $model->getKey(), 5, '0', STR_PAD_LEFT),
                );
                $model->saveQuietly();
            }
        });
    }

    /** The short uppercase prefix for this model's reference, e.g. "RO". */
    abstract public function referencePrefix(): string;
}
