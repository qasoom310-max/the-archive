<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Credit from a cancelled trip, spendable against future bookings.
 *
 * The balance is the issued amount minus every redemption, never a stored
 * "remaining" figure: a coupon is spent in pieces (100 BD might go 15, 15, 15…)
 * and a running total kept in two places is a running total that eventually
 * disagrees with itself. Redemption rows are the truth; this derives from them.
 *
 * @property int $id
 * @property string $code
 * @property int|null $limo_booking_id
 * @property int|null $limo_leg_id
 * @property string|null $leg_reference
 * @property int|null $customer_id
 * @property float $amount
 * @property Carbon|null $expires_at
 * @property string|null $note
 * @property int|null $user_id
 */
final class LimoCoupon extends Model
{
    protected $table = 'limo_coupons';

    /** How long credit stays good, from the booking that paid for it. */
    public const VALID_MONTHS = 12;

    /** @var list<string> */
    protected $fillable = [
        'code', 'limo_booking_id', 'limo_leg_id', 'leg_reference',
        'customer_id', 'amount', 'expires_at', 'note', 'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limo_booking_id' => 'integer',
            'limo_leg_id' => 'integer',
            'customer_id' => 'integer',
            'amount' => 'float',
            'expires_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<LimoCouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(LimoCouponRedemption::class, 'limo_coupon_id');
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /** What has been spent so far. */
    public function used(): float
    {
        return round((float) $this->redemptions()->sum('amount'), 3);
    }

    /** What is left to spend. */
    public function remaining(): float
    {
        return round(max(0.0, (float) $this->amount - $this->used()), 3);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Spendable right now: credit left, and still in date. */
    public function isUsable(): bool
    {
        return $this->remaining() > 0.001 && ! $this->isExpired();
    }

    /** active | used | expired — for the badge on the coupons page. */
    public function state(): string
    {
        if ($this->remaining() <= 0.001) {
            return 'used';
        }

        return $this->isExpired() ? 'expired' : 'active';
    }
}
