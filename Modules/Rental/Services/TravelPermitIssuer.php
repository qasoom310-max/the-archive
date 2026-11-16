<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\TravelPermit;
use Modules\Rental\Models\Vehicle;

/**
 * Decides whether a travel authorization can be written, and for how long.
 *
 * The letter is handed to border officers, so it is only ever built from what
 * is on file, never typed in:
 *  - the car must have its plate, make, model, year, colour and a registration
 *    that has not run out;
 *  - the driver must have a personal number and a name of at least three
 *    parts (first, father's, family), as the officers expect;
 *  - the letter cannot outlive the car's registration: an end date past it is
 *    pulled back to the registration's last day, and the result says so.
 */
final class TravelPermitIssuer
{
    /** The periods on offer, in the owner's order. */
    public const PERIODS = ['1d', '3d', '7d', '15d', '1m', '6m', '1y'];

    public const OWNER_NAME = 'rental.permit.owner_name';

    public const OWNER_NO = 'rental.permit.owner_no';

    /**
     * @return array<string, string>
     */
    public static function periodLabels(): array
    {
        return [
            '1d' => __('1 day'),
            '3d' => __('3 days'),
            '7d' => __('7 days'),
            '15d' => __('15 days'),
            '1m' => __('1 month'),
            '6m' => __('6 months'),
            '1y' => __('1 year'),
        ];
    }

    /** Who the cars belong to, as printed on the letter. */
    public static function ownerName(): string
    {
        return trim((string) Setting::get(self::OWNER_NAME, 'Wanaan Tours and Rental Car'));
    }

    /** The owner's commercial registration number, as printed on the letter. */
    public static function ownerNo(): string
    {
        return trim((string) Setting::get(self::OWNER_NO, '135164-1'));
    }

    /**
     * What is missing on the car for a letter to be written. Empty = ready.
     *
     * @return list<string>
     */
    public function carProblems(Vehicle $car, ?Carbon $from = null): array
    {
        $problems = [];

        foreach ([
            'plate_no' => __('Plate no.'),
            'make' => __('Make'),
            'model' => __('Model'),
            'color' => __('Colour'),
        ] as $field => $label) {
            if (trim((string) $car->getAttribute($field)) === '') {
                $problems[] = __(':field is not filled in on the car.', ['field' => $label]);
            }
        }

        if ((int) $car->year < 1950) {
            $problems[] = __(':field is not filled in on the car.', ['field' => __('Year')]);
        }

        $expiry = $car->registration_expiry;
        if ($expiry === null) {
            $problems[] = __('The registration expiry date is not filled in on the car.');
        } elseif ($expiry->lt(($from ?? Carbon::today())->copy()->startOfDay())) {
            $problems[] = __('The car\'s registration ended on :date.', ['date' => $expiry->format('d/m/Y')]);
        }

        return $problems;
    }

    /**
     * What is missing on the driver. Empty = ready.
     *
     * @return list<string>
     */
    public function driverProblems(Driver $driver): array
    {
        $problems = [];

        if (count($this->nameParts((string) $driver->name)) < 3) {
            $problems[] = __('The driver\'s name must have three names (first, father\'s and family name).');
        }

        if (preg_match('/\d{5,}/', (string) $driver->cpr) !== 1) {
            $problems[] = __('The driver\'s CPR number is not filled in.');
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function nameParts(string $name): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], static fn (string $p): bool => $p !== ''));
    }

    /**
     * The last day a letter starting on $from would run to, and whether the
     * car's registration cut it short.
     *
     * @return array{to: Carbon, shortened: bool}
     */
    public function window(Vehicle $car, Carbon $from, string $period): array
    {
        $from = $from->copy()->startOfDay();

        $to = match ($period) {
            '1d' => $from->copy()->addDay(),
            '3d' => $from->copy()->addDays(3),
            '7d' => $from->copy()->addDays(7),
            '15d' => $from->copy()->addDays(15),
            '1m' => $from->copy()->addMonthNoOverflow(),
            '6m' => $from->copy()->addMonthsNoOverflow(6),
            default => $from->copy()->addYearNoOverflow(),
        };

        $expiry = $car->registration_expiry?->copy()->startOfDay();
        if ($expiry !== null && $to->gt($expiry)) {
            return ['to' => $expiry, 'shortened' => true];
        }

        return ['to' => $to, 'shortened' => false];
    }

    /**
     * Write the letter. Throws a ValidationException naming exactly what is
     * missing; nothing is saved unless everything is on file.
     */
    public function issue(Vehicle $car, Driver $driver, Carbon $issuedOn, Carbon $from, string $period, ?string $by = null): TravelPermit
    {
        $errors = [];

        if (! in_array($period, self::PERIODS, true)) {
            $errors['period'] = [__('Choose how long the authorization runs.')];
        }
        if (($problems = $this->carProblems($car, $from)) !== []) {
            $errors['vehicle_id'] = $problems;
        }
        if (($problems = $this->driverProblems($driver)) !== []) {
            $errors['driver_id'] = $problems;
        }
        if (self::ownerName() === '' || self::ownerNo() === '') {
            $errors['owner'] = [__('The owner\'s name and number for the letter are not set.')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $window = $this->window($car, $from, $period);

        return TravelPermit::query()->create([
            'vehicle_id' => $car->id,
            'driver_id' => $driver->id,
            'issued_on' => $issuedOn->toDateString(),
            'valid_from' => $from->toDateString(),
            'valid_to' => $window['to']->toDateString(),
            'period' => $period,
            'shortened' => $window['shortened'],
            'plate_no' => trim((string) $car->plate_no),
            'make' => trim((string) $car->make),
            'model' => trim((string) $car->model),
            'year' => (int) $car->year,
            'color' => trim((string) $car->color),
            'driver_name' => implode(' ', $this->nameParts((string) $driver->name)),
            'driver_cpr' => trim((string) $driver->cpr),
            'owner_name' => self::ownerName(),
            'owner_no' => self::ownerNo(),
            'created_by' => $by,
        ]);
    }
}
