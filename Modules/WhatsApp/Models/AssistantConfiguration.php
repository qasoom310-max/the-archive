<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The staff assistant's own settings (single row, per database). The Meta
 * credentials live in {@see WhatsAppConfiguration}; this holds the AI key,
 * the model, and the master kill-switch.
 *
 * @property int $id
 * @property string|null $ai_api_key
 * @property string|null $ai_model
 * @property bool $enabled
 */
final class AssistantConfiguration extends Model
{
    public const DEFAULT_MODEL = 'claude-opus-5';

    protected $table = 'whatsapp_assistant_configuration';

    /** @var list<string> */
    protected $fillable = ['ai_api_key', 'ai_model', 'enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_api_key' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    public function model(): string
    {
        $model = trim((string) $this->ai_model);

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /** Switched on AND able to think: a key is set. */
    public function isReady(): bool
    {
        return (bool) $this->enabled && trim((string) $this->ai_api_key) !== '';
    }
}
