<?php

declare(strict_types=1);

namespace App\Livewire\Views;

use App\Erp\Chatter\Chatterable;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Views\ValueFormat;
use App\Erp\Views\ViewArch;
use App\Erp\Views\ViewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Abstract, metadata-driven Kanban board: records grouped by a state
 * column, drag-and-drop between columns performs the state transition,
 * and untouched cards show the Odoo-19 "rotting" aging cue.
 *
 * @property-read ViewArch $arch
 */
final class KanbanView extends Component
{
    /** @var class-string<Model> */
    public string $model;

    public string $modelKey = '';

    public string $title = '';

    public int $limit = 200;

    /**
     * @param  class-string<Model>  $model
     */
    public function mount(string $model, string $modelKey = '', string $title = ''): void
    {
        $this->model = $model;
        $this->modelKey = $modelKey;
        $this->title = $title;
    }

    #[Computed]
    public function arch(): ViewArch
    {
        return app(ViewResolver::class)->arch($this->modelKey, 'kanban');
    }

    /**
     * Drag-drop handler: move a card to another column = state transition.
     */
    private function access(): AccessControl
    {
        return app(AccessControl::class);
    }

    private function may(Permission $permission): bool
    {
        return $this->modelKey === ''
            || $this->access()->allows(Auth::user(), $this->modelKey, $permission);
    }

    public function moveCard(int|string $id, string $toStage): void
    {
        if (! $this->may(Permission::Write)) {
            $this->access()->authorize(Auth::user(), $this->modelKey, Permission::Write);
        }

        $groupBy = $this->arch->groupBy;

        if ($groupBy === null) {
            return;
        }

        $record = $this->model::query()->whereKey($id)->first();

        if ($record === null) {
            return;
        }

        $from = ValueFormat::key($record->getAttribute($groupBy));

        if ($from === $toStage) {
            return;
        }

        $record->setAttribute($groupBy, $toStage);
        $record->save();

        if ($record instanceof Chatterable) {
            $record->logChange("Stage: {$from} → {$toStage}");
        }

        $this->dispatch('card-moved', id: $id, to: $toStage);
    }

    public function render(): View
    {
        if (! $this->may(Permission::Read)) {
            return view('livewire.views.forbidden');
        }

        $arch = $this->arch;
        $groupBy = $arch->groupBy;

        $records = $this->model::query()->limit($this->limit)->get();

        // Establish column order: explicit stages first, then any extra
        // values found in the data.
        $columns = [];
        foreach ($arch->stages as $stage) {
            $columns[$stage['value']] = $stage['label'];
        }

        if ($groupBy === null) {
            $columns = ['' => $this->title !== '' ? $this->title : 'All'];
        } else {
            foreach ($records as $record) {
                $value = ValueFormat::key($record->getAttribute($groupBy));
                if (! array_key_exists($value, $columns)) {
                    $columns[$value] = $value === '' ? 'Undefined' : $value;
                }
            }
        }

        /** @var array<string, list<Model>> $grouped */
        $grouped = array_fill_keys(array_keys($columns), []);

        foreach ($records as $record) {
            $value = $groupBy === null ? '' : ValueFormat::key($record->getAttribute($groupBy));
            $grouped[$value][] = $record;
        }

        return view('livewire.views.kanban-view', [
            'columns' => $columns,
            'grouped' => $grouped,
            'card' => $arch->card,
            'rotting' => $arch->rotting,
            'groupBy' => $groupBy,
            'openUrl' => $arch->openUrl,
        ]);
    }
}
