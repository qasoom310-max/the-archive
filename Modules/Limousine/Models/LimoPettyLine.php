<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One paper receipt the driver handed back: what he spent it on, and proof.
 *
 * The categories are the things a driver actually pays for on the road. Not a
 * registry model — a line only means anything inside its advance.
 *
 * @property int $id
 * @property int $advance_id
 * @property Carbon|null $date
 * @property string $category
 * @property string|null $description
 * @property float $amount
 * @property string|null $photo_path
 * @property-read LimoPettyAdvance|null $advance
 */
final class LimoPettyLine extends Model
{
    protected $table = 'limo_petty_lines';

    /** @var list<string> */
    protected $fillable = ['advance_id', 'date', 'category', 'description', 'amount', 'photo_path'];

    /** @var array<string, mixed> */
    protected $attributes = ['category' => 'fuel', 'amount' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'advance_id' => 'integer',
            'date' => 'date',
            'amount' => 'float',
        ];
    }

    /**
     * What a driver spends petty cash on. Each maps 1:1 onto an expense
     * category, so a settled line lands in the expense ledger under the same
     * name it was entered as.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'fuel' => 'Fuel',
            'wash' => 'Car wash & cleaning',
            'parking' => 'Parking & tolls',
            'maintenance' => 'Maintenance',
            'other' => 'Other',
        ];
    }

    /**
     * @return BelongsTo<LimoPettyAdvance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(LimoPettyAdvance::class, 'advance_id');
    }
}
