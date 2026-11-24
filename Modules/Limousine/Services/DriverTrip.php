<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoTripRun;
use Modules\Limousine\Support\DashboardReader;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * The rules of the driver's trip link.
 *
 * Start: a dashboard photo (read for KM and fuel, checked by the driver) and
 * his location → the trip is started, the car's KM moves up, the customer is
 * emailed that the driver is on the way. End: the same photo and location →
 * the trip is completed. One trip at a time: while a driver has a trip
 * started and not ended, he cannot start another.
 *
 * Nothing the driver types is trusted blindly: what does not add up is kept
 * as a flag for the office to see on the trip record, never silently fixed.
 */
final class DriverTrip
{
    /** A KM change bigger than this between readings is worth a look. */
    public const KM_JUMP = 1500;

    public function run(LimoLeg $leg, int $driverId): LimoTripRun
    {
        return LimoTripRun::query()->firstOrCreate(
            ['leg_id' => $leg->id, 'driver_id' => $driverId],
            ['car_id' => $leg->car_id],
        );
    }

    /** The trip this driver has started and not ended, other than this one. */
    public function blocking(LimoLeg $leg, int $driverId): ?LimoTripRun
    {
        return LimoTripRun::query()
            ->where('driver_id', $driverId)
            ->where('leg_id', '!=', $leg->id)
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->whereHas('leg', fn ($q) => $q->where('driver_id', $driverId)->where('status', '!=', LimoLeg::STATUS_CANCELLED))
            ->with('leg')
            ->first();
    }

    /**
     * Keep the photo and read it. The reading is only a suggestion: the
     * driver confirms or corrects it before starting / ending.
     *
     * @return array{readable: bool, km: int, fuel_eighths: int, fuel_readable: bool}
     */
    public function photo(LimoTripRun $run, string $stage, string $path, string $bytes, string $mime): array
    {
        $read = ['readable' => false, 'km' => 0, 'fuel_eighths' => 0, 'fuel_readable' => false];
        if (BillScanner::isAvailable()) {
            try {
                [$image, $type] = BillScanner::shrinkImage($bytes, $mime);
                $reader = app()->bound(DashboardReader::class) ? app(DashboardReader::class) : new ClaudeDashboardReader();
                $read = $reader->read(BillScanner::apiKey(), \Modules\WhatsApp\Models\AssistantConfiguration::current()->model(), $image, $type);
            } catch (Throwable $e) {
                Log::warning('Dashboard read failed: ' . $e->getMessage());
            }
        }

        $run->forceFill([$stage . '_photo' => $path, $stage . '_read' => $read])->save();

        return $read;
    }

    /**
     * Start the trip. Returns an error message, or null when it started.
     */
    public function start(LimoLeg $leg, LimoTripRun $run, int $km, int $fuel, ?float $lat, ?float $lng, ?int $accuracy): ?string
    {
        if ($run->isStarted()) {
            return null;
        }
        if ($run->start_photo === null) {
            return 'Take the dashboard photo first. / صوّر لوحة العدادات أولاً.';
        }
        if ($km <= 0) {
            return 'Type the KM. / اكتب عدد الكيلومترات.';
        }

        $flags = $run->flags ?? [];
        $car = $leg->car_id !== null ? Vehicle::query()->find($leg->car_id) : null;
        $last = $car?->odometer;
        if ($last !== null && $km + 5 < $last) {
            $flags[] = "Start KM {$km} is lower than the car's last reading {$last}.";
        } elseif ($last !== null && $last > 0 && $km - $last > self::KM_JUMP) {
            $flags[] = "Start KM {$km} is " . ($km - $last) . " more than the car's last reading {$last}.";
        }
        $flags = [...$flags, ...$this->readFlags($run->start_read, $km, $fuel, 'Start')];
        if ($lat === null || $lng === null) {
            $flags[] = 'No location was shared at the start.';
        }

        DB::transaction(function () use ($leg, $run, $km, $fuel, $lat, $lng, $accuracy, $flags, $car): void {
            $run->forceFill([
                'car_id' => $leg->car_id,
                'started_at' => Carbon::now(),
                'start_km' => $km, 'start_fuel' => $fuel,
                'start_lat' => $lat, 'start_lng' => $lng, 'start_accuracy' => $accuracy,
                'flags' => array_values(array_unique($flags)),
            ])->save();

            $this->raiseOdometer($car, $km);

            if (in_array($leg->status, [LimoLeg::STATUS_QUEUE, LimoLeg::STATUS_CONFIRMED], true)) {
                $leg->status = LimoLeg::STATUS_ACTIVE;
                $leg->save();
                $this->syncBooking($leg);
            }
        });

        $this->notifyCustomer($leg, $run);

        return null;
    }

    /**
     * End the trip. Returns an error message, or null when it ended.
     */
    public function end(LimoLeg $leg, LimoTripRun $run, int $km, int $fuel, ?float $lat, ?float $lng, ?int $accuracy): ?string
    {
        if (! $run->isStarted()) {
            return 'Start the trip first. / ابدأ الرحلة أولاً.';
        }
        if ($run->isEnded()) {
            return null;
        }
        if ($run->end_photo === null) {
            return 'Take the dashboard photo first. / صوّر لوحة العدادات أولاً.';
        }
        if ($km <= 0) {
            return 'Type the KM. / اكتب عدد الكيلومترات.';
        }
        if ($run->start_km !== null && $km < $run->start_km) {
            return "The end KM cannot be lower than the start KM ({$run->start_km}). / لا يمكن أن يكون عداد النهاية أقل من عداد البداية ({$run->start_km}).";
        }

        $flags = $run->flags ?? [];
        if ($run->start_km !== null && $km - $run->start_km > self::KM_JUMP) {
            $flags[] = 'Trip distance ' . ($km - $run->start_km) . ' km is unusually long.';
        }
        $flags = [...$flags, ...$this->readFlags($run->end_read, $km, $fuel, 'End')];
        if ($lat === null || $lng === null) {
            $flags[] = 'No location was shared at the end.';
        }

        DB::transaction(function () use ($leg, $run, $km, $fuel, $lat, $lng, $accuracy, $flags): void {
            $run->forceFill([
                'ended_at' => Carbon::now(),
                'end_km' => $km, 'end_fuel' => $fuel,
                'end_lat' => $lat, 'end_lng' => $lng, 'end_accuracy' => $accuracy,
                'flags' => array_values(array_unique($flags)),
            ])->save();

            $this->raiseOdometer($run->car_id !== null ? Vehicle::query()->find($run->car_id) : null, $km);

            if (in_array($leg->status, LimoLeg::OPEN_STATUSES, true)) {
                $leg->status = LimoLeg::STATUS_COMPLETED;
                $leg->save();
                $this->syncBooking($leg);
            }
        });

        return null;
    }

    /**
     * The office ends a trip the driver forgot to end, so he can start his
     * next one. KM and fuel are optional (the office may not know them); the
     * record says who ended it and why, and is flagged for a look.
     */
    public function endByOffice(LimoLeg $leg, LimoTripRun $run, ?int $km, ?int $fuel, string $by, string $note): ?string
    {
        if (! $run->isStarted()) {
            return (string) __('The driver has not started this trip.');
        }
        if ($run->isEnded()) {
            return null;
        }
        if ($km !== null && $run->start_km !== null && $km < $run->start_km) {
            return (string) __('The end KM cannot be lower than the start KM (:km).', ['km' => $run->start_km]);
        }

        $flags = $run->flags ?? [];
        $flags[] = "Ended by the office ({$by}), not the driver: no end photo or location." . ($note !== '' ? " Reason: {$note}" : '');

        DB::transaction(function () use ($leg, $run, $km, $fuel, $by, $note, $flags): void {
            $run->forceFill([
                'ended_at' => Carbon::now(),
                'ended_by' => mb_substr($by, 0, 120),
                'ended_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                'end_km' => $km, 'end_fuel' => $fuel,
                'flags' => array_values(array_unique($flags)),
            ])->save();

            if ($km !== null) {
                $this->raiseOdometer($run->car_id !== null ? Vehicle::query()->find($run->car_id) : null, $km);
            }

            if (in_array($leg->status, LimoLeg::OPEN_STATUSES, true)) {
                $leg->status = LimoLeg::STATUS_COMPLETED;
                $leg->save();
                $this->syncBooking($leg);
            }
        });

        return null;
    }

    /**
     * Trips (leg ids) a driver started and has not ended, among these.
     *
     * @param  list<int>  $legIds
     * @return list<int>
     */
    public static function runningLegs(array $legIds): array
    {
        if ($legIds === [] || ! self::ready()) {
            return [];
        }

        return LimoTripRun::query()->whereIn('leg_id', $legIds)->whereNotNull('started_at')->whereNull('ended_at')
            ->pluck('leg_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * What the photo said against what the driver confirmed.
     *
     * @param  array<string, mixed>|null  $read
     * @return list<string>
     */
    private function readFlags(?array $read, int $km, int $fuel, string $stage): array
    {
        if ($read === null || ! ($read['readable'] ?? false)) {
            return ["{$stage} photo could not be read; KM typed by the driver."];
        }

        $flags = [];
        $readKm = (int) ($read['km'] ?? 0);
        if ($readKm > 0 && abs($readKm - $km) > 2) {
            $flags[] = "{$stage} KM changed by the driver (photo read {$readKm}, saved {$km}).";
        }
        if (($read['fuel_readable'] ?? false) && abs((int) ($read['fuel_eighths'] ?? 0) - $fuel) > 1) {
            $flags[] = "{$stage} fuel changed by the driver (photo read " . LimoTripRun::fuelLabel((int) $read['fuel_eighths']) . ', saved ' . LimoTripRun::fuelLabel($fuel) . ').';
        }

        return $flags;
    }

    /** The car's KM only ever goes up: a lower reading is a flag, not an update. */
    private function raiseOdometer(?Vehicle $car, int $km): void
    {
        if ($car !== null && ($car->odometer === null || $km > $car->odometer)) {
            $car->odometer = $km;
            $car->save();
        }
    }

    private function syncBooking(LimoLeg $leg): void
    {
        $booking = $leg->legable;
        if ($booking instanceof LimoBooking) {
            $booking->syncStatusFromLegs();
        }
    }

    /** "Your driver is on the way", once per trip. A mail failure never stops the start. */
    private function notifyCustomer(LimoLeg $leg, LimoTripRun $run): void
    {
        if ($run->customer_notified_at !== null) {
            return;
        }

        try {
            app(CustomerDocumentEmails::class)->driverOnTheWay($leg->id);
            $run->forceFill(['customer_notified_at' => Carbon::now()])->save();
        } catch (Throwable $e) {
            Log::warning('Driver on the way email failed: ' . $e->getMessage());
        }
    }

    public static function ready(): bool
    {
        return Schema::hasTable('limo_trip_runs');
    }
}
