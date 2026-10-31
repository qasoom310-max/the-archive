<?php

declare(strict_types=1);

namespace App\Erp\Push;

use App\Erp\Tenancy\WorkspaceManager;
use App\Jobs\SendExpoPush;
use App\Models\AppDevice;
use App\Models\User;

/**
 * Phone notifications for the staff app, through Expo's push service (which
 * delivers to both Android and iPhone; free, no Meta). Queued, so a scheduled
 * task or a request never waits on the network; the job remembers which
 * database it came from, to forget a phone that has uninstalled the app.
 */
final class ExpoPush
{
    public function __construct(private readonly WorkspaceManager $workspaces)
    {
    }

    /**
     * Notify these accounts (of THIS database) on every phone they're signed in
     * on. Returns how many phones were addressed.
     *
     * @param  list<int>  $userIds
     * @param  array<string, scalar|null>  $data  passed to the app (e.g. which trip to open)
     */
    public function toUsers(array $userIds, string $title, string $body, array $data = []): int
    {
        $tokens = AppDevice::tokensFor($userIds);
        if ($tokens === []) {
            return 0;
        }

        $messages = array_map(static fn (string $token): array => [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'sound' => 'default',
            'priority' => 'high',
            // The Android channel the app creates for these (loud, on the lock screen).
            'channelId' => 'reminders',
        ], $tokens);

        SendExpoPush::dispatch($messages, $this->workspaces->activeWorkspaceId());

        return count($tokens);
    }

    /**
     * The same, one message per language: each person reads it in the language
     * they chose for the ERP (`users.language`, else the company's).
     *
     * @param  iterable<User>  $users
     * @param  callable(): array{0: string, 1: string}  $compose  title + body, written in the locale it is called in
     * @param  array<string, scalar|null>  $data
     */
    public function toUsersInTheirLanguage(iterable $users, callable $compose, array $data = []): int
    {
        $byLocale = [];
        foreach ($users as $user) {
            $locale = in_array($user->language, ['en', 'ar'], true) ? (string) $user->language : (string) config('app.locale', 'en');
            $byLocale[$locale][] = (int) $user->id;
        }

        $sent = 0;
        $previous = app()->getLocale();
        try {
            foreach ($byLocale as $locale => $ids) {
                app()->setLocale($locale);
                [$title, $body] = $compose();
                $sent += $this->toUsers($ids, $title, $body, $data);
            }
        } finally {
            app()->setLocale($previous);
        }

        return $sent;
    }
}
