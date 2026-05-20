<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Erp\Chatter\ActivityBucket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $messageable_type
 * @property int $messageable_id
 * @property int $mail_activity_type_id
 * @property string $summary
 * @property string|null $note
 * @property Carbon $due_date
 * @property int|null $user_id
 * @property string|null $user_name
 * @property bool $done
 * @property Carbon|null $done_at
 * @property string|null $done_by_name
 * @property-read MailActivityType|null $type
 */
final class MailActivity extends Model
{
    protected $table = 'mail_activities';

    /** @var list<string> */
    protected $fillable = [
        'mail_activity_type_id',
        'summary',
        'note',
        'due_date',
        'user_id',
        'user_name',
        'done',
        'done_at',
        'done_by_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function messageable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<MailActivityType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(MailActivityType::class, 'mail_activity_type_id');
    }

    /**
     * Which Odoo activity bucket this activity currently belongs to.
     */
    public function bucket(): ActivityBucket
    {
        if ($this->done) {
            return ActivityBucket::Done;
        }

        $today = Carbon::today();
        $due = $this->due_date->copy()->startOfDay();

        return match (true) {
            $due->lt($today) => ActivityBucket::Overdue,
            $due->isSameDay($today) => ActivityBucket::Today,
            $due->isSameDay($today->copy()->addDay()) => ActivityBucket::Tomorrow,
            default => ActivityBucket::Planned,
        };
    }

    /**
     * @param  Builder<MailActivity>  $query
     * @return Builder<MailActivity>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('done', false);
    }

    /**
     * @param  Builder<MailActivity>  $query
     * @return Builder<MailActivity>
     */
    public function scopeDone(Builder $query): Builder
    {
        return $query->where('done', true);
    }

    public function markDone(?string $by = null): void
    {
        $this->update([
            'done' => true,
            'done_at' => Carbon::now(),
            'done_by_name' => $by,
        ]);
    }
}
