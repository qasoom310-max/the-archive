<?php

declare(strict_types=1);

namespace App\Erp\Views;

use App\Models\Ir\IrModel;
use App\Models\Ir\IrModelField;
use App\Models\Ir\IrUiView;

/**
 * Resolves the effective view for a model: the highest-priority active
 * `ir_ui_view`, or — like Odoo — an auto-generated default derived from
 * the model's registered `ir_model_fields`.
 */
final class ViewResolver
{
    public function view(string $modelKey, string $type): ?IrUiView
    {
        return IrUiView::query()
            ->where('model', $modelKey)
            ->where('type', $type)
            ->where('active', true)
            ->orderBy('priority')
            ->first();
    }

    public function arch(string $modelKey, string $type): ViewArch
    {
        $view = $this->view($modelKey, $type);

        if ($view !== null) {
            return ViewArch::fromArray($view->arch);
        }

        return ViewArch::fromArray($this->defaultArch($modelKey, $type));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultArch(string $modelKey, string $type): array
    {
        $model = IrModel::query()->where('model', $modelKey)->first();

        /** @var list<IrModelField> $fields */
        $fields = $model !== null
            ? $model->fields()->orderBy('sequence')->orderBy('id')->get()->all()
            : [];

        return match ($type) {
            'kanban' => $this->defaultKanbanArch($fields),
            'form' => $this->defaultFormArch($fields),
            default => $this->defaultListArch($fields),
        };
    }

    /**
     * @param  list<IrModelField>  $fields
     * @return array<string, mixed>
     */
    private function defaultFormArch(array $fields): array
    {
        $widgets = [
            'char' => 'text', 'text' => 'textarea', 'integer' => 'number',
            'float' => 'number', 'boolean' => 'checkbox', 'date' => 'date',
            'datetime' => 'datetime', 'selection' => 'select', 'binary' => 'image',
        ];

        $formFields = [];

        foreach ($fields as $field) {
            if (in_array($field->ttype, ['one2many', 'many2many', 'many2one'], true)) {
                continue;
            }

            $entry = [
                'field' => $field->name,
                'label' => $field->label,
                'widget' => $widgets[$field->ttype] ?? 'text',
                'required' => $field->required,
            ];

            if ($field->ttype === 'selection' && $field->selection !== null) {
                $entry['options'] = $field->selection;
            }

            $formFields[] = $entry;
        }

        return ['fields' => $formFields, 'cols' => 2];
    }

    /**
     * @param  list<IrModelField>  $fields
     * @return array<string, mixed>
     */
    private function defaultListArch(array $fields): array
    {
        $columns = [];

        foreach ($fields as $field) {
            if (in_array($field->ttype, ['binary', 'one2many', 'many2many'], true)) {
                continue;
            }

            $numeric = in_array($field->ttype, ['integer', 'float'], true);

            $columns[] = [
                'field' => $field->name,
                'label' => $field->label,
                'sortable' => true,
                'sum' => $numeric,
                'align' => $numeric ? 'right' : 'left',
                'format' => $this->formatFor($field->ttype),
            ];
        }

        return ['columns' => $columns, 'per_page' => 20];
    }

    /**
     * @param  list<IrModelField>  $fields
     * @return array<string, mixed>
     */
    private function defaultKanbanArch(array $fields): array
    {
        $names = array_map(static fn (IrModelField $f): string => $f->name, $fields);

        $groupBy = null;
        foreach (['stage', 'state', 'status', 'kanban_state'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                $groupBy = $candidate;
                break;
            }
        }

        if ($groupBy === null) {
            foreach ($fields as $field) {
                if ($field->ttype === 'selection') {
                    $groupBy = $field->name;
                    break;
                }
            }
        }

        $textFields = array_values(array_filter(
            $fields,
            static fn (IrModelField $f): bool => in_array($f->ttype, ['char', 'text'], true),
        ));

        $title = $textFields[0]->name ?? ($names[0] ?? 'id');
        $subtitle = $textFields[1]->name ?? null;

        return [
            'group_by' => $groupBy,
            'card' => array_filter([
                'title' => $title,
                'subtitle' => $subtitle,
            ], static fn (mixed $v): bool => $v !== null),
            'rotting' => ['field' => 'updated_at', 'days' => 7],
        ];
    }

    private function formatFor(string $ttype): string
    {
        return match ($ttype) {
            'integer', 'float' => 'number',
            'date' => 'date',
            'datetime' => 'datetime',
            'boolean' => 'bool',
            'selection' => 'badge',
            default => 'text',
        };
    }
}
