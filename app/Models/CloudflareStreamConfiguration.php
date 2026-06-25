<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row Cloudflare Stream API connection settings.
 *
 * The API token uses the `encrypted` cast (written/read through APP_KEY, never
 * plaintext). `current()` uses firstOrNew so callers always get a non-null
 * instance even before configuration.
 *
 * @property int $id
 * @property string|null $account_id
 * @property string|null $api_token
 * @property bool $enabled
 */
final class CloudflareStreamConfiguration extends Model
{
    protected $table = 'cloudflare_stream_configuration';

    /** @var list<string> */
    protected $fillable = ['account_id', 'api_token', 'enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    /** Ready to use: enabled and both the account id + token are present. */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->account_id !== null && $this->account_id !== ''
            && (string) $this->api_token !== '';
    }

    /** Base Stream endpoint for the configured account. */
    public function apiBase(): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/' . (string) $this->account_id . '/stream';
    }
}
