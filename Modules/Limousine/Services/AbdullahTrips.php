<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Abdullah\Abdullah;
use App\Erp\Settings\CompanyTimezone;
use App\Models\AbdullahAlert;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * Abdullah chases today's trips that still have no driver or no car.
 *
 * Run every minute (routes/console.php). For each such trip he emails the
 * operations address on the owner's timer (every 15 minutes by default),
 * at most 12 times (by default), all of the trips due in ONE email so the
 * mailbox is not flooded. The people with "Trip reminders" on also see it in
 * their box the first time, with an "I'm handling it" button that stops the
 * emails for those trips. He stops by himself the moment the trip has its car
 * and driver, is cancelled, or its time has passed. A trip still missing them
 * after the last reminder is reported to the owner, once.
 */
final class AbdullahTrips
{
    public function __construct(private readonly Abdullah $abdullah) {}

    /**
     * @return array{emails: int, escalations: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $done = ['emails' => 0, 'escalations' => 0];

        if (! $this->abdullah->enabled() || ! Schema::hasTable('limo_legs')) {
            return $done;
        }

        // "Today" is the company's day, whatever the server's clock says.
        $endOfToday = $now->copy()->setTimezone(CompanyTimezone::current())->endOfDay()->setTimezone(config('app.timezone'));

        $legs = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereIn('status', LimoLeg::OPEN_STATUSES)
            ->where('start_at', '>', $now)
            ->where('start_at', '<=', $endOfToday)
            ->where(static fn ($q) => LimoLeg::whereMissingCrew($q))
            ->with('legable')
            ->orderBy('start_at')
            ->get()
            ->filter(static fn (LimoLeg $leg): bool => $leg->legable instanceof LimoBooking
                && $leg->legable->status !== LimoBooking::STATUS_CANCELLED)
            ->values();

        // Fixed, cancelled or gone by: stop chasing.
        $missingKeys = $legs->map(static fn (LimoLeg $leg): string => self::key($leg))->all();
        AbdullahAlert::query()->where('kind', 'trip')->whereNull('resolved_at')
            ->whereNotIn('key', $missingKeys === [] ? [''] : $missingKeys)
            ->update(['resolved_at' => $now]);

        $every = $this->abdullah->tripEveryMinutes();
        $max = $this->abdullah->tripMax();
        // A minute's give, so a run a few seconds early does not skip a round.
        $dueBefore = $now->copy()->subMinutes($every)->addMinute();

        $due = [];
        $firstTime = [];
        $maxed = [];

        foreach ($legs as $leg) {
            $alert = $this->abdullah->alert(self::key($leg), 'trip');
            if ($alert->acknowledged_at !== null) {
                continue;
            }

            if ($alert->sent_count >= $max) {
                if ($alert->escalated_at === null) {
                    $maxed[] = [$leg, $alert];
                }

                continue;
            }

            if ($alert->last_sent_at === null || $alert->last_sent_at->lte($dueBefore)) {
                $due[] = [$leg, $alert];
                if ($alert->sent_count === 0) {
                    $firstTime[] = $leg;
                }
            }
        }

        if ($due !== []) {
            $round = max(array_map(static fn (array $pair): int => $pair[1]->sent_count, $due)) + 1;
            $dueLegs = array_map(static fn (array $pair): LimoLeg => $pair[0], $due);

            $sent = $this->abdullah->email(
                $this->abdullah->opsEmail(),
                'trip',
                ['trips' => $this->tripFacts($dueLegs), 'round' => $round, 'max' => $max],
                (string) __('Abdullah: :count trips today still without a driver', ['count' => count($dueLegs)], 'ar'),
                'ar',
                null,
                null,
                $this->rows($dueLegs, 'ar'),
                (string) __('Operations team', [], 'ar'),
            );
            if ($sent !== null) {
                $done['emails']++;
            }

            foreach ($due as [, $alert]) {
                $alert->forceFill(['sent_count' => $alert->sent_count + 1, 'last_sent_at' => $now])->save();
            }

            // The box hears about a trip once; the email is what repeats.
            if ($firstTime !== []) {
                foreach ($this->operations() as $user) {
                    $this->abdullah->tell($user, 'trip', [
                        'trips' => $this->tripFacts($firstTime),
                        'round' => 1,
                        'max' => $max,
                        'keys' => array_map(static fn (LimoLeg $leg): string => self::key($leg), $firstTime),
                    ]);
                }
            }
        }

        if ($maxed !== []) {
            $maxedLegs = array_map(static fn (array $pair): LimoLeg => $pair[0], $maxed);
            $facts = ['trips' => $this->tripFacts($maxedLegs), 'reminders' => $max];

            foreach ($this->owners() as $owner) {
                $language = $this->abdullah->languageFor($owner);
                $this->abdullah->email(
                    (string) $owner->email,
                    'escalation',
                    $facts,
                    (string) __('Abdullah: trips today still without a driver after :count reminders', ['count' => $max], $language === 'ar' ? 'ar' : 'en'),
                    $language,
                    (int) $owner->id,
                    null,
                    $this->rows($maxedLegs, $language),
                    (string) $owner->name,
                );
                $this->abdullah->tell($owner, 'escalation', $facts);
                $done['escalations']++;
            }

            foreach ($maxed as [, $alert]) {
                $alert->forceFill(['escalated_at' => $now])->save();
            }
        }

        return $done;
    }

    public static function key(LimoLeg $leg): string
    {
        return 'trip:leg:' . $leg->id;
    }

    /**
     * @param  list<LimoLeg>  $legs
     * @return list<array{reference: string, time: string, missing: string}>
     */
    private function tripFacts(array $legs): array
    {
        return array_map(fn (LimoLeg $leg): array => [
            'reference' => (string) $leg->reference,
            'time' => $leg->start_at?->copy()->setTimezone(CompanyTimezone::current())->format('H:i') ?? '',
            'missing' => $this->missing($leg, 'en'),
        ], $legs);
    }

    /**
     * @param  list<LimoLeg>  $legs
     * @return list<array{reference: string, time: string, missing: string, url: string}>
     */
    private function rows(array $legs, string $language): array
    {
        return array_map(fn (LimoLeg $leg): array => [
            'reference' => (string) $leg->reference,
            'time' => $leg->start_at?->copy()->setTimezone(CompanyTimezone::current())->format('H:i') ?? '',
            'missing' => $this->missing($leg, $language),
            'url' => url('/app/limousine/booking/' . $leg->legable_id),
        ], $legs);
    }

    private function missing(LimoLeg $leg, string $language): string
    {
        $locale = $language === 'ar' ? 'ar' : 'en';
        $noCar = $leg->car_id === null && trim((string) $leg->outside_company) === '';
        $noDriver = $leg->driver_id === null;

        return match (true) {
            $noCar && $noDriver => (string) __('Car and driver', [], $locale),
            $noCar => (string) __('Car', [], $locale),
            default => (string) __('Driver', [], $locale),
        };
    }

    /**
     * The people the owner switched "Trip reminders" on for (Settings → Users).
     *
     * @return Collection<int, User>
     */
    private function operations(): Collection
    {
        if (! Schema::hasColumn('users', 'trip_reminders')) {
            return collect();
        }

        return User::query()->where('trip_reminders', true)->get()
            ->filter(static fn (User $u): bool => $u->getsTripReminders())->values();
    }

    /** @return Collection<int, User> */
    private function owners(): Collection
    {
        return User::query()->where('is_super_admin', true)->get()
            ->reject(static fn (User $u): bool => $u->isPaused() || trim((string) $u->email) === '')
            ->values();
    }
}
