<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_cash
 * @property int $sequence
 * @property bool $active
 */
final class PosPaymentMethod extends Model
{
    protected $table = 'pos_payment_methods';

    /** @var list<string> */
    protected $fillable = ['name', 'is_cash', 'sequence', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'sequence' => 'integer',
            'active' => 'boolean',
        ];
    }
}
