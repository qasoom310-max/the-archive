<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spend against a coupon: how much of it went on which booking.
 *
 * Rows rather than a running total, so a coupon can answer where its money went
 * — 100 BD spread across three trips is three rows, and the balance falls out of
 * summing them.
 *
 * @property int $id
 * @property int $limo_coupon_id
 * @property int|null $limo_booking_id
 * @property string|null $booking_reference
 * @property float $amount
 * @property int|null $user_id
 */
final class LimoCouponRedemption extends Model
{
    protected $table = 'limo_coupon_redemptions';

    /** @var list<string> */
    protected $fillable = [
        'limo_coupon_id', 'limo_booking_id', 'booking_reference', 'amount', 'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limo_coupon_id' => 'integer',
            'limo_booking_id' => 'integer',
            'amount' => 'float',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LimoCoupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(LimoCoupon::class, 'limo_coupon_id');
    }
}
