<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A petty-cash spending category — the owner's list, editable from the page.
 *
 * `expense_category` names the slot in the expense ledger's fixed taxonomy a
 * settled line files under. Null (an owner-invented category) files under
 * "other", carrying its own name in the expense's notes so nothing is lost.
 *
 * @property int $id
 * @property string $name
 * @property string|null $expense_category
 */
final class LimoPettyCategory extends Model
{
    protected $table = 'limo_petty_categories';

    /** @var list<string> */
    protected $fillable = ['name', 'expense_category'];

    /** The ledger slot for a category NAME, for use at settlement. */
    public static function expenseSlotFor(string $name): string
    {
        $slot = self::query()->where('name', $name)->value('expense_category');

        return is_string($slot) && $slot !== '' ? $slot : 'other';
    }
}
