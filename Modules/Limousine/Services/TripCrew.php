<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Activity\ActivityLogger;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Vehicle;

/**
 * Puts a car and a driver on one trip — the rules the Bookings list's assign
 * dialog and the staff app share, so neither can do what the other refuses.
 *
 * Car and driver are resolved independently: an unknown id clears that one
 * field rather than being stored as a dangling reference, and none
 * un-assigns. Naming a driver IS dispatching: a queued or confirmed trip with a
 * car and a driver goes Active — and one being dispatched without both is
 * refused, saying which is missing.
 */
final class TripCrew
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly CustomerDocumentEmails $emails,
    ) {
    }

    /**
     * @param  string|null  $pay  the driver's commission for this trip, as typed ('' = none); null = the
     *                           caller has no pay field (the staff app): the amount already agreed stays
     *                           while the driver does
     * @param  bool  $startRequested  "Start trip" was pressed: going out even before a driver is named
     * @param  array{company: string, car: string, cost: string}|false|null  $outside  another company's car
     *                           (our driver drives it); false = one of ours (clears any other company's car);
     *                           null = the caller has no such choice (the staff app): a trip already in
     *                           another company's car keeps it unless a fleet car is picked
     * @return array{errors: array<'car'|'driver'|'pay'|'outside_company'|'outside_car'|'outside_cost', string>, started: bool}
     */
    public function assign(LimoLeg $leg, ?int $carId, ?int $driverId, ?string $pay = '', bool $startRequested = false, array|false|null $outside = null): array
    {
        $crewBefore = $leg->crewKey();

        if (is_array($outside)) {
            // Another company's car: no fleet car, the company and the car as
            // typed, and what we pay them for it.
            $company = trim($outside['company']);
            $model = trim($outside['car']);
            $cost = trim($outside['cost']);
            $errors = [];
            if ($company === '') {
                $errors['outside_company'] = __('Type the company whose car it is.');
            } elseif (mb_strlen($company) > 150) {
                $errors['outside_company'] = __('Keep the company name under 150 characters.');
            }
            if ($model === '') {
                $errors['outside_car'] = __('Type the car, so the customer knows what to look for.');
            } elseif (mb_strlen($model) > 150) {
                $errors['outside_car'] = __('Keep the car under 150 characters.');
            }
            if ($cost !== '' && (! is_numeric($cost) || (float) $cost < 0 || (float) $cost > 100000)) {
                $errors['outside_cost'] = __('Enter the cost as an amount in BD, or leave it blank.');
            }
            if ($errors !== []) {
                return ['errors' => $errors, 'started' => false];
            }

            $leg->car_id = null;
            $leg->vehicle = $model;
            $leg->outside_company = $company;
            $leg->outside_cost = $cost !== '' ? round((float) $cost, 3) : null;
        } else {
            $car = $carId !== null ? Vehicle::query()->find($carId) : null;
            $keepOutside = $outside === null && $car === null && $leg->hasOutsideCar();
            if (! $keepOutside) {
                $leg->car_id = $car?->id;
                $leg->vehicle = $car?->displayName();
                $leg->outside_company = null;
                $leg->outside_cost = null;
            }
        }

        $driver = $driverId !== null ? LimoDriver::query()->find($driverId) : null;

        // The licence is checked HERE as well as hidden from the picker: a
        // dropped option is a courtesy, not a rule, and a trip must not go out
        // behind an expired licence because somebody kept a stale page open.
        if ($driver !== null && ! $driver->canBeDispatched()) {
            $leg->save();

            return ['errors' => ['driver' => (string) $driver->dispatchBlockReason()], 'started' => false];
        }

        // The driver's commission for this trip, optional, agreed when the trip
        // is given to them. An amount for a driver on the company's books makes
        // them a commission driver on their record (the owner's rule), with this
        // as their usual amount. No driver, no commission.
        if ($pay === null) {
            // Not asked (the staff app has no pay field): the amount agreed with
            // this driver stands — and the driver's record is left as it is. A
            // different driver starts with none; it was agreed with someone else.
            if ($driver === null || $driver->id !== $leg->driver_id) {
                $leg->driver_payment = null;
            }
        } elseif ($driver !== null && trim($pay) !== '') {
            $pay = trim($pay);
            if (! is_numeric($pay) || (float) $pay < 0 || (float) $pay > 100000) {
                $leg->save();

                return ['errors' => ['pay' => __('Enter the payment as an amount in BD, or leave it blank.')], 'started' => false];
            }
            $leg->driver_payment = round((float) $pay, 3);
            $this->makeCommissionDriver($driver, (float) $leg->driver_payment);
        } else {
            $leg->driver_payment = null;
        }

        $leg->driver_id = $driver?->id;
        $leg->driver = $driver?->displayName();

        // Only from queue/confirmed: editing the driver on a leg that already
        // ran must not drag a completed or cancelled trip back to Active.
        $dispatchable = in_array($leg->status, [LimoLeg::STATUS_QUEUE, LimoLeg::STATUS_CONFIRMED], true);
        $started = false;

        // Either a driver was named (which is itself the act of dispatching) or
        // Start trip was pressed. Both mean the same thing: this trip goes out.
        $goingOut = $driver !== null || $startRequested;

        if ($goingOut && $dispatchable) {
            // A trip needs a car and someone to drive it — both, or it does not
            // leave. Whichever is missing is said, so it can be picked there.
            if (! $leg->hasCar()) {
                $leg->save();

                return ['errors' => ['car' => __('Pick the car — a trip cannot go out without one.')], 'started' => false];
            }

            if ($driver === null) {
                $leg->save();

                return ['errors' => ['driver' => __('Pick the driver — a trip cannot go out without one.')], 'started' => false];
            }

            $leg->status = LimoLeg::STATUS_ACTIVE;
            $started = true;
        }

        $leg->save();

        if ($started && $leg->legable instanceof LimoBooking) {
            // The booking summarises its legs, so it follows them up.
            $leg->legable->syncStatusFromLegs();
        }

        // The customer is emailed their driver and car the moment a trip has
        // both: once per pair, so a later change of car or driver sends the new
        // details and re-saving the same two sends nothing. Never for a trip
        // that is over or already past its pick-up (dispatch typed in after the
        // fact is record-keeping, not news to the customer).
        if ($leg->hasCarAndDriver()
            && $leg->crewKey() !== $crewBefore
            && $leg->legable instanceof LimoBooking
            && in_array($leg->status, LimoLeg::OPEN_STATUSES, true)
            && ($leg->start_at === null || $leg->start_at->isFuture())) {
            $this->emails->driverAssigned((int) $leg->id);
        }

        return ['errors' => [], 'started' => $started];
    }

    /**
     * Cars offerable for a trip: everything free in the Rent A Car fleet,
     * plus whatever is already on it, so an existing choice does not vanish
     * from its own list once the car is marked rented.
     *
     * @return list<array{value: int, label: string}>
     */
    public function carOptions(?int $current = null): array
    {
        $ids = Vehicle::query()->where('active', true)
            ->where('status', Vehicle::STATUS_AVAILABLE)->pluck('id')->all();
        if ($current !== null) {
            $ids[] = $current;
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return Vehicle::query()->whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'color', 'is_outside'])
            ->map(fn (Vehicle $v): array => [
                'value' => $v->id,
                'label' => $v->displayName() . ($v->is_outside ? ' · ' . __('Outside') : ''),
            ])
            ->all();
    }

    /**
     * Drivers offerable for a trip: everyone active whose licence lets them
     * drive, plus whoever is already on it (said to be expired, so a refused
     * save makes sense rather than the name silently vanishing).
     *
     * @return list<array{value: int, label: string}>
     */
    public function driverOptions(?int $current = null): array
    {
        $query = LimoDriver::query()->where('active', true);
        if ($current !== null) {
            $query->orWhere('id', $current);
        }

        return $query->orderBy('name')->get()
            ->filter(static fn (LimoDriver $d): bool => $d->canBeDispatched() || $d->id === $current)
            ->map(static fn (LimoDriver $d): array => [
                'value' => $d->id,
                'label' => $d->licenceExpired()
                    ? $d->displayName() . ' · ' . __('licence expired')
                    : $d->displayName(),
            ])
            ->values()
            ->all();
    }

    /**
     * A commission given to a company driver moves them onto commission, with
     * that figure as their usual amount. A commission driver with no usual
     * amount yet takes this one; one who has an amount keeps it (this trip's
     * figure is a one-off).
     */
    private function makeCommissionDriver(LimoDriver $driver, float $amount): void
    {
        if ($driver->isCommissionDriver() && $driver->commission_amount !== null) {
            return;
        }

        $wasCompany = ! $driver->isCommissionDriver();
        $driver->forceFill(['pay_type' => LimoDriver::PAY_TYPE_COMMISSION, 'commission_amount' => $amount])->save();

        $this->activity->log(
            'updated',
            __('Driver').' '.$driver->displayName(),
            $wasCompany
                ? __('Now a commission driver (:amount BD), set when assigning a trip.', ['amount' => self::plainAmount($amount)])
                : __('Usual commission set to :amount BD when assigning a trip.', ['amount' => self::plainAmount($amount)]),
        );
    }

    /** "15.000" → "15", "12.500" → "12.5": what a person would have typed. */
    private static function plainAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.');
    }
}
