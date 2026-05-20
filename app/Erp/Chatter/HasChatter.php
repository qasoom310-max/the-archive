<?php

declare(strict_types=1);

namespace App\Erp\Chatter;

use App\Models\Mail\MailActivity;
use App\Models\Mail\MailActivityType;
use App\Models\Mail\MailMessage;
use App\Models\Mail\MessageType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The `mail.thread` mixin: gives any Eloquent model a Chatter — threaded
 * messages / internal notes / audit log + schedulable activities.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasChatter
{
    /**
     * @return MorphMany<MailMessage, $this>
     */
    public function messages(): MorphMany
    {
        return $this->morphMany(MailMessage::class, 'messageable');
    }

    /**
     * @return MorphMany<MailActivity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(MailActivity::class, 'messageable');
    }

    /** Post a message to the thread ("Send message"). */
    public function postMessage(string $body, ?string $subject = null, ?string $author = null): MailMessage
    {
        return $this->messages()->create([
            'type' => MessageType::Comment,
            'subject' => $subject,
            'body' => $body,
            'author_name' => $author,
        ]);
    }

    /** Add an internal note ("Log note"). */
    public function postNote(string $body, ?string $author = null): MailMessage
    {
        return $this->messages()->create([
            'type' => MessageType::Note,
            'body' => $body,
            'author_name' => $author,
        ]);
    }

    /**
     * Record a system audit-log entry (lifecycle / field changes).
     *
     * @param  array<string, mixed>  $tracking
     */
    public function logChange(string $body, array $tracking = []): MailMessage
    {
        return $this->messages()->create([
            'type' => MessageType::Log,
            'body' => $body,
            'tracking' => $tracking === [] ? null : $tracking,
        ]);
    }

    /** Schedule a future activity on this record. */
    public function scheduleActivity(
        MailActivityType|int $type,
        string $summary,
        Carbon|string $dueDate,
        ?string $note = null,
        ?string $assignee = null,
    ): MailActivity {
        return $this->activities()->create([
            'mail_activity_type_id' => $type instanceof MailActivityType ? $type->id : $type,
            'summary' => $summary,
            'due_date' => $dueDate instanceof Carbon ? $dueDate : Carbon::parse($dueDate),
            'note' => $note,
            'user_name' => $assignee,
        ]);
    }

    /**
     * Open (not-yet-done) activities, soonest due first.
     *
     * @return Collection<int, MailActivity>
     */
    public function openActivities(): Collection
    {
        return $this->activities()
            ->where('done', false)
            ->orderBy('due_date')
            ->get();
    }
}
