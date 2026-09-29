<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A trip as the AI parsed it from a staff message: which fare, when, where,
 * and for whom. Built from untrusted tool input, so every field is coerced and
 * {@see problems()} says what is still missing before anything is proposed.
 *
 * The AMOUNT is deliberately not part of it: the price is always re-read from
 * the fare tables, never taken from what the model said.
 */
final class TripRequest
{
    public function __construct(
        public readonly string $service,
        public readonly string $car,
        public readonly string $option,
        public readonly bool $roundTrip,
        public readonly float $extraHours,
        public readonly ?CarbonImmutable $pickupAt,
        public readonly string $from,
        public readonly string $to,
        public readonly string $customerName,
        public readonly string $customerPhone,
        public readonly string $notes,
        public readonly string $companyReference,
        /** The company (corporate account) the trip is billed to; empty for a private customer. */
        public readonly string $company = '',
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $pickup = null;
        $raw = self::str($input, 'pickup_at');
        if ($raw !== '') {
            try {
                $pickup = CarbonImmutable::parse($raw, 'Asia/Bahrain');
            } catch (Throwable) {
                $pickup = null;
            }
        }

        return new self(
            service: self::str($input, 'service'),
            car: self::str($input, 'car'),
            option: self::str($input, 'option'),
            roundTrip: (bool) ($input['round_trip'] ?? false),
            extraHours: max(0.0, (float) (is_numeric($input['extra_hours'] ?? null) ? $input['extra_hours'] : 0)),
            pickupAt: $pickup,
            from: self::str($input, 'from'),
            to: self::str($input, 'to'),
            customerName: self::str($input, 'customer_name'),
            customerPhone: self::str($input, 'customer_phone'),
            notes: self::str($input, 'notes'),
            companyReference: self::str($input, 'company_reference'),
            company: self::str($input, 'company'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromInput($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'car' => $this->car,
            'option' => $this->option,
            'round_trip' => $this->roundTrip,
            'extra_hours' => $this->extraHours,
            'pickup_at' => $this->pickupAt?->format('Y-m-d\TH:i'),
            'from' => $this->from,
            'to' => $this->to,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'notes' => $this->notes,
            'company_reference' => $this->companyReference,
            'company' => $this->company,
        ];
    }

    /**
     * What is still missing to write this trip down.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $missing = [];
        foreach (['service' => $this->service, 'car' => $this->car, 'option' => $this->option, 'from' => $this->from, 'customer_name' => $this->customerName, 'customer_phone' => $this->customerPhone] as $field => $value) {
            if ($value === '') {
                $missing[] = $field;
            }
        }
        if ($this->pickupAt === null) {
            $missing[] = 'pickup_at';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
