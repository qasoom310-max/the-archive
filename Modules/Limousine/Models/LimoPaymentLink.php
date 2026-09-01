<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payment link ("partition") raised against a booking's trip (leg) and sent
 * to the customer through the Wanaan WordPress portal. A booking can have many:
 * a deposit, the balance, or one per leg — each settles the booking on its own
 * when Tap confirms it.
 *
 * The row id is the stable idempotency key the portal and its paid-callback
 * quote back (`erp_payment_id`), so re-sending a link updates rather than
 * duplicating and a replayed callback can't pay twice.
 *
 * @property int $id
 * @property int $leg_id
 * @property int $booking_id
 * @property float $amount
 * @property string $currency
 * @property string|null $token
 * @property string|null $url
 * @property string $status
 * @property int|null $woo_order_id
 * @property string|null $transaction_ref
 * @property Carbon|null $paid_at
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LimoBooking|null $booking
 * @property-read LimoLeg|null $leg
 */
final class LimoPaymentLink extends Model
{
    protected $table = 'limo_payment_links';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'leg_id', 'booking_id', 'amount', 'currency', 'token', 'url',
        'status', 'woo_order_id', 'transaction_ref', 'paid_at', 'created_by_user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'BHD',
        'status' => self::STATUS_UNPAID,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leg_id' => 'integer',
            'booking_id' => 'integer',
            'amount' => 'float',
            'woo_order_id' => 'integer',
            'paid_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LimoBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(LimoBooking::class, 'booking_id');
    }

    /**
     * @return BelongsTo<LimoLeg, $this>
     */
    public function leg(): BelongsTo
    {
        return $this->belongsTo(LimoLeg::class, 'leg_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
