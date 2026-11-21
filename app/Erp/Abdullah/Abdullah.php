<?php

declare(strict_types=1);

namespace App\Erp\Abdullah;

use App\Erp\Settings\Setting;
use App\Models\AbdullahAlert;
use App\Models\AbdullahMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Throwable;

/**
 * Abdullah, the staff assistant: who he talks to, in which language, and how
 * his words get written and delivered.
 *
 * The ERP's own code decides WHAT to say (a trip with no driver, a price far
 * from the usual, a place name one letter off a saved one). Abdullah only
 * phrases it, so he never accuses anyone of something that is not there.
 * Box messages are written when their reader is on screen; emails are written
 * and sent at once.
 *
 * Claude writes when a key is saved (the WhatsApp assistant's) and the month's
 * limit is not reached; otherwise a plain fixed sentence is used, so nothing
 * stops working when the money runs out.
 */
final class Abdullah
{
    /** @var array<string, string> code => the language's own name */
    public const LANGUAGES = ['ar' => 'العربية', 'en' => 'English', 'ml' => 'മലയാളം', 'tl' => 'Tagalog'];

    public const DEFAULT_LANGUAGE = 'ar';

    public const DEFAULT_MONTHLY_LIMIT = 3000;

    public const DEFAULT_OPS_EMAIL = 'operation@wanaan-bh.com';

    /** The owner's choice: every 15 minutes, at most 12 times, then the owner is told. */
    public const DEFAULT_TRIP_EVERY_MINUTES = 15;

    public const DEFAULT_TRIP_MAX = 12;

    public function __construct(private readonly TemplateVoice $templates) {}

    public static function ready(): bool
    {
        return Schema::hasTable('abdullah_messages') && Schema::hasTable('abdullah_alerts');
    }

    public function enabled(): bool
    {
        return self::ready() && (bool) Setting::get('abdullah.enabled', true);
    }

    public function monthlyLimit(): int
    {
        return max(0, (int) Setting::get('abdullah.monthly_limit', self::DEFAULT_MONTHLY_LIMIT));
    }

    public function opsEmail(): string
    {
        $email = trim((string) Setting::get('abdullah.ops_email', self::DEFAULT_OPS_EMAIL));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    public function tripEveryMinutes(): int
    {
        return max(5, (int) Setting::get('abdullah.trip_every_minutes', self::DEFAULT_TRIP_EVERY_MINUTES));
    }

    public function tripMax(): int
    {
        return max(1, (int) Setting::get('abdullah.trip_max', self::DEFAULT_TRIP_MAX));
    }

    public function languageFor(?User $user): string
    {
        $code = (string) ($user?->getAttribute('abdullah_language') ?? '');

        return array_key_exists($code, self::LANGUAGES) ? $code : self::DEFAULT_LANGUAGE;
    }

    /** Whether Abdullah talks to this person at all. */
    public function isOnFor(?User $user): bool
    {
        return $user instanceof User
            && $this->enabled()
            && ! $user->isPaused()
            && ($user->getAttribute('abdullah_off') ?? false) !== true;
    }

    /**
     * Queue a message for someone's box. Written when they are next on screen.
     *
     * @param  array<string, mixed>  $facts
     */
    public function tell(?User $user, string $kind, array $facts, ?string $alertKey = null): ?AbdullahMessage
    {
        if (! $user instanceof User || ! $this->isOnFor($user)) {
            return null;
        }

        return AbdullahMessage::query()->create([
            'user_id' => $user->id,
            'role' => 'abdullah',
            'kind' => $kind,
            'channel' => 'box',
            'alert_key' => $alertKey,
            'language' => $this->languageFor($user),
            'facts' => $facts,
        ]);
    }

    /** Write a box message that has no words yet. */
    public function writePending(AbdullahMessage $message): void
    {
        if ($message->body !== null) {
            return;
        }

        [$text, $byAi] = $this->speak($message->language, $message->kind, $message->facts ?? [], (string) ($message->user->name ?? ''));
        $message->forceFill(['body' => $text, 'by_ai' => $byAi])->save();
    }

    /**
     * A person writes to Abdullah; he answers. Both lines are kept.
     */
    public function reply(User $user, string $text): AbdullahMessage
    {
        $text = mb_substr(trim($text), 0, 1000);

        $history = AbdullahMessage::query()
            ->where('user_id', $user->id)
            ->where('channel', 'box')
            ->whereNotNull('body')
            ->latest('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->map(static fn (AbdullahMessage $m): array => [
                'who' => $m->role === 'user' ? (string) $user->name : 'Abdullah',
                'text' => (string) $m->body,
            ])
            ->values()
            ->all();

        AbdullahMessage::query()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'kind' => 'chat',
            'channel' => 'box',
            'language' => $this->languageFor($user),
            'body' => $text,
            'read_at' => Carbon::now(),
        ]);

        [$answer, $byAi] = $this->speak($this->languageFor($user), 'chat', [
            'today' => Carbon::now()->toDateString(),
            'open_issues' => AbdullahAlert::query()->where('user_id', $user->id)->whereNull('resolved_at')->whereNull('acknowledged_at')->count(),
        ], (string) $user->name, $history, $text);

        return AbdullahMessage::query()->create([
            'user_id' => $user->id,
            'role' => 'abdullah',
            'kind' => 'chat',
            'channel' => 'box',
            'language' => $this->languageFor($user),
            'body' => $answer,
            'by_ai' => $byAi,
            'read_at' => Carbon::now(),
        ]);
    }

    /**
     * Write and send an email now. The figures the email lists are put in the
     * mail as a table by the ERP, beside Abdullah's words, so they are exact.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array{reference: string, time: string, missing: string, url: string}>  $rows
     */
    public function email(string $to, string $kind, array $facts, string $subject, string $language = self::DEFAULT_LANGUAGE, ?int $userId = null, ?string $alertKey = null, array $rows = [], string $name = ''): ?AbdullahMessage
    {
        if ($to === '' || ! $this->enabled()) {
            return null;
        }

        [$text, $byAi] = $this->speak($language, $kind, $facts, $name !== '' ? $name : 'the team');

        $message = AbdullahMessage::query()->create([
            'user_id' => $userId,
            'role' => 'abdullah',
            'kind' => $kind,
            'channel' => 'email',
            'email_to' => $to,
            'alert_key' => $alertKey,
            'language' => $language,
            'facts' => $facts,
            'body' => $text,
            'by_ai' => $byAi,
        ]);

        try {
            Mail::to($to)->send(new AbdullahMail($subject, $text, $rows, $language));
        } catch (Throwable $e) {
            Log::warning('Abdullah email failed', ['to' => $to, 'error' => $e->getMessage()]);
        }

        return $message;
    }

    /**
     * The chase record for a key, made the first time it is asked for.
     */
    public function alert(string $key, string $kind, ?int $userId = null): AbdullahAlert
    {
        return AbdullahAlert::query()->firstOrCreate(['key' => $key], ['kind' => $kind, 'user_id' => $userId]);
    }

    /**
     * Say something once per key: a finding already said and not fixed since
     * is not said again. A fixed finding that comes back is said again.
     *
     * @param  array<string, mixed>  $facts
     */
    public function once(?User $user, string $key, string $kind, array $facts): bool
    {
        if (! $user instanceof User || ! $this->isOnFor($user)) {
            return false;
        }

        $alert = $this->alert($key, $kind, (int) $user->id);
        if ($alert->sent_count > 0 && $alert->resolved_at === null) {
            return false;
        }

        $this->tell($user, $kind, $facts, $key);
        $alert->forceFill([
            'user_id' => $user->id,
            'sent_count' => $alert->sent_count + 1,
            'last_sent_at' => Carbon::now(),
            'resolved_at' => null,
            'acknowledged_at' => null,
        ])->save();

        return true;
    }

    /** A finding that is no longer true: closed, so it may be said again if it comes back. */
    public function resolve(string $key): void
    {
        if (! self::ready()) {
            return;
        }

        AbdullahAlert::query()->where('key', $key)->whereNull('resolved_at')->update(['resolved_at' => Carbon::now()]);
    }

    /** How many messages Claude has written this month. */
    public function usedThisMonth(): int
    {
        return AbdullahMessage::query()
            ->where('by_ai', true)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();
    }

    public function apiKey(): string
    {
        if (! class_exists(AssistantConfiguration::class) || ! Schema::hasTable('whatsapp_assistant_configuration')) {
            return '';
        }

        return trim((string) AssistantConfiguration::current()->ai_api_key);
    }

    public function aiAvailable(): bool
    {
        return $this->apiKey() !== '' && $this->usedThisMonth() < $this->monthlyLimit();
    }

    /**
     * Abdullah's words for an event: Claude when it may be used, the plain
     * sentence when not, or when Claude fails.
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array{who: string, text: string}>  $history
     * @return array{0: string, 1: bool} the text, and whether Claude wrote it
     */
    public function speak(string $language, string $kind, array $facts, string $userName, array $history = [], ?string $userText = null): array
    {
        if ($this->aiAvailable()) {
            try {
                $model = AssistantConfiguration::current()->model();
                $text = app(Voice::class)->write($this->apiKey(), $model, $language, $kind, $facts, $userName, $history, $userText);

                return [mb_substr($text, 0, 2000), true];
            } catch (Throwable $e) {
                Log::warning('Abdullah could not use Claude', ['kind' => $kind, 'error' => $e->getMessage()]);
            }
        }

        return [$this->templates->write($language, $kind, $facts, $userName), false];
    }
}
