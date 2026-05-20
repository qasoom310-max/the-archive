<?php

declare(strict_types=1);

namespace App\Erp\Registry;

/**
 * Immutable description of a stored view, used to populate `ir_ui_view`.
 * `arch` is the structural layout consumed by the dynamic view engine (Phase 4).
 */
final readonly class ViewDefinition
{
    /**
     * @param 'list'|'kanban'|'form'|'search' $type
     * @param array<string, mixed> $arch
     * @param 'primary'|'extension' $mode
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $arch,
        public int $priority = 16,
        public string $mode = 'primary',
        public bool $active = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'arch' => $this->arch,
            'priority' => $this->priority,
            'mode' => $this->mode,
            'active' => $this->active,
        ];
    }
}
