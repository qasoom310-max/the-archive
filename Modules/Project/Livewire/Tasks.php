<?php

declare(strict_types=1);

namespace Modules\Project\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Project\Models\ProjectTask;

/**
 * Tasks browser — renders the engine List view for the `project.task`
 * model (backs the sidebar entry). Rows open the engine task form.
 */
#[Layout('components.layouts.app')]
#[Title('Tasks')]
final class Tasks extends Component
{
    public function render(): View
    {
        return view('project::engine-list', [
            'model' => ProjectTask::class,
            'modelKey' => 'project.task',
        ]);
    }
}
