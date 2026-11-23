<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverPayment;
use Modules\Limousine\Models\LimoLeg;

/**
 * What each outside driver has earned, been paid, and is still owed.
 *
 * Earned = the commission on every trip they drove from the start date up to
 * now (the same trips the Outside drivers page counts: booking trips, not
 * cancelled, with an amount). A trip later than now is "booked ahead": not owed
 * yet. Paid = every payment not cancelled. Owed = earned − paid; below zero
 * means paid ahead.
 *
 * The start date exists because older months were paid outside the system:
 * counting them would show money owed that was already handed over. The owner
 * sets it on the Driver payments page; 1 November 2026 to begin with, the
 * month whose total the owner quoted (1,098 BD).
 *
 * @phpstan-type Balance array{id: int, name: string, earned: float, paid: float, owed: float, ahead: float, trips: int}
 */
final class DriverBalances
{
    public const START_KEY = 'limousine.driver_pay_from';

    public const DEFAULT_START = '2026-11-01';

    public static function start(): Carbon
    {
        $value = (string) Setting::get(self::START_KEY, self::DEFAULT_START);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $value = self::DEFAULT_START;
        }

        return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay() ?? Carbon::parse(self::DEFAULT_START)->startOfDay();
    }

    /**
     * @return array<int, Balance> keyed by driver id, most owed first
     */
    public function all(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $start = self::start();

        $legs = LimoLeg::query()
            ->where('legable_type', LimoBooking::class)
            ->where('status', '!=', LimoLeg::STATUS_CANCELLED)
            ->whereNotNull('driver_id')
            ->whereNotNull('driver_payment')
            ->where('start_at', '>=', $start)
            ->get(['driver_id', 'driver_payment', 'start_at']);

        $paid = Schema::hasTable('limo_driver_payments')
            ? LimoDriverPayment::query()->whereNull('cancelled_at')
                ->selectRaw('driver_id, SUM(amount) as total')->groupBy('driver_id')
                ->pluck('total', 'driver_id')
            : collect();

        $ids = $legs->pluck('driver_id')->merge($paid->keys())->map(static fn (mixed $id): int => (int) $id)->unique()->all();

        $drivers = LimoDriver::query()
            ->where(static fn ($q) => $q->where('pay_type', LimoDriver::PAY_TYPE_COMMISSION)->orWhereIn('id', $ids === [] ? [0] : $ids))
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($drivers as $driver) {
            $mine = $legs->where('driver_id', $driver->id);
            $done = $mine->filter(static fn (LimoLeg $leg): bool => $leg->start_at !== null && $leg->start_at->lte($now));
            $earned = round((float) $done->sum('driver_payment'), 3);
            $ahead = round((float) $mine->sum('driver_payment') - $earned, 3);
            $paidTotal = round((float) ($paid->get($driver->id) ?? 0), 3);

            $rows[(int) $driver->id] = [
                'id' => (int) $driver->id,
                'name' => $driver->displayName(),
                'earned' => $earned,
                'paid' => $paidTotal,
                'owed' => round($earned - $paidTotal, 3),
                'ahead' => $ahead,
                'trips' => $done->count(),
            ];
        }

        uasort($rows, static fn (array $a, array $b): int => [$b['owed'], $a['name']] <=> [$a['owed'], $b['name']]);

        return $rows;
    }

    /** @return Balance|null */
    public function of(int $driverId): ?array
    {
        return $this->all()[$driverId] ?? null;
    }
}
