<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row connection settings for the Wanaan WordPress service-order portal.
 *
 * `shared_secret` uses the `encrypted` cast (written/read through APP_KEY, never
 * plaintext in the DB) — it is the HMAC key both sides sign with. `current()`
 * uses firstOrNew so callers always get a non-null instance even before setup.
 *
 * Same shape as {@see \Modules\WooCommerce\Models\WooCommerceConfiguration}.
 *
 * @property int $id
 * @property string|null $portal_url
 * @property string|null $shared_secret
 * @property bool $enabled
 */
final class LimoPortalConfiguration extends Model
{
    protected $table = 'limo_portal_configuration';

    /** @var list<string> */
    protected $fillable = [
        'portal_url', 'shared_secret', 'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The one configuration row (or a fresh, unsaved instance).
     */
    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    /**
     * Ready to push: the master switch is ON and both the URL and the shared
     * secret are set. A missing secret can never push — an unsigned request the
     * portal would reject anyway, so we don't send it.
     */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->portal_url !== null && $this->portal_url !== ''
            && (string) $this->shared_secret !== '';
    }

    /**
     * Endpoint the ERP POSTs a service order to, e.g.
     * https://wanaan-bh.com/wp-json/wanaan/v1/booking .
     */
    public function bookingEndpoint(): string
    {
        return rtrim((string) $this->portal_url, '/') . '/wp-json/wanaan/v1/booking';
    }
}
