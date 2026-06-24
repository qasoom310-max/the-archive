<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One actual payment of a recurring {@see Expense} for a month. `period` is the
 * 'YYYY-MM' it covers (may differ from `paid_on` for a late payment); `amount`
 * is the real amount paid that month.
 *
 * @property int $id
 * @property int|null $expense_id
 * @property string $name
 * @property string $period
 * @property float $amount
 * @property Carbon $paid_on
 * @property string|null $notes
 */
final class ExpensePayment extends Model
{
    /** @var list<string> */
    protected $fillable = ['expense_id', 'name', 'period', 'amount', 'paid_on', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'paid_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }
}
