<?php

declare(strict_types=1);

namespace App\Erp\Registry;

/**
 * Immutable description of a system model, used to populate `ir_model`
 * (plus its `ir_model_fields` and `ir_ui_view` children).
 */
final readonly class ModelDefinition
{
    /**
     * @param class-string         $class  Eloquent FQCN
     * @param list<FieldDefinition> $fields
     * @param list<ViewDefinition>  $views
     */
    public function __construct(
        public string $model,
        public string $name,
        public string $class,
        public string $table,
        public array $fields = [],
        public array $views = [],
        public ?string $module = null,
        public bool $isCustom = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'model' => $this->model,
            'name' => $this->name,
            'class' => $this->class,
            'table' => $this->table,
            'module' => $this->module,
            'is_custom' => $this->isCustom,
        ];
    }
}
