<?php

declare(strict_types=1);

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The online store's connection, one row per database.
 *
 * `shared_secret` is the HMAC key the website signs every request with (read
 * and order alike), stored through the `encrypted` cast. The endpoint stays
 * shut until the switch is on AND a secret exists.
 *
 * @property int $id
 * @property bool $enabled
 * @property string|null $shared_secret
 * @property string|null $whatsapp_number
 * @property float $delivery_charge      What the customer pays for delivery
 * @property float|null $free_delivery_over  Goods total from which delivery is free
 */
final class StorefrontConfiguration extends Model
{
    protected $table = 'storefront_configuration';

    /** @var list<string> */
    protected $fillable = ['enabled', 'shared_secret', 'whatsapp_number', 'delivery_charge', 'free_delivery_over'];

    /** @var list<string> */
    protected $hidden = ['shared_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'shared_secret' => 'encrypted',
            'delivery_charge' => 'float',
            'free_delivery_over' => 'float',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    public function isConfigured(): bool
    {
        return $this->enabled && (string) $this->shared_secret !== '';
    }

    /** The delivery charge for a goods total (free above the threshold). */
    public function deliveryChargeFor(float $goods): float
    {
        if ($this->free_delivery_over !== null && $this->free_delivery_over > 0 && $goods >= $this->free_delivery_over) {
            return 0.0;
        }

        return round(max(0.0, (float) $this->delivery_charge), 2);
    }
}
