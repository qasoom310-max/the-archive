<?php

declare(strict_types=1);

namespace Modules\Storefront\Support;

/**
 * How the shop website signs every request it makes to this ERP.
 *
 * Headers:
 *   X-Store-Timestamp : Unix time of signing
 *   X-Store-Signature : hex HMAC-SHA256 of
 *                       "<METHOD>\n<PATH>\n<timestamp>.<raw body>"
 *                       keyed by the shared secret from Settings → Online store
 *
 * PATH is the URL path only, with its leading slash and nothing after it, e.g.
 * "/storefront/v1/7/catalog". The method and path are signed so a signature
 * for one database's catalog cannot be replayed against another's; the
 * timestamp is signed and must be within five minutes. Same scheme as the
 * portal's signed reads, kept in this module so it does not depend on the
 * Limousine module being present.
 */
final class StoreSignature
{
    public const TIMESTAMP_HEADER = 'X-Store-Timestamp';

    public const SIGNATURE_HEADER = 'X-Store-Signature';

    public const MAX_SKEW_SECONDS = 300;

    public static function sign(string $method, string $path, string $rawBody, string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $timestamp . '.' . $rawBody, $secret);
    }

    public static function verify(string $method, string $path, string $rawBody, string $timestamp, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($method, $path, $rawBody, $timestamp, $secret), $signature);
    }
}
