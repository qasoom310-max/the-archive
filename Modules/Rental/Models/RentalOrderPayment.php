<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated change to a rental order's Advance. Written by the order itself
 * whenever the Advance moves, so no screen can forget it.
 *
 *   payment     money taken (cash, card, online…) — counts as money in
 *   correction  the Advance was lowered (a typo, a refund) — counts as minus
 *   credit      coupon credit — NOT money, never counted as money in
 *
 * @property int $id
 * @property int $order_id
 * @property Carbon|null $date
 * @property float $amount
 * @property string $kind
 * @property string|null $method
 * @property string|null $taken_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property-read RentalOrder|null $order
 */
final class RentalOrderPayment extends Model
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_CORRECTION = 'correction';

    public const KIND_CREDIT = 'credit';

    /** Kinds that are real money moving (in, or back out). */
    public const MONEY_KINDS = [self::KIND_PAYMENT, self::KIND_CORRECTION];

    protected $table = 'rental_order_payments';

    /** @var list<string> */
    protected $fillable = ['order_id', 'date', 'amount', 'kind', 'method', 'taken_by', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['order_id' => 'integer', 'date' => 'date', 'amount' => 'float'];
    }

    /**
     * @return BelongsTo<RentalOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(RentalOrder::class, 'order_id');
    }

    /** Money received through rental orders on a date (credit left out). */
    public static function moneyOn(string $date): float
    {
        return round((float) self::query()
            ->whereIn('kind', self::MONEY_KINDS)
            ->whereDate('date', $date)
            ->whereIn('order_id', RentalOrder::query()->select('id'))
            ->sum('amount'), 3);
    }
}
