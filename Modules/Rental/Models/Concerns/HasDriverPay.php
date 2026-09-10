<?php

declare(strict_types=1);

namespace Modules\Rental\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * How a driver is paid. Shared because `Driver` and `LimoDriver` are two
 * doors onto ONE table (see {@see HasDriverLicence}) — pay arrangement is a
 * fact about the PERSON, not about which app dispatched them.
 *
 * The five commission rates are the office's own fixed pay scale, not a
 * suggestion. `PAY_TYPE_OPTIONS`/`commissionRateOptions()` feed the engine
 * form's `select` widgets, which derive an `in:` validation rule from
 * exactly the option values on offer (see `FormView::rules()`) — so a rate
 * outside the scale, or a pay type that isn't one of these two, can never be
 * saved, without this trait having to validate anything itself.
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

    /** The office's fixed commission scale — no other rate is ever offered. */
    public const COMMISSION_RATES = [25, 15, 10, 7, 5];

    /** @return list<array{value: string, label: string}> */
    public static function commissionRateOptions(): array
    {
        return array_map(
            static fn (int $rate): array => ['value' => (string) $rate, 'label' => $rate . '%'],
            self::COMMISSION_RATES,
        );
    }

    public function isCommissionDriver(): bool
    {
        return $this->pay_type === self::PAY_TYPE_COMMISSION;
    }

    /**
     * A commission rate left over from before a driver was switched back to
     * Company must not linger — it would sit on the record unseen (the form
     * hides nothing, but nobody thinks to check it) and mislead the next
     * person who reads it as still meaning something.
     */
    public static function bootHasDriverPay(): void
    {
        static::saving(static function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }

            if ($model->pay_type !== self::PAY_TYPE_COMMISSION) {
                $model->commission_rate = null;
            }
        });
    }
}
