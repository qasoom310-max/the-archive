<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;

/**
 * Which trips staff can no longer change (the owner's rule, 2026-11-24).
 *
 *   Active     the job's terms are fixed: customer, times, places, price,
 *              extras, car type, trips added or removed. Comments, a car or
 *              driver swap (breakdown) and payments stay open.
 *   Completed  read-only: no edits, no cancel, no re-assigning.
 *   Cancelled  read-only; restoring it is a correction.
 *
 * Payments stay open on every trip (money often arrives after it). Only the
 * owner and the Accountant may correct a locked trip, with a reason that is
 * logged. Each trip locks on its own: a booking's later trips stay editable.
 */
final class TripLock
{
    public const FULL = [LimoLeg::STATUS_COMPLETED, LimoLeg::STATUS_CANCELLED];

    public const PARTIAL = [LimoLeg::STATUS_ACTIVE];

    /** The owner and the Accountant: the people who may correct a locked trip. */
    public static function mayCorrect(?Authenticatable $user): bool
    {
        return $user instanceof User && $user->canConfirmPayments();
    }

    /** 'full', 'partial' or null for one trip. */
    public static function of(LimoLeg $leg): ?string
    {
        return match (true) {
            in_array($leg->status, self::FULL, true) => 'full',
            in_array($leg->status, self::PARTIAL, true) => 'partial',
            default => null,
        };
    }

    /** Whether this person is stopped by this trip's lock right now. */
    public static function blocks(LimoLeg $leg, ?Authenticatable $user, bool $fullOnly = false): bool
    {
        $state = self::of($leg);

        return $state !== null && ($state === 'full' || ! $fullOnly) && ! self::mayCorrect($user);
    }

    /**
     * A trip of the booking that has run or is running (active or completed):
     * from then on the booking's customer and passenger are fixed. A cancelled
     * trip alone does not freeze the rest of the booking.
     */
    public static function bookingHasLock(LimoBooking $booking): bool
    {
        return $booking->legs()->whereIn('status', [LimoLeg::STATUS_ACTIVE, LimoLeg::STATUS_COMPLETED])->exists();
    }

    /** A trip running right now: the whole booking cannot be cancelled by staff. */
    public static function bookingHasActive(LimoBooking $booking): bool
    {
        return $booking->legs()->where('status', LimoLeg::STATUS_ACTIVE)->exists();
    }

    public static function message(LimoLeg $leg): string
    {
        return self::of($leg) === 'partial'
            ? (string) __('Trip :ref is active: its details are locked. Only the owner or the accountant can change them.', ['ref' => (string) $leg->reference])
            : (string) __('Trip :ref is :status: it is locked. Only the owner or the accountant can change it.', ['ref' => (string) $leg->reference, 'status' => __((string) $leg->status)]);
    }
}
