<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Push\ExpoPush;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\AppDevice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Throwable;

/**
 * The staff app's notifications for the 12-hour promise: the customer gets the
 * driver and car 12 hours before pick-up, so a trip inside those 12 hours with
 * no car or no driver is everyone's problem.
 *
 * Every few minutes, for each database (routes/console.php):
 * - operations staff — the people the owner ticked under Settings → Driver
 *   details — get a phone notification every {@see REMIND_EVERY_MINUTES}
 *   minutes until the trip has both, or its pick-up time passes;
 * - the owner (every super admin) is told once that the promise was missed —
 *   for a booking taken inside the 12 hours, only after an hour unsolved.
 *
 * Through Expo's push service: free, both Android and iPhone, no Meta. It only
 * reaches people signed in to the app, so there is no switch to forget.
 */
final class AppCrewReminders
{
    public const REMIND_EVERY_MINUTES = 10;

    public const LATE_BOOKING_GRACE_MINUTES = 60;

    public function __construct(
        private readonly ExpoPush $push,
        private readonly WorkspaceManager $workspaces,
    ) {
    }

    /**
     * @return array{reminders: int, alerts: int} how many phones were notified
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $sent = ['reminders' => 0, 'alerts' => 0];

        if (! Schema::hasTable('app_devices') || ! AppDevice::query()->exists()) {
            return $sent;
        }

        $operations = Schema::hasColumn('users', 'trip_details_locked')
            ? User::query()->where('trip_details_locked', true)->get()
                ->filter(static fn (User $u): bool => $u->seesTripDetailsOnlyWhenCrewed())->values()
            : collect();
        $owners = User::query()->where('is_super_admin', true)->get();

        if ($operations->isEmpty() && $owners->isEmpty()) {
            return $sent;
        }

        $legs = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereIn('status', LimoLeg::OPEN_STATUSES)
            ->where('start_at', '>', $now)
            ->where('start_at', '<=', $now->copy()->addHours(LimoLeg::CREW_PROMISE_HOURS))
            ->where(static fn ($q) => $q->whereNull('car_id')->orWhereNull('driver_id'))
            ->with('legable.customer')
            ->orderBy('start_at')
            ->get();

        foreach ($legs as $leg) {
            $booking = $leg->legable;
            if (! $booking instanceof LimoBooking) {
                continue;
            }

            // One trip's bad data must not stop the rest.
            try {
                $sent['reminders'] += $this->remind($leg, $booking, $operations, $now);
                $sent['alerts'] += $this->alert($leg, $booking, $owners, $now);
            } catch (Throwable $e) {
                Log::warning('Staff-app trip reminder failed', ['leg' => $leg->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $operations
     */
    private function remind(LimoLeg $leg, LimoBooking $booking, Collection $operations, Carbon $now): int
    {
        // A minute's give, so a run that lands a few seconds early does not
        // push the next reminder out by a whole extra run.
        $since = $now->copy()->subMinutes(self::REMIND_EVERY_MINUTES)->addMinute();
        if ($operations->isEmpty() || ($leg->push_reminded_at !== null && $leg->push_reminded_at->gt($since))) {
            return 0;
        }

        $phones = $this->push->toUsersInTheirLanguage(
            $operations,
            fn (): array => [
                __('Assign a car and driver: trip :reference', ['reference' => (string) $leg->reference]),
                __(':when · :customer — the customer is waiting for the driver details.', [
                    'when' => $this->when($leg),
                    'customer' => (string) ($booking->customer->name ?? ''),
                ]),
            ],
            $this->data($leg, 'needs_crew'),
        );

        $leg->forceFill(['push_reminded_at' => $now])->saveQuietly();

        return $phones;
    }

    /**
     * @param  Collection<int, User>  $owners
     */
    private function alert(LimoLeg $leg, LimoBooking $booking, Collection $owners, Carbon $now): int
    {
        if ($owners->isEmpty() || $leg->push_escalated_at !== null || $leg->start_at === null) {
            return 0;
        }

        $deadline = $leg->start_at->copy()->subHours(LimoLeg::CREW_PROMISE_HOURS);
        $grace = ($leg->created_at ?? $now)->copy()->addMinutes(self::LATE_BOOKING_GRACE_MINUTES);
        if ($now->lt($deadline->max($grace))) {
            return 0;
        }

        $phones = $this->push->toUsersInTheirLanguage(
            $owners,
            fn (): array => [
                __('12-hour promise missed: trip :reference', ['reference' => (string) $leg->reference]),
                __(':when · :customer — still no car or driver.', [
                    'when' => $this->when($leg),
                    'customer' => (string) ($booking->customer->name ?? ''),
                ]),
            ],
            $this->data($leg, 'promise_missed'),
        );

        $leg->forceFill(['push_escalated_at' => $now])->saveQuietly();

        return $phones;
    }

    /** The pick-up time, in the language the message is being written in. */
    private function when(LimoLeg $leg): string
    {
        return $leg->start_at?->copy()->locale(app()->getLocale())->isoFormat('ddd D MMM, HH:mm') ?? '';
    }

    /**
     * What the app needs to open the trip when the notification is tapped.
     *
     * @return array<string, scalar|null>
     */
    private function data(LimoLeg $leg, string $type): array
    {
        return [
            'type' => $type,
            'trip_id' => (int) $leg->id,
            'workspace_id' => $this->workspaces->activeWorkspaceId(),
        ];
    }
}
