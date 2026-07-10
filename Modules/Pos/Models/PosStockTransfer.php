<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A move of a product's finished stock between the STORE (back stock, where
 * production lands) and the SHOP (display stock the register sells from). Logged
 * with who / when / session for accountability.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $pos_product_id
 * @property int|null $pos_session_id
 * @property float $quantity
 * @property string $direction
 * @property string|null $notes
 * @property int|null $moved_by_user_id
 * @property-read PosProduct|null $product
 */
final class PosStockTransfer extends Model
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'pos_stock_transfers';

    public const STORE_TO_SHOP = 'store_to_shop';

    public const SHOP_TO_STORE = 'shop_to_store';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'pos_product_id', 'pos_session_id', 'quantity',
        'direction', 'notes', 'moved_by_user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['direction' => self::STORE_TO_SHOP];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pos_product_id' => 'integer',
            'pos_session_id' => 'integer',
            'quantity' => 'float',
            'moved_by_user_id' => 'integer',
        ];
    }

    public function referencePrefix(): string
    {
        return 'TRF';
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    /** Apply the move to the product's two stock buckets. */
    public function applyMove(): void
    {
        $product = $this->product;
        if ($product === null) {
            return;
        }

        $qty = (float) $this->quantity;
        if ($this->direction === self::SHOP_TO_STORE) {
            $product->stock_on_hand = max(0.0, (float) $product->stock_on_hand - $qty);
            $product->store_stock = (float) $product->store_stock + $qty;
        } else {
            $product->store_stock = max(0.0, (float) $product->store_stock - $qty);
            $product->stock_on_hand = (float) $product->stock_on_hand + $qty;
        }

        $product->save();
    }
}
