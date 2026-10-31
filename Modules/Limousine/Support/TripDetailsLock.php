<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;

/**
 * Operations staff the owner named see a trip's details only once it has a car
 * AND a driver ({@see User::seesTripDetailsOnlyWhenCrewed()}).
 *
 * The office copied trips out of the system and sent them to drivers on
 * WhatsApp without assigning anyone, so the customer — promised their driver
 * and car 12 hours ahead — had nothing to be sent. Until both are assigned,
 * such a user sees the trip number, time, car type, customer and the countries;
 * the exact places and map pins, the passenger, phone numbers, notes, the copy
 * text and the service order stay back. Assigning the two is what shows them,
 * and the same moment sends the customer their driver (CrewNotices).
 *
 * Applied wherever a trip's details reach a screen: the bookings list (rows,
 * copy, preview, quick edit, assign dialog), its exports, the booking page, the
 * service order, the customer page and the WhatsApp staff assistant. A website
 * can't stop a screenshot, so the details simply aren't on screen to take one.
 */
final class TripDetailsLock
{
    public static function appliesTo(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User && $user->seesTripDetailsOnlyWhenCrewed();
    }

    /** Whether this trip's details are held back from the user right now. */
    public static function hides(LimoLeg $leg, ?Authenticatable $user = null): bool
    {
        return self::appliesTo($user)
            && in_array($leg->status, LimoLeg::OPEN_STATUSES, true)
            && ! $leg->hasCarAndDriver();
    }

    /** Whether any trip still to happen on this booking is held back. */
    public static function hidesBooking(LimoBooking $booking, ?Authenticatable $user = null): bool
    {
        if (! self::appliesTo($user)) {
            return false;
        }

        return $booking->legs()
            ->whereIn('status', LimoLeg::OPEN_STATUSES)
            ->where(static fn ($q) => $q->whereNull('car_id')->orWhereNull('driver_id'))
            ->exists();
    }

    /** What stands in for a held-back field. */
    public static function placeholder(): string
    {
        return '🔒 ' . __('After a car and driver are assigned');
    }

    /**
     * A held-back place: just its country ("🔒 Saudi Arabia"), which is all the
     * office needs to pick a car and a driver for a border run.
     */
    public static function place(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        foreach (LimoLocation::countryOptions() as $option) {
            if ($option['value'] === $code) {
                return '🔒 ' . $option['label'];
            }
        }

        return self::placeholder();
    }
}
