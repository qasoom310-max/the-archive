<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something Abdullah is chasing, by a stable key ("trip:leg:12",
 * "check:rate:leg:12"), so he says it once, or repeats it on a timer up to a
 * limit, and stops when it is fixed.
 *
 * @property int $id
 * @property string $key
 * @property string $kind
 * @property int|null $user_id
 * @property int $sent_count
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $escalated_at
 * @property Carbon|null $resolved_at
 */
final class AbdullahAlert extends Model
{
    protected $table = 'abdullah_alerts';

    /** @var list<string> */
    protected $fillable = ['key', 'kind', 'user_id', 'sent_count', 'last_sent_at', 'acknowledged_at', 'acknowledged_by', 'escalated_at', 'resolved_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['sent_count' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'sent_count' => 'integer',
            'acknowledged_by' => 'integer',
            'last_sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null && $this->acknowledged_at === null;
    }
}
