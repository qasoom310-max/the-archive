<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Pos\Enums\SettlementState;

/**
 * One payout from the delivery company (or one confirmed bank transfer): the
 * money for a batch of delivered orders finally arriving in our account.
 *
 * `expected_amount` is snapshotted when the payout is requested — the orders'
 * collected total MINUS the delivery fees the company deducts before remitting.
 * `received_amount` is what actually landed. `difference` is the gap, so a short
 * or wrong transfer is visible rather than silently accepted.
 *
 * @property int $id
 * @property string $reference
 * @property SettlementState $state
 * @property Carbon|null $requested_at
 * @property Carbon|null $received_at
 * @property float $collected_total   What the company collected from customers
 * @property float $fees_deducted     Delivery fees they keep before remitting
 * @property float $expected_amount   collected_total − fees_deducted
 * @property float $received_amount   What actually landed in our account
 * @property float $difference        received − expected (negative = short)
 * @property string|null $method
 * @property string|null $note
 * @property int|null $user_id
 */
final class PosSettlement extends Model
{
    protected $table = 'pos_settlements';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'state', 'requested_at', 'received_at',
        'collected_total', 'fees_deducted', 'expected_amount',
        'received_amount', 'difference', 'method', 'note', 'user_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'requested',
        'collected_total' => 0,
        'fees_deducted' => 0,
        'expected_amount' => 0,
        'received_amount' => 0,
        'difference' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => SettlementState::class,
            'requested_at' => 'datetime',
            'received_at' => 'datetime',
            'collected_total' => 'float',
            'fees_deducted' => 'float',
            'expected_amount' => 'float',
            'received_amount' => 'float',
            'difference' => 'float',
            'user_id' => 'integer',
        ];
    }

    /**
     * The orders whose money this payout covers.
     *
     * @return HasMany<PosOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'pos_settlement_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isReceived(): bool
    {
        return $this->state === SettlementState::Received;
    }

    /** The amount short (positive) when they sent less than we asked for. */
    public function shortfall(): float
    {
        return $this->difference < 0 ? round(abs($this->difference), 3) : 0.0;
    }

    /** Whether what arrived differs from what we asked for (needs a look). */
    public function hasDiscrepancy(): bool
    {
        return $this->isReceived() && abs($this->difference) >= 0.001;
    }
}
