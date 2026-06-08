<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Pos\Enums\PrepStatus;

/**
 * @property int $id
 * @property int $pos_order_id
 * @property int|null $pos_product_id
 * @property string $name
 * @property string|null $notes
 * @property float $qty
 * @property float $unit_price
 * @property float $discount
 * @property float $tax_rate
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $total
 * @property list<array{id: int, name: string, price: float}>|null $condiments
 * @property PrepStatus|null $prep_status
 * @property Carbon|null $prep_sent_at
 * @property Carbon|null $prep_started_at
 * @property Carbon|null $prep_ready_at
 * @property Carbon|null $prep_completed_at
 */
final class PosOrderLine extends Model
{
    protected $table = 'pos_order_lines';

    /** @var list<string> */
    protected $fillable = [
        'pos_order_id', 'pos_product_id', 'name', 'notes', 'condiments',
        'qty', 'unit_price', 'discount', 'tax_rate',
        'subtotal', 'tax_amount', 'total',
        'prep_status', 'prep_sent_at', 'prep_started_at',
        'prep_ready_at', 'prep_completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_price' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'subtotal' => 'float',
            'tax_amount' => 'float',
            'total' => 'float',
            'condiments' => 'array',
            'prep_status' => PrepStatus::class,
            'prep_sent_at' => 'datetime',
            'prep_started_at' => 'datetime',
            'prep_ready_at' => 'datetime',
            'prep_completed_at' => 'datetime',
        ];
    }

    /**
     * Advance the KDS state machine and stamp the matching transition
     * timestamp. Idempotent for the terminal state (`completed`) — calling
     * `advance()` on a Completed line is a no-op so the kitchen can hammer
     * the button without consequence.
     */
    public function advancePrep(): void
    {
        $current = $this->prep_status;

        if ($current === null) {
            return;
        }

        $next = $current->next();

        if ($next === null) {
            return;
        }

        $now = Carbon::now();
        $this->prep_status = $next;

        match ($next) {
            PrepStatus::Preparing => $this->prep_started_at = $now,
            PrepStatus::Ready => $this->prep_ready_at = $now,
            PrepStatus::Completed => $this->prep_completed_at = $now,
            default => null,
        };

        $this->save();
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    /**
     * @return BelongsTo<PosProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    /**
     * Per-unit surcharge from the attached condiments (sum of their prices).
     * Multiplied by qty in {@see recompute()} — 2 burgers with extra cheese
     * pay for cheese twice.
     */
    public function condimentsSurcharge(): float
    {
        $sum = 0.0;

        foreach ($this->condiments ?? [] as $condiment) {
            $sum += (float) ($condiment['price'] ?? 0);
        }

        return round($sum, 2);
    }

    /**
     * Recompute money fields from qty / price / condiments / discount / tax.
     */
    public function recompute(): void
    {
        $gross = $this->qty * ($this->unit_price + $this->condimentsSurcharge());
        $net = $gross * (1 - $this->discount / 100);

        $this->subtotal = round($net, 2);
        $this->tax_amount = round($net * $this->tax_rate / 100, 2);
        $this->total = round($this->subtotal + $this->tax_amount, 2);
    }
}
