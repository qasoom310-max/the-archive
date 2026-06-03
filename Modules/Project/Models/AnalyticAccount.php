<?php

declare(strict_types=1);

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A minimal analytic ("statistical") account — a cost/revenue bucket that
 * is NOT part of the financial general ledger. The system had no analytic
 * dimension before this module; this is the smallest faithful version of
 * Odoo's `account.analytic.account` so projects have somewhere to post
 * labour cost. If a Purchases/Expenses module later wants analytic
 * tagging too, promote this pair (account + line) into a shared concern.
 *
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property bool $active
 */
final class AnalyticAccount extends Model
{
    protected $table = 'analytic_accounts';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return HasMany<AnalyticLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AnalyticLine::class, 'analytic_account_id');
    }

    /**
     * Live managerial balance: Σ(amount). Costs are stored negative, so a
     * project that has only logged labour returns a negative number.
     */
    public function balance(): float
    {
        return round((float) $this->lines()->sum('amount'), 2);
    }
}
