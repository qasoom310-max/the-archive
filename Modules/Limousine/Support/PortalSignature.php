<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

/**
 * The one signing scheme both ends of the Wanaan portal integration share.
 *
 * A request carries two headers:
 *   X-Wanaan-Timestamp : the Unix time it was signed
 *   X-Wanaan-Signature : hex HMAC-SHA256 of "<timestamp>.<raw-body>", keyed by
 *                        the shared secret
 *
 * The timestamp is folded INTO the signed string (not just sent alongside), so
 * a captured request can't be replayed with a fresh timestamp to slip past the
 * age check — changing the timestamp changes the signature. Verification is
 * constant-time (`hash_equals`) and rejects anything older than five minutes.
 *
 * The WordPress plugin MUST reproduce this byte-for-byte:
 *   hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret)
 */
final class PortalSignature
{
    public const TIMESTAMP_HEADER = 'X-Wanaan-Timestamp';

    public const SIGNATURE_HEADER = 'X-Wanaan-Signature';

    /** How far a request's timestamp may drift from now, in seconds. */
    public const MAX_SKEW_SECONDS = 300;

    /** Hex HMAC-SHA256 of the timestamp-bound raw body. */
    public static function sign(string $rawBody, string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * True only when the signature matches AND the timestamp is fresh. Every
     * input here is attacker-controlled, so each is checked before use and the
     * comparison is constant-time.
     */
    public static function verify(string $rawBody, string $timestamp, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($rawBody, $timestamp, $secret), $signature);
    }

    /**
     * The same scheme, but with the METHOD and PATH folded in as well.
     *
     * {@see sign()} covers only the timestamp and the body, which binds a POST
     * perfectly well — its body carries what the request means. A GET has no
     * body, so that string collapses to "<timestamp>." and identifies nothing:
     * a signature minted for /workspaces/7/pricing would verify unchanged
     * against /workspaces/3/pricing, handing one signed read the run of every
     * database. Folding the path in is what makes a workspace-scoped GET
     * actually scoped.
     *
     * Signed string: "<METHOD>\n<PATH>\n<timestamp>.<raw-body>", where PATH is
     * the URL path alone — no scheme, host, query string or trailing slash.
     *
     * Kept SEPARATE from sign()/verify() rather than replacing them: the live
     * service-order push and payment callback are already signed the old way at
     * both ends, and changing that would break money in flight.
     */
    public static function signRequest(
        string $method,
        string $path,
        string $rawBody,
        string $timestamp,
        string $secret,
    ): string {
        return hash_hmac('sha256', self::canonical($method, $path, $rawBody, $timestamp), $secret);
    }

    /** Constant-time check of {@see signRequest()}, with the same freshness rule. */
    public static function verifyRequest(
        string $method,
        string $path,
        string $rawBody,
        string $timestamp,
        string $signature,
        string $secret,
    ): bool {
        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(
            self::signRequest($method, $path, $rawBody, $timestamp, $secret),
            $signature,
        );
    }

    /**
     * The exact bytes {@see signRequest()} hashes. Public so a test — and the
     * WordPress plugin's own test vectors — can assert the construction rather
     * than only its output.
     */
    public static function canonical(string $method, string $path, string $rawBody, string $timestamp): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $timestamp . '.' . $rawBody;
    }
}
