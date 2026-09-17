<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payment link raised from a chat, so the staff member who raised it is
 * told when the customer pays.
 *
 * @property int $id
 * @property int $payment_link_id
 * @property int $conversation_id
 * @property Carbon|null $notified_at
 */
final class AssistantPaymentLink extends Model
{
    protected $table = 'whatsapp_assistant_payment_links';

    /** @var list<string> */
    protected $fillable = ['payment_link_id', 'conversation_id', 'notified_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payment_link_id' => 'integer', 'conversation_id' => 'integer', 'notified_at' => 'datetime'];
    }
}
