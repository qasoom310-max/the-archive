<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * Card layout for a Kanban view, parsed from `arch.card`.
 *
 * `image` is the model attribute name holding a storage-relative path
 * (e.g. `image_path` on PosProduct → `storage/app/public/...`). When set,
 * the card renders a square photo hero above the title. Null = no hero.
 *
 * `meta` is an ordered list of `{ field, label?, format? }` rows shown
 * at the bottom of the card (label-on-left, value-on-right). Use for the
 * Odoo-style "Price 4.00 BD / Stock 42" footer. `format` strings map onto
 * {@see ValueFormat} — `money` / `number` / null = plain label.
 */
final readonly class KanbanCard
{
    /**
     * @param list<string>                                                $badges
     * @param list<array{field: string, label: ?string, format: ?string}> $meta
     */
    public function __construct(
        public string $title,
        public ?string $subtitle = null,
        public array $badges = [],
        public ?string $image = null,
        public array $meta = [],
    ) {}
}
