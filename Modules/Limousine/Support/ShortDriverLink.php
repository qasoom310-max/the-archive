<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoLeg;

/**
 * Short addresses for the driver's two links, for a WhatsApp message a driver
 * can read: erp…/d/7.8z1.e.Ab3dEf9hJk2L instead of a 150-character signed URL.
 *
 * The code carries the business database, the trip, the driver (base 36) and,
 * for bills, when it expires; the last part is an HMAC of all of that with the
 * app key, so nothing in it can be changed. No table: the code IS the record.
 * Opening it checks the HMAC and redirects to the full signed address, which
 * keeps every check it always had (driver still on the trip, expiry…).
 */
final class ShortDriverLink
{
    public static function trip(LimoLeg $leg): ?string
    {
        if ($leg->driver_id === null) {
            return null;
        }

        return url('/d/' . self::code('d', [self::ws(), (int) $leg->id, (int) $leg->driver_id]));
    }

    public static function bills(LimoLeg $leg, Carbon $expires): ?string
    {
        if ($leg->driver_id === null) {
            return null;
        }

        return url('/b/' . self::code('b', [self::ws(), (int) $leg->id, (int) $leg->driver_id, $expires->getTimestamp()]));
    }

    /**
     * The numbers in a code, or null when it was not made here.
     *
     * @return list<int>|null
     */
    public static function decode(string $kind, string $code, int $parts): ?array
    {
        $bits = explode('.', $code);
        if (count($bits) !== $parts + 1) {
            return null;
        }

        $sig = (string) array_pop($bits);
        $numbers = [];
        foreach ($bits as $bit) {
            if (preg_match('/^[0-9a-z]{1,12}$/', $bit) !== 1) {
                return null;
            }
            $numbers[] = (int) base_convert($bit, 36, 10);
        }

        return hash_equals(self::sign($kind, $numbers), $sig) ? $numbers : null;
    }

    /**
     * @param  list<int>  $numbers
     */
    private static function code(string $kind, array $numbers): string
    {
        return implode('.', array_map(static fn (int $n): string => base_convert((string) $n, 10, 36), $numbers)) . '.' . self::sign($kind, $numbers);
    }

    /**
     * @param  list<int>  $numbers
     */
    private static function sign(string $kind, array $numbers): string
    {
        $raw = hash_hmac('sha256', $kind . '|' . implode('|', $numbers), (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode($raw), '+/', 'xy'), '='), 0, 12);
    }

    private static function ws(): int
    {
        return app(WorkspaceManager::class)->activeTenantId() ?? 0;
    }
}
