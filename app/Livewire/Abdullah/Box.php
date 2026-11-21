<?php

declare(strict_types=1);

namespace App\Livewire\Abdullah;

use App\Erp\Abdullah\Abdullah;
use App\Models\AbdullahAlert;
use App\Models\AbdullahMessage;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Abdullah's box, on every screen: he pops up when he has something to say,
 * and the person can answer him.
 *
 * Every 30 seconds it writes any message waiting for this person (at most two
 * a time, so a slow answer never holds the page) and opens itself when one is
 * new. Only this person's own messages are ever loaded.
 */
final class Box extends Component
{
    public bool $open = false;

    public string $text = '';

    /** The newest message id this box has already shown, so it pops only for new ones. */
    public int $seenId = 0;

    private function me(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function mount(Abdullah $abdullah): void
    {
        $user = $this->me();
        if ($user === null || ! $abdullah->isOnFor($user)) {
            return;
        }

        $this->seenId = (int) AbdullahMessage::query()->where('user_id', $user->id)->where('channel', 'box')->whereNotNull('read_at')->max('id');
    }

    /** The 30-second beat: write what is waiting, pop up for anything new. */
    public function tick(Abdullah $abdullah): void
    {
        $user = $this->me();
        if ($user === null || ! $abdullah->isOnFor($user)) {
            return;
        }

        AbdullahMessage::query()
            ->where('user_id', $user->id)
            ->where('channel', 'box')
            ->whereNull('body')
            ->orderBy('id')
            ->limit(2)
            ->get()
            ->each(static fn (AbdullahMessage $m) => $abdullah->writePending($m));

        $newest = (int) AbdullahMessage::query()
            ->where('user_id', $user->id)
            ->where('channel', 'box')
            ->where('role', 'abdullah')
            ->whereNotNull('body')
            ->whereNull('read_at')
            ->max('id');

        if ($newest > $this->seenId) {
            $this->seenId = $newest;
            $this->openBox();
        }
    }

    public function openBox(): void
    {
        $this->open = true;
        $user = $this->me();
        if ($user !== null) {
            AbdullahMessage::query()->where('user_id', $user->id)->whereNotNull('body')->whereNull('read_at')->update(['read_at' => Carbon::now()]);
        }
    }

    public function closeBox(): void
    {
        $this->open = false;
    }

    public function send(Abdullah $abdullah): void
    {
        $user = $this->me();
        if ($user === null || ! $abdullah->isOnFor($user)) {
            return;
        }

        $this->validate(['text' => ['required', 'string', 'max:1000']]);

        $answer = $abdullah->reply($user, $this->text);
        $this->seenId = max($this->seenId, (int) $answer->id);
        $this->text = '';
    }

    public function setLanguage(string $code): void
    {
        $user = $this->me();
        if ($user === null || ! array_key_exists($code, Abdullah::LANGUAGES)) {
            return;
        }

        $user->forceFill(['abdullah_language' => $code])->save();
    }

    /**
     * "I'm handling it" on a trip reminder: the emails for that trip stop,
     * and who said so is kept for the owner to see.
     */
    public function handling(int $messageId, Abdullah $abdullah): void
    {
        $user = $this->me();
        if ($user === null) {
            return;
        }

        $message = AbdullahMessage::query()->where('user_id', $user->id)->find($messageId);
        $keys = is_array($message?->facts['keys'] ?? null) ? array_values(array_filter($message->facts['keys'], 'is_string')) : [];
        if ($message === null || $message->kind !== 'trip' || $keys === []) {
            return;
        }

        AbdullahAlert::query()->whereIn('key', $keys)->whereNull('resolved_at')->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => Carbon::now(), 'acknowledged_by' => $user->id]);

        AbdullahMessage::query()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'kind' => 'trip',
            'channel' => 'box',
            'language' => $abdullah->languageFor($user),
            'body' => (string) __("I'm handling it."),
            'read_at' => Carbon::now(),
        ]);
    }

    public function render(Abdullah $abdullah): View
    {
        $user = $this->me();
        $on = $user !== null && $abdullah->isOnFor($user);

        $messages = $on
            ? AbdullahMessage::query()->where('user_id', $user->id)->where('channel', 'box')->whereNotNull('body')
                ->latest('id')->limit(30)->get()->reverse()->values()
            : collect();

        // Trip reminders still open, so their "I'm handling it" button shows.
        $openKeys = $on
            ? AbdullahAlert::query()->where('kind', 'trip')->whereNull('resolved_at')->whereNull('acknowledged_at')->pluck('key')->all()
            : [];

        return view('livewire.abdullah.box', [
            'on' => $on,
            'messages' => $messages,
            'openKeys' => $openKeys,
            'unread' => $on ? AbdullahMessage::query()->where('user_id', $user->id)->where('role', 'abdullah')->whereNotNull('body')->whereNull('read_at')->count() : 0,
            'language' => $abdullah->languageFor($user),
            'languages' => Abdullah::LANGUAGES,
        ]);
    }
}
