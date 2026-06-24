<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * POS product ⇄ WooCommerce product mapping (+ last sync state). One row per
 * POS product. `woo_id` is null until the first successful push, after which
 * edits PUT the same remote product.
 *
 * @property int $id
 * @property int $pos_product_id
 * @property int|null $woo_id
 * @property string|null $last_status
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
final class WooCommerceProductLink extends Model
{
    protected $table = 'woocommerce_product_links';

    /** @var list<string> */
    protected $fillable = [
        'pos_product_id', 'woo_id', 'last_status', 'last_error', 'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_product_id' => 'integer',
            'woo_id' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function forProduct(int $posProductId): self
    {
        return self::query()->firstOrNew(['pos_product_id' => $posProductId]);
    }
}
