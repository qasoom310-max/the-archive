<?php

declare(strict_types=1);

namespace App\Erp\Registry;

/**
 * Immutable description of a single model attribute / relationship,
 * used to populate `ir_model_fields`.
 */
final readonly class FieldDefinition
{
    /**
     * @param 'char'|'text'|'integer'|'float'|'boolean'|'date'|'datetime'|'binary'|'selection'|'many2one'|'one2many'|'many2many' $ttype
     * @param list<array{value: string, label: string}>|null $selection
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $ttype = 'char',
        public ?string $relation = null,
        public bool $required = false,
        public bool $readonly = false,
        public ?array $selection = null,
        public ?string $help = null,
        public int $sequence = 100,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'ttype' => $this->ttype,
            'relation' => $this->relation,
            'required' => $this->required,
            'readonly' => $this->readonly,
            'selection' => $this->selection,
            'help' => $this->help,
            'sequence' => $this->sequence,
        ];
    }
}
