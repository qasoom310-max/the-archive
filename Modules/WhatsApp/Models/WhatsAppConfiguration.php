<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row WhatsApp Business Cloud API connection settings.
 *
 * Secrets use the `encrypted` cast so they are written/read through
 * APP_KEY (never plaintext in the DB). `current()` uses firstOrNew so
 * callers always get a non-null instance even before configuration.
 *
 * @property int $id
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string|null $access_token
 * @property string|null $app_secret
 * @property string|null $webhook_verify_token
 * @property string|null $api_version
 * @property string|null $template_language  Meta locale code for the outbound template,
 *                                            e.g. 'en' / 'en_US' / 'ar'. Must match the
 *                                            language tab where the template was approved
 *                                            in WhatsApp Manager — wrong code = Graph
 *                                            error #132001 "Template name does not exist
 *                                            in the translation".
 * @property string|null $from_phone_label
 * @property bool $enabled
 */
final class WhatsAppConfiguration extends Model
{
    protected $table = 'whatsapp_configuration';

    /** @var list<string> */
    protected $fillable = [
        'phone_number_id', 'business_account_id', 'access_token',
        'app_secret', 'webhook_verify_token', 'api_version',
        'template_language', 'from_phone_label', 'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
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
     * Ready to send: enabled and the mandatory credentials are present.
     */
    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->phone_number_id !== null && $this->phone_number_id !== ''
            && $this->access_token !== null && $this->access_token !== '';
    }

    /**
     * Graph API messages endpoint for the configured "from" number.
     */
    public function graphEndpoint(): string
    {
        $version = (string) $this->api_version !== '' ? (string) $this->api_version : 'v21.0';

        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $version,
            (string) $this->phone_number_id,
        );
    }
}
