<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agreed price for one (option, car) pair for a company — or, with no
 * customer, the standard corporate rate every company gets. Never published
 * to the website.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int $option_id
 * @property string $car_id
 * @property float $amount
 */
final class PricingCorporateRate extends Model
{
    protected $table = 'pricing_corporate_rates';

    /** @var list<string> */
    protected $fillable = ['customer_id', 'option_id', 'car_id', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'option_id' => 'integer', 'amount' => 'float'];
    }

    /**
     * @return BelongsTo<PricingOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PricingOption::class, 'option_id');
    }

    /**
     * The agreed amount for this company, falling back to the standard
     * corporate rate. Null when neither is set.
     *
     * @return array{amount: float, source: string}|null
     */
    public static function lookup(int $companyId, int $optionId, string $carId): ?array
    {
        $rows = self::query()
            ->where('option_id', $optionId)
            ->where('car_id', $carId)
            ->where(fn ($q) => $q->where('customer_id', $companyId)->orWhereNull('customer_id'))
            ->get();

        $own = $rows->firstWhere('customer_id', $companyId);
        if ($own !== null && $own->amount > 0) {
            return ['amount' => $own->amount, 'source' => 'corporate'];
        }

        $standard = $rows->first(static fn (self $r): bool => $r->customer_id === null);
        if ($standard !== null && $standard->amount > 0) {
            return ['amount' => $standard->amount, 'source' => 'corporate_standard'];
        }

        return null;
    }
}
