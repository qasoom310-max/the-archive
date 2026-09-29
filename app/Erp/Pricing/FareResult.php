<?php

declare(strict_types=1);

namespace App\Erp\Pricing;

/**
 * One priced trip from {@see FareCalculator}, or the reason there is no price.
 * `found = false` is a real answer: the caller says "no set fare", it never
 * substitutes a number.
 */
final class FareResult
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $reason,
        public readonly string $serviceId = '',
        public readonly string $serviceEn = '',
        public readonly string $serviceAr = '',
        public readonly string $carId = '',
        public readonly string $carEn = '',
        public readonly string $carAr = '',
        public readonly string $optionCode = '',
        public readonly string $optionEn = '',
        public readonly string $optionAr = '',
        public readonly ?int $hours = null,
        public readonly bool $roundTrip = false,
        public readonly float $extraHours = 0.0,
        public readonly float $base = 0.0,
        public readonly float $extraHoursAmount = 0.0,
        public readonly float $discountPercent = 0.0,
        public readonly float $discount = 0.0,
        public readonly float $total = 0.0,
        /** Where the price came from: `website`, `corporate` (this company's own deal) or `corporate_standard`. */
        public readonly string $source = self::SOURCE_WEBSITE,
    ) {
    }

    public const SOURCE_WEBSITE = 'website';

    public const SOURCE_CORPORATE = 'corporate';

    public const SOURCE_CORPORATE_STANDARD = 'corporate_standard';

    public function isCorporate(): bool
    {
        return $this->source !== self::SOURCE_WEBSITE;
    }

    public static function missing(string $reason): self
    {
        return new self(found: false, reason: $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'found' => $this->found,
            'reason' => $this->reason,
            'service' => $this->serviceId,
            'service_en' => $this->serviceEn,
            'service_ar' => $this->serviceAr,
            'car' => $this->carId,
            'car_en' => $this->carEn,
            'car_ar' => $this->carAr,
            'option' => $this->optionCode,
            'option_en' => $this->optionEn,
            'option_ar' => $this->optionAr,
            'hours' => $this->hours,
            'round_trip' => $this->roundTrip,
            'extra_hours' => $this->extraHours,
            'base' => $this->base,
            'extra_hours_amount' => $this->extraHoursAmount,
            'discount_percent' => $this->discountPercent,
            'discount' => $this->discount,
            'total' => $this->total,
            'source' => $this->source,
        ];
    }
}
