<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bill a driver photographed through his trip link, waiting for the office.
 *
 * @property int $id
 * @property int $driver_id
 * @property int|null $leg_id
 * @property string $photo_path  private disk
 * @property array<string, mixed>|null $draft  what the bill reader found, if anything
 * @property string $status  new | saved | rejected (| discarded, the earlier "set aside")
 * @property string|null $reject_reason  why the accountant rejected it
 * @property Carbon|null $told_at  when someone told the driver in person
 * @property string|null $told_by
 * @property int|null $expense_id
 * @property int|null $handled_by_user_id
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property-read LimoDriver|null $driver
 * @property-read LimoLeg|null $leg
 * @property-read LimoExpense|null $expense
 */
final class LimoDriverBill extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_SAVED = 'saved';

    public const STATUS_DISCARDED = 'discarded';

    /** Rejected with a reason; kept with the accountant until the driver is told in person. */
    public const STATUS_REJECTED = 'rejected';

    /** A link takes this many bills at most, so it cannot be used to flood the office. */
    public const MAX_PER_LINK = 20;

    protected $table = 'limo_driver_bills';

    /** @var list<string> */
    protected $fillable = ['driver_id', 'leg_id', 'photo_path', 'draft', 'status', 'expense_id', 'handled_by_user_id', 'handled_at', 'reject_reason', 'told_at', 'told_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_NEW];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_id' => 'integer',
            'leg_id' => 'integer',
            'draft' => 'array',
            'expense_id' => 'integer',
            'handled_by_user_id' => 'integer',
            'handled_at' => 'datetime',
            'told_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LimoDriver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(LimoDriver::class, 'driver_id');
    }

    /** @return BelongsTo<LimoLeg, $this> */
    public function leg(): BelongsTo
    {
        return $this->belongsTo(LimoLeg::class, 'leg_id');
    }

    /** @return BelongsTo<LimoExpense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(LimoExpense::class, 'expense_id');
    }

    /** The total the reader found on the bill, in its own currency, or null. */
    public function readTotal(): ?float
    {
        if (! is_array($this->draft) || ! is_numeric($this->draft['net_amount'] ?? null)) {
            return null;
        }

        $net = (float) $this->draft['net_amount'];
        $rate = (int) ($this->draft['vat_rate'] ?? 0);

        return round($net + $net * $rate / 100, 3);
    }

    /**
     * Reject the bill with the reason. Only a bill still waiting can be
     * rejected; a saved one is an expense already.
     */
    public function reject(string $reason, ?int $userId): bool
    {
        $reason = trim($reason);
        if ($this->status !== self::STATUS_NEW || $reason === '') {
            return false;
        }

        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'reject_reason' => mb_substr($reason, 0, 500),
            'handled_by_user_id' => $userId,
            'handled_at' => Carbon::now(),
        ])->save();

        return true;
    }
}
