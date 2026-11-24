<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Modules\Limousine\Models\LimoLeg;

/**
 * The link a driver gets for his trip: details, start photo, end photo, bills.
 *
 * Signed with the trip AND the driver, so it cannot be pointed at another
 * trip or driver, and it stops working when the trip goes to someone else or
 * is cancelled. It does NOT expire (the owner's call): it lives until the
 * driver has ended the trip, and after that it shows the finished trip. The
 * business database rides along as `ws`, because the driver is signed out.
 */
final class DriverTripLink
{
    /** Both languages at once, whatever the page language (the owner's wording, 2026-11-24). */
    public const THANKS_EN = 'Thank you :driver for being part of Wanaan';

    public const THANKS_AR = 'شكراً :driver لكونك جزءاً من ونان';

    public static function for(LimoLeg $leg): ?string
    {
        if ($leg->driver_id === null || ! Route::has('limousine.driver_trip.show')) {
            return null;
        }

        $ws = app(WorkspaceManager::class)->activeTenantId();
        $params = ['leg' => $leg->id, 'driver' => $leg->driver_id];
        if ($ws !== null) {
            $params['ws'] = $ws;
        }

        return URL::signedRoute('limousine.driver_trip.show', $params);
    }

    /**
     * The message the office copies to the driver: trip number, thanks, link,
     * in English then Arabic. Everything else is on the page behind the link.
     */
    public static function message(LimoLeg $leg, string $link): string
    {
        $driver = trim((string) ($leg->driver ?? ''));
        $ref = (string) ($leg->reference ?? '');

        return implode("\n", [
            '*Your booking No ' . $ref . '*',
            str_replace(':driver', $driver, self::THANKS_EN),
            '',
            '*رقم حجزك ' . $ref . '*',
            str_replace(':driver', $driver, self::THANKS_AR),
            '',
            '👉 ' . $link,
        ]);
    }
}
