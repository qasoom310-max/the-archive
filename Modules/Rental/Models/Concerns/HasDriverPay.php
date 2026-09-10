<?php

declare(strict_types=1);

namespace Modules\Rental\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * How a driver is paid. Shared because `Driver` and `LimoDriver` are two
 * doors onto ONE table (see {@see HasDriverLicence}) — pay arrangement is a
 * fact about the PERSON, not about which app dispatched them.
 *
 * Commission is a flat amount in Bahraini Dinar (per trip), typed freely by
 * the admin — NOT a percentage and NOT restricted to a fixed scale (an
 * earlier version locked it to 25/15/10/7/5%; the office asked for an open
 * BD figure instead). `PAY_TYPE_OPTIONS` still whitelists the pay type via
 * the engine's `in:` rule (see `FormView::rules()`); the commission amount
 * itself is just a `number` widget (`nullable|numeric` — see the same
 * `rules()`), so any BD figure the admin types is accepted.
 */
trait HasDriverPay
{
    public const PAY_TYPE_COMPANY = 'company';

    public const PAY_TYPE_COMMISSION = 'commission';

    /** @var list<array{value: string, label: string}> */
    public const PAY_TYPE_OPTIONS = [
        ['value' => self::PAY_TYPE_COMPANY, 'label' => 'Company driver'],
        ['value' => self::PAY_TYPE_COMMISSION, 'label' => 'Commission driver'],
    ];

    public function isCommissionDriver(): bool
    {
        return $this->pay_type === self::PAY_TYPE_COMMISSION;
    }

    /**
     * A commission amount left over from before a driver was switched back
     * to Company must not linger — it would sit on the record unseen (the
     * form hides nothing, but nobody thinks to check it) and mislead the
     * next person who reads it as still meaning something.
     */
    public static function bootHasDriverPay(): void
    {
        static::saving(static function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }

            if ($model->pay_type !== self::PAY_TYPE_COMMISSION) {
                $model->commission_amount = null;
            }
        });
    }
}
