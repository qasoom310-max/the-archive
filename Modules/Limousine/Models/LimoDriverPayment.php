<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Models\Concerns\HasReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money paid to an outside driver. Cancelled, never deleted.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $driver_id
 * @property float $amount
 * @property Carbon $paid_on
 * @property string $method
 * @property int|null $requested_by_user_id
 * @property int|null $recorded_by_user_id
 * @property string|null $note
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property-read LimoDriver|null $driver
 * @property-read User|null $requestedBy
 * @property-read User|null $recordedBy
 * @property-read User|null $cancelledBy
 */
final class LimoDriverPayment extends Model
{
    use HasReference;

    protected $table = 'limo_driver_payments';

    /** @var list<string> */
    public const METHODS = ['cash', 'transfer', 'benefit'];

    /** @var list<string> */
    protected $fillable = ['driver_id', 'amount', 'paid_on', 'method', 'requested_by_user_id', 'recorded_by_user_id', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_id' => 'integer',
            'amount' => 'float',
            'paid_on' => 'date',
            'requested_by_user_id' => 'integer',
            'recorded_by_user_id' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by_user_id' => 'integer',
        ];
    }

    public function referencePrefix(): string
    {
        return 'DPAY';
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** @return BelongsTo<LimoDriver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(LimoDriver::class, 'driver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
