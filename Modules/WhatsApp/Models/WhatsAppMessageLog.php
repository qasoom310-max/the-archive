<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per WhatsApp message (outbound or inbound) and its current
 * delivery status. The webhook correlates Meta status callbacks to
 * outbound rows via {@see $wamid}.
 *
 * @property int $id
 * @property string|null $wamid
 * @property string $direction
 * @property string|null $contact_number
 * @property string|null $message_type
 * @property string|null $template_name
 * @property string $status
 * @property string|null $error
 * @property array<string, mixed>|null $payload
 * @property string|null $related_type
 * @property int|null $related_id
 */
final class WhatsAppMessageLog extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    protected $table = 'whatsapp_messages_log';

    /** @var list<string> */
    protected $fillable = [
        'wamid', 'direction', 'contact_number', 'message_type',
        'template_name', 'status', 'error', 'payload',
        'related_type', 'related_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
