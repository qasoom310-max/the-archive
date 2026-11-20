<?php

declare(strict_types=1);

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An order placed on the website, waiting in the inbox.
 *
 * Prices on it are the ERP's own, worked out when it arrived — never what the
 * website said — so a tampered basket cannot set its own price. Nothing here
 * touches stock or money: that happens when staff accept it and it becomes a
 * normal pay-on-delivery POS order ({@see \Modules\Storefront\Services\StorefrontOrderAcceptor}).
 *
 * @property int $id
 * @property string $source_reference
 * @property string $status  new | accepted | rejected
 * @property string $customer_name
 * @property string $customer_phone
 * @property string $delivery_address
 * @property string|null $notes
 * @property list<array{product_id: int, name: string, qty: int, unit_price: float, tax_rate: float, total: float}> $lines
 * @property float $subtotal
 * @property float $delivery_charge
 * @property float $total
 * @property int|null $pos_order_id
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property string|null $reject_reason
 * @property Carbon|null $created_at
 */
final class StorefrontOrder extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'storefront_orders';

    /** @var list<string> */
    protected $fillable = [
        'source_reference', 'status', 'customer_name', 'customer_phone', 'delivery_address', 'notes',
        'lines', 'subtotal', 'delivery_charge', 'total', 'pos_order_id', 'handled_by', 'handled_at', 'reject_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'subtotal' => 'float',
            'delivery_charge' => 'float',
            'total' => 'float',
            'pos_order_id' => 'integer',
            'handled_by' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }
}
