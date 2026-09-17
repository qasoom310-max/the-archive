<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every message in and out of an assistant conversation. `wa_message_id` is
 * unique, which is what makes a redelivered Meta webhook a no-op.
 *
 * @property int $id
 * @property int|null $conversation_id
 * @property string $direction
 * @property string|null $wa_message_id
 * @property string $type
 * @property string|null $body
 */
final class ConversationMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const UPDATED_AT = null;

    protected $table = 'whatsapp_messages';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'direction', 'wa_message_id', 'type', 'body'];
}
