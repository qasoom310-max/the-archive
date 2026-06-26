<?php

declare(strict_types=1);

namespace App\Erp\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Cross-module notification registry. Each module registers a provider (in its
 * service provider's boot) that returns the alerts a given user should see; the
 * top-bar bell aggregates them all. Bound as a singleton so module registrations
 * accumulate into one instance per request.
 *
 * A module contributes like:
 *   app(NotificationCenter::class)->register(
 *       fn (Authenticatable $u) => app(MyNotifications::class)->for($u)
 *   );
 */
final class NotificationCenter
{
    /** @var list<callable(Authenticatable): iterable<NotificationItem>> */
    private array $providers = [];

    /**
     * @param  callable(Authenticatable): iterable<NotificationItem>  $provider
     */
    public function register(callable $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Every alert for the user, most urgent first. A provider that throws is
     * skipped — one broken module must never break the whole bell.
     *
     * @return list<NotificationItem>
     */
    public function forUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $items = [];
        foreach ($this->providers as $provider) {
            try {
                foreach ($provider($user) as $item) {
                    if ($item instanceof NotificationItem) {
                        $items[] = $item;
                    }
                }
            } catch (Throwable) {
                // Resilience: a failing provider is ignored, not fatal.
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, static fn (NotificationItem $a, NotificationItem $b): int => ($rank[$a->level] ?? 2) <=> ($rank[$b->level] ?? 2));

        return $items;
    }
}
