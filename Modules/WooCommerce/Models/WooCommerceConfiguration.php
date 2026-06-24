<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row WooCommerce REST API connection settings.
 *
 * The consumer key/secret use the `encrypted` cast so they are written/read
 * through APP_KEY (never plaintext in the DB). `current()` uses firstOrNew so
 * callers always get a non-null instance even before configuration.
 *
 * @property int $id
 * @property string|null $store_url
 * @property string|null $consumer_key
 * @property string|null $consumer_secret
 * @property string|null $api_version
 * @property bool $enabled
 */
final class WooCommerceConfiguration extends Model
{
    protected $table = 'woocommerce_configuration';

    /** @var list<string> */
    protected $fillable = [
        'store_url', 'consumer_key', 'consumer_secret', 'api_version', 'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
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
     * Ready to push: enabled and the store URL + both credentials are present.
     */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->store_url !== null && $this->store_url !== ''
            && (string) $this->consumer_key !== ''
            && (string) $this->consumer_secret !== '';
    }

    /**
     * Base REST endpoint, e.g. https://shop.example.com/wp-json/wc/v3 .
     */
    public function apiBase(): string
    {
        $version = (string) $this->api_version !== '' ? (string) $this->api_version : 'wc/v3';

        return rtrim((string) $this->store_url, '/') . '/wp-json/' . trim($version, '/');
    }
}
