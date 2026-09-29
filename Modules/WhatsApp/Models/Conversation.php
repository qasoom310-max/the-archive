<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One staff member's running chat with the assistant.
 *
 * `draft` is the action waiting for an explicit YES — the only way anything
 * gets created. `history` keeps the recent text turns so the AI can follow a
 * conversation across messages.
 *
 * @property int $id
 * @property string $wa_id
 * @property int|null $user_id
 * @property string $language
 * @property string|null $state
 * @property array<string, mixed>|null $draft
 * @property list<array{role: string, text: string}>|null $history
 * @property Carbon|null $last_msg_at
 */
final class Conversation extends Model
{
    public const STATE_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /**
     * Turns kept for context; older ones fall away. Anything meant to outlast
     * this window goes into the staff member's saved notes (AssistantMemory).
     */
    public const HISTORY_LIMIT = 60;

    /** A proposal nobody answers within this long is forgotten. */
    public const DRAFT_TTL_MINUTES = 30;

    protected $table = 'whatsapp_conversations';

    /** @var list<string> */
    protected $fillable = ['wa_id', 'user_id', 'language', 'state', 'draft', 'history', 'last_msg_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'draft' => 'array',
            'history' => 'array',
            'last_msg_at' => 'datetime',
        ];
    }

    public function remember(string $role, string $text): void
    {
        $history = $this->history ?? [];
        $history[] = ['role' => $role, 'text' => $text];
        $this->history = array_values(array_slice($history, -self::HISTORY_LIMIT));
    }

    /**
     * The action awaiting YES, if it hasn't gone stale.
     *
     * @return array<string, mixed>|null
     */
    public function pendingAction(): ?array
    {
        if ($this->state !== self::STATE_AWAITING_CONFIRMATION || ! is_array($this->draft)) {
            return null;
        }

        $at = isset($this->draft['proposed_at']) && is_string($this->draft['proposed_at'])
            ? Carbon::parse($this->draft['proposed_at'])
            : null;

        if ($at === null || $at->lt(Carbon::now()->subMinutes(self::DRAFT_TTL_MINUTES))) {
            return null;
        }

        return $this->draft;
    }

    /**
     * @param array<string, mixed> $action
     */
    public function propose(array $action): void
    {
        $this->state = self::STATE_AWAITING_CONFIRMATION;
        $this->draft = $action + ['proposed_at' => Carbon::now()->toIso8601String()];
    }

    public function clearPending(): void
    {
        $this->state = null;
        $this->draft = null;
    }
}
