<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Limousine\Support\DriverAliases;

/**
 * One old-system driver name, and what it means.
 *
 * Either `driver_id` (this name is that driver in the register) or `is_office`
 * (this name is the office, an owner's login or a system account, so no driver
 * is credited). Both empty means the name has been seen but not yet decided.
 *
 * @property int $id
 * @property string $alias
 * @property int|null $driver_id
 * @property bool $is_office
 * @property string|null $decided_by
 */
final class LimoDriverAlias extends Model
{
    protected $table = 'limo_driver_aliases';

    /** @var list<string> */
    protected $fillable = ['alias', 'driver_id', 'is_office', 'decided_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_office' => 'boolean'];
    }

    protected static function booted(): void
    {
        // The resolver holds the whole mapping for the length of a request.
        // A decision saved has to reach the screen that shows it, so writing a
        // row empties what was read before it.
        $forget = static function (): void {
            app(DriverAliases::class)->flush();
        };

        static::saved($forget);
        static::deleted($forget);
    }
}
