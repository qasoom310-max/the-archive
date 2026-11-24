<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Abdullah\Abdullah;
use App\Erp\Settings\CompanyTimezone;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoTripRun;

/**
 * Abdullah tells operations when a trip's time has passed and its driver has
 * not pressed Start trip on his link (the owner's request, 2026-11-24): nobody
 * can say "I was in a hurry" without the office knowing at the time.
 *
 * Run every minute. A trip counts once it is LATE_MINUTES past its pick-up and
 * for up to six hours after; each trip is reported ONCE, in at most one email
 * per reminder interval for all trips due (and the "Trip reminders" people see it
 * in their box). Trips before the link existed (SINCE) are left alone.
 */
final class AbdullahTripStarts
{
    public const LATE_MINUTES = 15;

    /** The trip link went live on 24 Nov 2026; earlier trips never had one. */
    public const SINCE = '2026-11-25 00:00:00';

    public function __construct(private readonly Abdullah $abdullah) {}

    public function run(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        if (! $this->abdullah->enabled() || ! DriverTrip::ready()) {
            return 0;
        }

        $since = Carbon::parse(self::SINCE, CompanyTimezone::current())->setTimezone(config('app.timezone'));
        $legs = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereIn('status', LimoLeg::OPEN_STATUSES)
            ->whereNotNull('driver_id')
            ->where('start_at', '>=', $since)
            ->where('start_at', '<=', $now->copy()->subMinutes(self::LATE_MINUTES))
            ->where('start_at', '>=', $now->copy()->subHours(6))
            ->with('legable')
            ->orderBy('start_at')
            ->get()
            ->filter(static fn (LimoLeg $leg): bool => $leg->legable instanceof LimoBooking
                && $leg->legable->status !== LimoBooking::STATUS_CANCELLED
                && ! LimoTripRun::query()->where('leg_id', $leg->id)->where('driver_id', $leg->driver_id)->whereNotNull('started_at')->exists())
            ->values();

        // One shared clock (as for trips without a driver): at most one email per
        // interval, listing every trip not started that has not been reported.
        $batch = $this->abdullah->alert('start:batch', 'start_batch');
        if ($batch->last_sent_at !== null && $batch->last_sent_at->gt($now->copy()->subMinutes($this->abdullah->tripEveryMinutes())->addMinute())) {
            return 0;
        }

        $due = [];
        foreach ($legs as $leg) {
            $alert = $this->abdullah->alert(self::key($leg), 'not_started');
            if ($alert->sent_count === 0) {
                $due[] = [$leg, $alert];
            }
        }
        if ($due === []) {
            return 0;
        }

        $dueLegs = array_map(static fn (array $pair): LimoLeg => $pair[0], $due);
        $facts = ['trips' => array_map(static fn (LimoLeg $leg): array => [
            'reference' => (string) $leg->reference,
            'time' => $leg->start_at?->copy()->setTimezone(CompanyTimezone::current())->format('H:i') ?? '',
            'driver' => trim((string) $leg->driver),
        ], $dueLegs)];

        $this->abdullah->email(
            $this->abdullah->opsEmail(),
            'not_started',
            $facts,
            (string) __('Abdullah: :count trips not started by the driver', ['count' => count($dueLegs)], 'ar'),
            'ar',
            null,
            null,
            array_map(static fn (LimoLeg $leg): array => [
                'reference' => (string) $leg->reference,
                'time' => $leg->start_at?->copy()->setTimezone(CompanyTimezone::current())->format('H:i') ?? '',
                'missing' => trim((string) $leg->driver),
                'url' => url('/app/limousine/trip-record/' . $leg->id),
            ], $dueLegs),
            (string) __('Operations team', [], 'ar'),
        );

        if (Schema::hasColumn('users', 'trip_reminders')) {
            foreach (User::query()->where('trip_reminders', true)->get() as $user) {
                if ($user->getsTripReminders()) {
                    $this->abdullah->tell($user, 'not_started', $facts);
                }
            }
        }

        foreach ($due as [, $alert]) {
            $alert->forceFill(['sent_count' => 1, 'last_sent_at' => $now])->save();
        }
        $batch->forceFill(['sent_count' => $batch->sent_count + 1, 'last_sent_at' => $now])->save();

        return count($dueLegs);
    }

    public static function key(LimoLeg $leg): string
    {
        return 'start:leg:' . $leg->id;
    }
}
