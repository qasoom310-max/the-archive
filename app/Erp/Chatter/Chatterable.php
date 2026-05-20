<?php

declare(strict_types=1);

namespace App\Erp\Chatter;

use App\Models\Mail\MailActivity;
use App\Models\Mail\MailActivityType;
use App\Models\Mail\MailMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A record that carries a Chatter. Satisfied by the {@see HasChatter} trait;
 * lets the reusable Chatter component depend on a contract rather than a
 * concrete model.
 */
interface Chatterable
{
    /** @return MorphMany<MailMessage, covariant \Illuminate\Database\Eloquent\Model> */
    public function messages(): MorphMany;

    /** @return MorphMany<MailActivity, covariant \Illuminate\Database\Eloquent\Model> */
    public function activities(): MorphMany;

    public function postMessage(string $body, ?string $subject = null, ?string $author = null): MailMessage;

    public function postNote(string $body, ?string $author = null): MailMessage;

    /** @param array<string, mixed> $tracking */
    public function logChange(string $body, array $tracking = []): MailMessage;

    public function scheduleActivity(
        MailActivityType|int $type,
        string $summary,
        Carbon|string $dueDate,
        ?string $note = null,
        ?string $assignee = null,
    ): MailActivity;

    /** @return Collection<int, MailActivity> */
    public function openActivities(): Collection;
}
