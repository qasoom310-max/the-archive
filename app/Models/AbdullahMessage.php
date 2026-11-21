<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing Abdullah said (or a person said to him), in the box or by email.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $role      abdullah | user
 * @property string $kind      chat | booking | check | trip | escalation
 * @property string $channel   box | email
 * @property string|null $email_to
 * @property string|null $alert_key
 * @property string $language  ar | en | ml | tl
 * @property array<string, mixed>|null $facts
 * @property string|null $body  null until written
 * @property bool $by_ai
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
final class AbdullahMessage extends Model
{
    protected $table = 'abdullah_messages';

    /** @var list<string> */
    protected $fillable = ['user_id', 'role', 'kind', 'channel', 'email_to', 'alert_key', 'language', 'facts', 'body', 'by_ai', 'read_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'facts' => 'array',
            'by_ai' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
