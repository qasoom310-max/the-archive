<?php

declare(strict_types=1);

namespace App\Erp\Translation;

/**
 * Marker contract for an Eloquent model that uses
 * `Spatie\Translatable\HasTranslations`. Spatie doesn't ship an interface
 * for its trait, which leaves PHPStan blind to `getTranslations()` and
 * `setTranslations()` at call sites that only have a base `Model` in
 * hand (e.g. `FormView::save()`, which operates on `class-string<Model>`).
 *
 * Models that opt into translations implement this *in addition to*
 * `use HasTranslations`. The interface declares only the two methods
 * the engine actually calls — keeps the contract narrow and easy to
 * verify against Spatie's source if it ever evolves.
 */
interface TranslatableModel
{
    /**
     * @param  list<string>|null  $allowedLocales
     * @return array<string, string>
     */
    public function getTranslations(?string $key = null, ?array $allowedLocales = null): array;

    /**
     * @param  array<string, string>  $translations
     */
    public function setTranslations(string $key, array $translations): self;
}
