<?php

declare(strict_types=1);

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single analytic entry — one-sided and "statistical" (no contra line,
 * never touches the GL). `amount` is signed: negative = cost, positive =
 * revenue. `unit_amount` carries the quantity dimension (e.g. hours for a
 * timesheet). `source_ref` is the idempotency key of the originating
 * document, e.g. "project.timesheet:42", so re-syncing never duplicates.
 *
 * @property int $id
 * @property int $analytic_account_id
 * @property Carbon $date
 * @property string $name
 * @property float $amount
 * @property float $unit_amount
 * @property int|null $user_id
 * @property string|null $source_ref
 */
final class AnalyticLine extends Model
{
    protected $table = 'analytic_lines';

    /** @var list<string> */
    protected $fillable = [
        'analytic_account_id',
        'date',
        'name',
        'amount',
        'unit_amount',
        'user_id',
        'source_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'float',
            'unit_amount' => 'float',
            'analytic_account_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AnalyticAccount, $this>
     */
    public function analyticAccount(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class, 'analytic_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
