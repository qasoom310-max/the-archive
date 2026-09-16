<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A picker list scoped to `active = true`, plus whichever record is already
 * SELECTED even if it has since been deactivated (a customer, vehicle,
 * driver or branch, later archived) — so opening an existing record for
 * editing never renders that field blank just because its relation was
 * deactivated afterwards. A brand-new selection is still only ever offered
 * the active ones; this only keeps an existing one visible.
 *
 * Mirrors the reasoning already applied to a leg's car in
 * {@see \Modules\Limousine\Livewire\Concerns\HandlesTripLegs::carOptions()}.
 */
trait ActiveOrSelected
{
    /**
     * Every model using this trait is `final`, so `self` and `static` are
     * always the same class — `self::query()` avoids PHPStan/Larastan's
     * late-static-binding vs. invariant-Collection-template mismatch that
     * `static::query()` triggers here (see the "not covariant" note at
     * https://phpstan.org/blog/whats-up-with-template-covariant).
     *
     * @param  list<string>  $columns
     * @return Collection<int, self>
     */
    public static function activeOrSelected(?int $selectedId, array $columns = ['*']): Collection
    {
        return self::query()
            ->where(function (Builder $query) use ($selectedId): void {
                $query->where('active', true);

                if ($selectedId !== null) {
                    $query->orWhere('id', $selectedId);
                }
            })
            ->orderBy('name')
            ->get($columns);
    }
}
