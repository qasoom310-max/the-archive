<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Models\Concerns\HasReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Several of one customer's invoices sent as one document.
 *
 * It holds no money of its own. Its total, what was paid and what is left are
 * always read from the invoices in it, so a payment taken on one of those
 * invoices directly shows here too and the two can never disagree.
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property Carbon|null $issue_date
 * @property Carbon|null $period_from
 * @property Carbon|null $period_to
 * @property Carbon|null $sent_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by_user_id
 * @property int|null $cancelled_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LimoCustomer|null $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LimoInvoice> $invoices
 */
final class LimoBulkInvoice extends Model
{
    use HasReference;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'limo_bulk_invoices';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'issue_date', 'period_from', 'period_to',
        'sent_at', 'cancelled_at', 'created_by_user_id', 'cancelled_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'issue_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_by_user_id' => 'integer',
            'cancelled_by_user_id' => 'integer',
        ];
    }

    public function referencePrefix(): string
    {
        return 'BINV';
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /**
     * @return HasMany<LimoInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(LimoInvoice::class, 'bulk_invoice_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function total(): float
    {
        return round((float) $this->invoices->sum('total'), 3);
    }

    public function paid(): float
    {
        return round((float) $this->invoices->sum('amount_paid'), 3);
    }

    public function balance(): float
    {
        return round($this->total() - $this->paid(), 3);
    }

    /** Unpaid, partial or paid, worked out from the invoices in it. */
    public function status(): string
    {
        if ($this->isCancelled()) {
            return self::STATUS_CANCELLED;
        }

        $paid = $this->paid();

        return match (true) {
            $paid > 0.0005 && $this->balance() <= 0.0005 => self::STATUS_PAID,
            $paid > 0.0005 => self::STATUS_PARTIAL,
            default => self::STATUS_UNPAID,
        };
    }
}
