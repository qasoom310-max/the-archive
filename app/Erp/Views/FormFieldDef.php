<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * One field of a Form view, parsed from `arch.fields[]`.
 */
final readonly class FormFieldDef
{
    /**
     * @param 'text'|'textarea'|'email'|'tel'|'number'|'checkbox'|'select'|'date'|'datetime'|'image' $widget
     * @param list<array{value: string, label: string}> $options
     */
    public function __construct(
        public string $field,
        public string $label,
        public string $widget = 'text',
        public bool $required = false,
        public ?string $placeholder = null,
        public array $options = [],
        public ?string $help = null,
        public ?DynamicOptions $optionsSource = null,
    ) {}

    public function isImage(): bool
    {
        return $this->widget === 'image';
    }
}
