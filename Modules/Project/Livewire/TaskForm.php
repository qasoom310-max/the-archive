<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\ProjectTask;

/**
 * Create/edit a task via the engine FormView. Relation pickers (project,
 * stage, parent, assignee) are model-sourced selects declared in the task's
 * irModelDefinition() — no bespoke pickers.
 */
#[Layout('components.layouts.app')]
#[Title('Task')]
final class TaskForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $task = $this->id !== null ? ProjectTask::query()->find($this->id) : null;

        return view('project::task-form', ['task' => $task]);
    }
}
