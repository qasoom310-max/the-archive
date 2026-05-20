<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's presence in the single global register session. `last_activity`
 * is refreshed by the terminal heartbeat; "recently seen" → still working.
 *
 * @property int $id
 * @property int $pos_session_id
 * @property int $user_id
 * @property Carbon|null $last_activity
 */
final class PosSessionParticipant extends Model
{
    protected $table = 'pos_session_participants';

    /** @var list<string> */
    protected $fillable = [
        'pos_session_id', 'user_id', 'last_activity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
