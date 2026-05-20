<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $pos_order_id
 * @property int $pos_payment_method_id
 * @property float $amount
 * @property Carbon|null $paid_at
 */
final class PosPayment extends Model
{
    protected $table = 'pos_payments';

    /** @var list<string> */
    protected $fillable = ['pos_order_id', 'pos_payment_method_id', 'amount', 'paid_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    /**
     * @return BelongsTo<PosPaymentMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PosPaymentMethod::class, 'pos_payment_method_id');
    }
}
