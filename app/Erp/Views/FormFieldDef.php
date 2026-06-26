<?php

declare(strict_types=1);

namespace App\Erp\Views;

/**
 * One field of a Form view, parsed from `arch.fields[]`.
 */
final readonly class FormFieldDef
{
    /**
     * @param 'text'|'textarea'|'email'|'tel'|'number'|'checkbox'|'select'|'date'|'datetime'|'image'|'file'|'color' $widget
     * @param list<array{value: string, label: string}> $options
     * @param bool $translatable  When true the field renders with Odoo-style
     *                            language pills (EN / AR) and saves via
     *                            Spatie's `setTranslations()`. Only text /
     *                            textarea widgets honour this — other widgets
     *                            ignore the flag (a translatable number makes
     *                            no sense).
     * @param bool $unique        When true the value must be unique across the
     *                            model's table — saving a duplicate is blocked
     *                            with an inline "already exists" error. Honoured
     *                            for translatable fields too (compared across
     *                            every locale envelope), so two ingredients can't
     *                            both be named "Qahwa". {@see FormView::rules()}.
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
        public bool $translatable = false,
        public bool $unique = false,
        /**
         * Restricts a `file` widget's accepted upload type. 'pdf' = PDF only
         * (rejects images too); null = the controller's default (PDF + images).
         */
        public ?string $accept = null,
    ) {}

    public function isImage(): bool
    {
        return $this->widget === 'image';
    }

    /**
     * A document upload (PDF/image) handled by the direct
     * FormFileUploadController — its value lives in FormView's `$filePaths`
     * buffer, not in `$form`, exactly like {@see isImage()}.
     */
    public function isFile(): bool
    {
        return $this->widget === 'file';
    }

    /**
     * Only text-shaped widgets carry per-locale strings; everything else
     * (numbers, checkboxes, selects, dates, images) is locale-independent
     * and silently drops the `translatable` flag if a careless arch sets
     * it. Keeps the FormView render simpler — it can ask one method.
     */
    public function isTranslatable(): bool
    {
        return $this->translatable && in_array($this->widget, ['text', 'textarea'], true);
    }
}
