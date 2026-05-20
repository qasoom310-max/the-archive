<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * Card layout for a Kanban view, parsed from `arch.card`.
 */
final readonly class KanbanCard
{
    /**
     * @param list<string> $badges
     */
    public function __construct(
        public string $title,
        public ?string $subtitle = null,
        public array $badges = [],
    ) {}
}
