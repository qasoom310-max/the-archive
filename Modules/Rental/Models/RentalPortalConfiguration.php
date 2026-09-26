<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The website's credential for sending bookings in, one row per database.
 *
 * `shared_secret` is the HMAC key both ends sign with, stored through the
 * `encrypted` cast so the database never holds it in plain text.
 *
 * Deliberately NOT the limousine portal's secret, though the shape is the same
 * ({@see \Modules\Limousine\Models\LimoPortalConfiguration}). They are two
 * plugins on one website doing different jobs, and a booking plugin that is
 * compromised must not also be able to sign "this trip has been paid for"
 * against the payment callback.
 *
 * Inbound only, so there is no URL to store: the website comes to us.
 *
 * @property int $id
 * @property string|null $shared_secret
 * @property bool $enabled
 */
final class RentalPortalConfiguration extends Model
{
    protected $table = 'rental_portal_configuration';

    /** @var list<string> */
    protected $fillable = ['shared_secret', 'enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shared_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /** The one configuration row (or a fresh, unsaved instance). */
    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    /**
     * Open for business: the switch is on AND there is a secret to verify
     * against. Without a secret nothing could be verified, so the endpoint
     * stays shut rather than accepting whatever arrives.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && (string) $this->shared_secret !== '';
    }
}
