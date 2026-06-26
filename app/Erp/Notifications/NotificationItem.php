<?php

declare(strict_types=1);

namespace App\Erp\Notifications;

/**
 * One actionable / informational alert shown in the top-bar notification bell.
 * Derived live from current state (no inbox table) — when the underlying thing
 * is resolved (approved, refunded, renewed) the item simply stops appearing.
 */
final readonly class NotificationItem
{
    /**
     * @param  'critical'|'warning'|'info'  $level  Drives the dot colour + sort order.
     */
    public function __construct(
        public string $title,
        public string $description = '',
        public string $url = '#',
        public string $level = 'info',
        public ?string $group = null,
    ) {}
}
