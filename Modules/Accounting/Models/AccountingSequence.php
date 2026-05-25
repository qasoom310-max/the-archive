<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal counter row — one per (prefix, year) — driving the human-
 * readable journal numbers ("MISC/2026/0001"). Never exposed in the UI;
 * read/written exclusively through
 * {@see \Modules\Accounting\Services\SequenceGenerator}.
 *
 * @property int $id
 * @property string $prefix
 * @property int $year
 * @property int $last_number
 */
final class AccountingSequence extends Model
{
    protected $table = 'accounting_sequences';

    /** @var list<string> */
    protected $fillable = ['prefix', 'year', 'last_number'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
