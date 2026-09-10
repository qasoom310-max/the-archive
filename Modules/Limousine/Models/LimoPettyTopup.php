<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money put INTO the petty-cash float.
 *
 * The float's balance is always derived — top-ups in, advances and excess
 * reimbursements out — never stored, because a stored balance and the rows it
 * summarises always eventually disagree.
 *
 * @property int $id
 * @property Carbon|null $date
 * @property float $amount
 * @property string|null $added_by
 * @property string|null $notes
 */
final class LimoPettyTopup extends Model
{
    protected $table = 'limo_petty_topups';

    /** @var list<string> */
    protected $fillable = ['date', 'amount', 'added_by', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['amount' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'float'];
    }
}
