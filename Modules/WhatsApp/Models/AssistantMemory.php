<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing a staff member asked the assistant to remember.
 *
 * Kept per ERP user, so the same notes follow them from WhatsApp to the ERP
 * test chat, and one person's notes never steer another's conversation. The
 * notes are context only: a price in a note is never a fare (those come from
 * the ERP fare tables), and using one as a custom price still goes through the
 * same admin-only override check.
 *
 * @property int $id
 * @property int $user_id
 * @property string $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AssistantMemory extends Model
{
    /** Notes kept per person — enough for a working reference, bounded so every message stays small. */
    public const MAX_PER_USER = 50;

    /** Longest single note, in characters. */
    public const MAX_LENGTH = 1500;

    protected $table = 'whatsapp_assistant_memories';

    /** @var list<string> */
    protected $fillable = ['user_id', 'text'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer'];
    }

    /**
     * @return Collection<int, self>
     */
    public static function forUser(int $userId): Collection
    {
        return self::query()->where('user_id', $userId)->orderBy('id')->get();
    }

    /**
     * The notes as the block the assistant reads, one per line with its id so
     * it can forget a specific one. Empty when there are none.
     */
    public static function promptBlock(int $userId): string
    {
        return self::forUser($userId)
            ->map(fn (self $m): string => '[' . $m->id . '] ' . str_replace(["\r\n", "\r"], "\n", $m->text))
            ->implode("\n");
    }
}
