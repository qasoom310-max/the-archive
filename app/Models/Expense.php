<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring operating-expense template — a usual monthly bill (Rent, EWA,
 * SIO, LMRA, salaries…). Defined once with its usual {@see $amount}; the real
 * amount paid each month is recorded as an {@see ExpensePayment}.
 *
 * @property int $id
 * @property string $name
 * @property string $category
 * @property float $amount
 * @property int|null $due_day
 * @property bool $active
 * @property int $sequence
 */
final class Expense extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'category', 'amount', 'due_day', 'active', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'other',
        'amount' => 0,
        'active' => true,
        'sequence' => 0,
    ];

    /**
     * Suggested categories for the form. EWA → utilities; SIO / LMRA →
     * government. Stored as the lowercase key.
     *
     * @var list<array{value: string, label: string}>
     */
    public const CATEGORIES = [
        ['value' => 'rent', 'label' => 'Rent'],
        ['value' => 'utilities', 'label' => 'Utilities (EWA, water…)'],
        ['value' => 'government', 'label' => 'Government (SIO, LMRA…)'],
        ['value' => 'salaries', 'label' => 'Salaries'],
        ['value' => 'supplies', 'label' => 'Supplies'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'due_day' => 'integer',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return HasMany<ExpensePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class, 'expense_id');
    }

    /**
     * The payment covering a given 'YYYY-MM' month, or null if not yet paid.
     */
    public function paymentForPeriod(string $period): ?ExpensePayment
    {
        return $this->payments()->where('period', $period)->first();
    }
}
